<?php

declare(strict_types=1);

namespace App\Services\Predb\Feeds;

use App\Facades\Search;
use App\Models\Predb;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Upserts feed entries into the predb table by title, mirroring the IRC scraper:
 * new titles are inserted and indexed; existing rows only gain missing details or nuke changes.
 * Rows the database rejects as data are skipped; infrastructure failures propagate.
 */
class PredbFeedImporter
{
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

        $existing = Predb::query()->where('title', $title)->first();

        if ($existing === null) {
            if ($dryRun) {
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
            return 'skipped';
        }

        if ($dryRun) {
            return 'updated';
        }

        if (! $this->applyChanges((int) $existing->id, $changes)) {
            return 'skipped';
        }

        Search::updatePreDb([
            'id' => (int) $existing->id,
            'title' => $existing->title,
            'filename' => $existing->filename,
            'source' => $existing->source,
        ]);

        return 'updated';
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
            $changed = DB::table('predb')
                ->where('id', $id)
                ->where('nuked', '!=', $changes['nuked'])
                ->update(['nuked' => $changes['nuked'], 'nukereason' => $changes['nukereason'] ?? null]) > 0 || $changed;
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

        if ($entry->nuked !== Predb::PRE_NONUKE && (int) $existing->nuked !== $entry->nuked) {
            $changes['nuked'] = $entry->nuked;
            $changes['nukereason'] = $entry->nukeReason !== null ? mb_substr($entry->nukeReason, 0, 255) : $existing->nukereason;
        }

        return $changes;
    }
}
