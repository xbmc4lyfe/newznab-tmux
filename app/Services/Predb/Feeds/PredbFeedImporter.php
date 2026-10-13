<?php

declare(strict_types=1);

namespace App\Services\Predb\Feeds;

use App\Facades\Search;
use App\Models\Predb;
use App\Support\PredbSearchDocument;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

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

    /** @var array<string, string> */
    private array $dryRunKeys = [];

    /**
     * @param  iterable<PredbFeedEntry>  $entries
     * @return array{inserted: int, updated: int, skipped: int}
     */
    public function import(iterable $entries, bool $dryRun = false): array
    {
        if ($dryRun && in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            $entries = $this->prepareDryRunEntries($entries);
        }
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
        $title = trim($entry->title);

        if ($title === '' || mb_strlen($title) > 255) {
            return 'skipped';
        }

        $existing = ($dryRun ? $this->findDryRunRow($title) : null) ?? Predb::query()->where('title', $title)->first();

        if ($existing === null) {
            if ($entry->enrichOnly) {
                return 'skipped';
            }

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

        if (PredbSearchDocument::exactValue((string) $existing->title) !== PredbSearchDocument::exactValue($title)) {
            return 'skipped';
        }

        $changes = $this->changesFor($existing, $entry);

        if ($changes === []) {
            if (! $dryRun && $existing->exists) {
                // Idempotent re-index from the current row (another ingester may have changed the
                // indexed fields), so a document dropped by an earlier failed index write recovers.
                $this->reindex((int) $existing->id, Predb::query()->find($existing->id, ['id', 'title', 'filename', 'source']), $title);
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

        $changes['_observed_title'] = (string) $existing->title;
        if (! $this->applyChanges((int) $existing->id, $changes)) {
            return 'skipped';
        }

        // Re-read the indexed fields: another ingester may have changed them since $existing was read.
        return $this->reindex((int) $existing->id, Predb::query()->find($existing->id, ['id', 'title', 'filename', 'source']), $title) ? 'updated' : 'skipped';
    }

    private function reindex(int $id, ?Predb $row, string $expectedTitle): bool
    {
        if ($row === null || PredbSearchDocument::exactValue((string) $row->title) !== PredbSearchDocument::exactValue($expectedTitle)) {
            return false;
        }
        Search::updatePreDb([
            'id' => $id,
            'title' => $row->title,
            'filename' => $row->filename,
            'source' => $row->source,
        ]);

        return true;
    }

    /**
     * MySQL keys follow the real title-column collation; other drivers retain literal keys.
     */
    private function shadowKey(string $title): string
    {
        $literal = 'literal:'.PredbSearchDocument::exactValue($title);

        return in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true) ? $this->dryRunKeys[$literal] : $literal;
    }

    private function findDryRunRow(string $title): ?Predb
    {
        $key = $this->shadowKey($title);
        if (isset($this->dryRunRows[$key])) {
            return $this->dryRunRows[$key];
        }
        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            return null;
        }

        foreach (array_chunk($this->dryRunRows, 100) as $rows) {
            $comparison = DB::table('predb')->select('title')->whereRaw('1 = 0');
            foreach ($rows as $row) {
                $comparison->unionAll(DB::query()->selectRaw('? AS title', [(string) $row->title]));
            }
            $match = DB::query()->fromSub($comparison, 'shadow_titles')->where('title', $title)->value('title');
            if (is_string($match)) {
                return $this->dryRunRows[$this->shadowKey($match)];
            }
        }

        return null;
    }

    /**
     * @param  iterable<PredbFeedEntry>  $entries
     * @return \Generator<int, PredbFeedEntry>
     */
    private function prepareDryRunEntries(iterable $entries): \Generator
    {
        $chunk = [];
        foreach ($entries as $entry) {
            $chunk[] = $entry;
            if (count($chunk) === 100) {
                $this->cacheShadowKeys($chunk);
                yield from $chunk;
                $chunk = [];
            }
        }
        if ($chunk !== []) {
            $this->cacheShadowKeys($chunk);
            yield from $chunk;
        }
    }

    /** @param array<array-key, PredbFeedEntry> $entries */
    private function cacheShadowKeys(array $entries): void
    {
        $missing = [];
        foreach ($entries as $entry) {
            $title = trim($entry->title);
            $literal = 'literal:'.PredbSearchDocument::exactValue($title);
            if ($title !== '' && mb_strlen($title) <= 255 && ! isset($this->dryRunKeys[$literal])) {
                $missing[$literal] = $title;
            }
        }

        foreach (array_chunk($missing, 100) as $titles) {
            $comparison = DB::table('predb')->select('title')->whereRaw('1 = 0')
                ->unionAll(DB::query()->selectRaw('? AS title', [' ']));
            foreach ($titles as $title) {
                $comparison->unionAll(DB::query()->selectRaw('? AS title', [$title]));
            }
            /** @var Collection<int, object{title: string, weight_key: string, title_collation: string}> $rows */
            $rows = DB::query()->fromSub($comparison, 'shadow_titles')
                ->selectRaw('title, HEX(WEIGHT_STRING(title)) AS weight_key, COLLATION(title) AS title_collation')->get();
            $space = $rows->first(static fn (object $row): bool => $row->title === ' ');
            if ($space === null || $space->weight_key === '') {
                throw new RuntimeException('Unable to verify the PreDB dry-run title collation.');
            }
            foreach ($rows as $row) {
                if ($row->title_collation !== 'utf8mb4_unicode_ci') {
                    throw new RuntimeException('PreDB dry-run title weights require the verified utf8mb4_unicode_ci collation.');
                }
                if ($row->title === ' ') {
                    continue;
                }
                $weight = $row->weight_key;
                // PAD SPACE ignores trailing space weights; full weights must never be truncated.
                while (str_ends_with($weight, $space->weight_key)) {
                    $weight = substr($weight, 0, -strlen($space->weight_key));
                }
                $this->dryRunKeys['literal:'.PredbSearchDocument::exactValue($row->title)] = 'weight:'.$weight;
            }
        }
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
        $observedTitle = $changes['_observed_title'] ?? DB::table('predb')->where('id', $id)->value('title');
        if (! is_string($observedTitle)) {
            return false;
        }
        $identity = DB::table('predb')->where('id', $id)->whereRaw('HEX(title) = HEX(?)', [$observedTitle]);
        $changed = false;

        foreach (['category', 'size', 'files'] as $column) {
            if (array_key_exists($column, $changes)) {
                $changed = (clone $identity)
                    ->where(static fn ($query) => $query->whereNull($column)->orWhere($column, ''))
                    ->update([$column => $changes[$column]]) > 0 || $changed;
            }
        }

        if (array_key_exists('predate', $changes)) {
            $changed = (clone $identity)->whereNull('predate')->update(['predate' => $changes['predate']]) > 0 || $changed;
        }

        if (array_key_exists('nuked', $changes)) {
            // Transition from the status that was read; if another ingester changed it since, keep theirs.
            $changed = (clone $identity)
                ->where('nuked', $changes['nuked_from'] ?? $changes['nuked'])
                ->update(['nuked' => $changes['nuked'], 'nukereason' => $changes['nukereason'] ?? null]) > 0 || $changed;
        } elseif (array_key_exists('nukereason', $changes)) {
            // Same status: fill or correct the reason while the status still matches.
            $observed = $changes['nukereason_from'] ?? null;
            $changed = (clone $identity)
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

        if (($existing->category === null || $existing->category === '') && $entry->category !== null) {
            $changes['category'] = mb_substr($entry->category, 0, 255);
        }

        if (($existing->size === null || $existing->size === '') && $entry->size !== null) {
            $changes['size'] = mb_substr($entry->size, 0, 50);
        }

        if (($existing->files === null || $existing->files === '') && $entry->files !== null) {
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
