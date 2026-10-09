<?php

declare(strict_types=1);

namespace App\Services\Predb\Feeds;

use App\Facades\Search;
use App\Models\Predb;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Upserts feed entries into the predb table by title, mirroring the IRC scraper:
 * new titles are inserted and indexed; existing rows only gain missing details or nuke changes.
 * Rows the database rejects as data are skipped; infrastructure failures propagate.
 */
class PredbFeedImporter
{
    /**
     * Dry runs record what they would have written, so later pages and overlapping feeds in the
     * same run are counted as updates/skips rather than as more inserts.
     *
     * @var array<string, Predb>
     */
    private array $dryRunRows = [];

    /**
     * @param  iterable<PredbFeedEntry>  $entries
     * @return array{inserted: int, updated: int, skipped: int}
     */
    public function import(iterable $entries, bool $dryRun = false): array
    {
        $result = ['inserted' => 0, 'updated' => 0, 'skipped' => 0];

        foreach ($entries as $entry) {
            try {
                $outcome = $this->importEntry($entry, $dryRun);
            } catch (QueryException $e) {
                // Skip rows the database rejects as data (SQLSTATE class 22, e.g. a bad value);
                // connection, schema and other infrastructure failures must fail the run.
                if (! str_starts_with((string) $e->getCode(), '22')) {
                    throw $e;
                }

                Log::warning('PreDB feed entry import failed', ['title' => $entry->title, 'source' => $entry->source, 'error' => $e->getMessage()]);
                $outcome = 'skipped';
            }

            $result[$outcome]++;
        }

        return $result;
    }

    /**
     * @return 'inserted'|'updated'|'skipped'
     */
    private function importEntry(PredbFeedEntry $entry, bool $dryRun): string
    {
        $title = mb_substr(trim($entry->title), 0, 255);

        if ($title === '') {
            return 'skipped';
        }

        $existing = ($dryRun ? ($this->dryRunRows[$this->shadowKey($title)] ?? null) : null) ?? Predb::query()->where('title', $title)->first();

        if ($existing === null) {
            if ($dryRun) {
                $this->dryRunRows[$this->shadowKey($title)] = new Predb([
                    'title' => $title,
                    'source' => $entry->source,
                    'category' => $entry->category,
                    'size' => $entry->size,
                    'files' => $entry->files,
                    'predate' => $entry->predate?->format('Y-m-d H:i:s'),
                    'nuked' => $entry->nuked,
                    'nukereason' => $entry->nukeReason,
                ]);

                return 'inserted';
            }

            try {
                return $this->insert($title, $entry);
            } catch (UniqueConstraintViolationException) {
                // Another ingester (IRC scraper) inserted the title concurrently; fall through to an update.
                $existing = Predb::query()->where('title', $title)->first();

                if ($existing === null) {
                    return 'skipped';
                }
            }
        }

        $changes = $this->changesFor($existing, $entry);

        if ($changes === []) {
            if (! $dryRun && $existing->exists) {
                // Idempotent re-index from the current row (another ingester may have changed the
                // indexed fields), so a document dropped by an earlier failed index write recovers.
                $this->reindex((int) $existing->id, Predb::query()->find($existing->id, ['id', 'title', 'filename', 'source']) ?? $existing);
            }

            return 'skipped';
        }

        if ($dryRun) {
            $shadow = $this->dryRunRows[$this->shadowKey($title)] ?? clone $existing;
            foreach (array_diff_key($changes, ['nuked_from' => true, 'nuked_status' => true, 'nukereason_from' => true]) as $column => $value) {
                $shadow->setAttribute($column, $value);
            }
            $this->dryRunRows[$this->shadowKey($title)] = $shadow;

            return 'updated';
        }

        if (! $this->applyChanges((int) $existing->id, $changes)) {
            return 'skipped';
        }

        // Re-read the indexed fields: another ingester may have changed them since $existing was read.
        $this->reindex((int) $existing->id, Predb::query()->find($existing->id, ['id', 'title', 'filename', 'source']) ?? $existing);

        return 'updated';
    }

    private function reindex(int $id, Predb $row): void
    {
        Search::updatePreDb([
            'id' => $id,
            'title' => $row->title,
            'filename' => $row->filename,
            'source' => $row->source,
        ]);
    }

    /**
     * Shadow key matching predb.title's case- and accent-insensitive collation.
     */
    private function shadowKey(string $title): string
    {
        return mb_strtolower(Str::ascii($title));
    }

    /**
     * Apply fill-only changes atomically: each detail is written only while the column is still
     * empty in the database (another ingester may have filled it since the row was read), and a
     * nuke change only while the status still differs.
     *
     * @param  array<string, mixed>  $changes
     */
    protected function applyChanges(int $id, array $changes): bool
    {
        $changed = false;

        foreach (['category', 'size', 'files'] as $column) {
            if (array_key_exists($column, $changes)) {
                $changed = DB::table('predb')
                    ->where('id', $id)
                    ->where(static fn ($query) => $query->whereNull($column)->orWhere($column, ''))
                    ->update([$column => $changes[$column]]) > 0 || $changed;
            }
        }

        if (array_key_exists('predate', $changes)) {
            $changed = DB::table('predb')->where('id', $id)->whereNull('predate')->update(['predate' => $changes['predate']]) > 0 || $changed;
        }

        if (array_key_exists('nuked', $changes)) {
            // Transition from the status that was read; if another ingester changed it since, keep theirs.
            $changed = DB::table('predb')
                ->where('id', $id)
                ->where('nuked', $changes['nuked_from'] ?? $changes['nuked'])
                ->update(['nuked' => $changes['nuked'], 'nukereason' => $changes['nukereason'] ?? null]) > 0 || $changed;
        } elseif (array_key_exists('nukereason', $changes)) {
            // Same status: fill or correct the reason while the status still matches.
            $observed = $changes['nukereason_from'] ?? null;
            $changed = DB::table('predb')
                ->where('id', $id)
                ->where('nuked', $changes['nuked_status'])
                ->where(static fn ($query) => $observed === null || $observed === ''
                    ? $query->whereNull('nukereason')->orWhere('nukereason', '')
                    : $query->where('nukereason', $observed))
                ->update(['nukereason' => $changes['nukereason']]) > 0 || $changed;
        }

        return $changed;
    }

    private function insert(string $title, PredbFeedEntry $entry): string
    {
        $id = DB::table('predb')->insertGetId([
            'title' => $title,
            'source' => mb_substr($entry->source, 0, 50),
            'category' => $entry->category !== null ? mb_substr($entry->category, 0, 255) : null,
            'size' => $entry->size !== null ? mb_substr($entry->size, 0, 50) : null,
            'files' => $entry->files !== null ? mb_substr($entry->files, 0, 50) : null,
            // Unknown stays null so a later source with the real time can fill it.
            'predate' => $entry->predate?->format('Y-m-d H:i:s'),
            'nuked' => $entry->nuked,
            'nukereason' => $entry->nukeReason !== null ? mb_substr($entry->nukeReason, 0, 255) : null,
            'filename' => '',
        ]);

        Search::insertPredb([
            'id' => (int) $id,
            'title' => $title,
            'filename' => '',
            'source' => $entry->source,
        ]);

        return 'inserted';
    }

    /**
     * Only fill details the row is missing, and record nuke status changes.
     *
     * @return array<string, mixed>
     */
    private function changesFor(Predb $existing, PredbFeedEntry $entry): array
    {
        $changes = [];

        if (empty($existing->category) && $entry->category !== null) {
            $changes['category'] = mb_substr($entry->category, 0, 255);
        }

        if (empty($existing->size) && $entry->size !== null) {
            $changes['size'] = mb_substr($entry->size, 0, 50);
        }

        if (empty($existing->files) && $entry->files !== null) {
            $changes['files'] = mb_substr($entry->files, 0, 50);
        }

        if ($existing->predate === null && $entry->predate !== null) {
            $changes['predate'] = $entry->predate->format('Y-m-d H:i:s');
        }

        $reason = $entry->nukeReason !== null ? mb_substr($entry->nukeReason, 0, 255) : null;

        if ($entry->nuked !== Predb::PRE_NONUKE && (int) $existing->nuked !== $entry->nuked) {
            $changes['nuked'] = $entry->nuked;
            $changes['nuked_from'] = (int) $existing->nuked;
            $changes['nukereason'] = $reason ?? $existing->nukereason;
        } elseif ($entry->nuked !== Predb::PRE_NONUKE && $reason !== null && $reason !== (string) $existing->nukereason) {
            $changes['nukereason'] = $reason;
            $changes['nuked_status'] = $entry->nuked;
            $changes['nukereason_from'] = $existing->nukereason;
        }

        return $changes;
    }
}
