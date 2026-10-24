<?php

declare(strict_types=1);

namespace App\Services\Predb\Feeds;

use App\Facades\Search;
use App\Models\Predb;
use App\Services\Predb\PredbIdentityWriter;
use App\Support\PredbSearchDocument;
use App\Support\RustPipelineLibrary;
use FFI;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Upserts feed entries into the predb table by title, mirroring the IRC scraper:
 * new titles are inserted and indexed; existing rows only gain missing details or nuke changes.
 * Rows the database rejects as data are skipped; infrastructure failures propagate.
 */
class PredbFeedImporter
{
    public const string FFI_CDEF = <<<'C'
        ssize_t nntmux_predb_feed_importer_changes_for_json(const char *existing_category, size_t existing_category_len, const char *existing_size, size_t existing_size_len, const char *existing_files, size_t existing_files_len, const char *existing_nukereason, size_t existing_nukereason_len, const char *entry_category, size_t entry_category_len, const char *entry_size, size_t entry_size_len, const char *entry_files, size_t entry_files_len, const char *entry_predate, size_t entry_predate_len, const char *entry_nukereason, size_t entry_nukereason_len, uint32_t present_mask, uint8_t existing_predate_null, int64_t existing_nuked, int64_t entry_nuked, char *out_buf, size_t out_cap);
        C;

    /**
     * changesFor() stays on PHP: Eloquent attribute reads dominate and FFI plus JSON decoding
     * cannot recover 1.5x (see RustPredbFeedsAndStreamParityTest for the benchmark evidence).
     */
    private static bool $useRustFfi = false;

    private static FFI|false|null $ffi = null;

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
            $connection = DB::connection();
            $ambient = $connection->transactionLevel() > 0;
            $plan = $this->importEntry($entry, $dryRun, $connection);
            $failure = $plan['source_failure'];
            if ($failure !== null) {
                if (! str_starts_with((string) $failure->getCode(), '22')) {
                    throw $failure;
                }
                Log::warning('PreDB feed entry import failed', ['title' => $entry->title, 'source' => $entry->source, 'error' => $failure->getMessage()]);
                $plan = $this->plan('skipped');
            }
            if ($plan['publication'] !== null) {
                $this->publish($connection, $ambient, $plan['publication']);
            }

            $result[$plan['outcome']]++;
        }

        return $result;
    }

    /**
     * @return array{outcome: 'inserted'|'updated'|'skipped', publication: null|array{method: 'insert'|'update', document: array<string, mixed>}, source_failure: QueryException|null}
     */
    private function importEntry(PredbFeedEntry $entry, bool $dryRun, Connection $connection): array
    {
        $title = trim($entry->title);

        if ($title === '' || mb_strlen($title) > 255) {
            return $this->plan('skipped');
        }

        $existing = ($dryRun ? $this->findDryRunRow($title) : null) ?? Predb::query()->where('title', $title)->first();

        if ($existing === null) {
            if ($entry->enrichOnly) {
                return $this->plan('skipped');
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

                return $this->plan('inserted');
            }

            $insert = $this->insert($title, $entry, $connection);
            if (! $insert['source_failure'] instanceof UniqueConstraintViolationException) {
                return $insert;
            }
            // Only a verified source INSERT rejection can enter competing-row recovery.
            $existing = Predb::query()->where('title', $title)->first();
            if ($existing === null) {
                return $this->plan('skipped');
            }
        }

        if (PredbSearchDocument::exactValue((string) $existing->title) !== PredbSearchDocument::exactValue($title)) {
            return $this->plan('skipped');
        }

        $changes = $this->changesFor($existing, $entry);

        if ($changes === []) {
            if (! $dryRun && $existing->exists) {
                // Idempotent re-index from the current row (another ingester may have changed the
                // indexed fields), so a document dropped by an earlier failed index write recovers.
                return $this->plan('skipped', $this->reindexPlan((int) $existing->id, Predb::query()->find($existing->id, ['id', 'title', 'filename', 'source']), $title));
            }

            return $this->plan('skipped');
        }

        if ($dryRun) {
            $shadow = $this->dryRunRows[$this->shadowKey($title)] ?? clone $existing;
            foreach (array_diff_key($changes, ['nuked_from' => true, 'nuked_status' => true, 'nukereason_from' => true]) as $column => $value) {
                $shadow->setAttribute($column, $value);
            }
            $this->dryRunRows[$this->shadowKey($title)] = $shadow;

            return $this->plan('updated');
        }

        $changes['_observed_title'] = (string) $existing->title;
        try {
            $changed = $this->applyChanges((int) $existing->id, $changes);
        } catch (QueryException $error) {
            // This existing method contains source metadata DML only, never writer maintenance.
            if (! str_starts_with((string) $error->getCode(), '22')) {
                throw $error;
            }

            return $this->plan('skipped', null, $error);
        }
        if (! $changed) {
            return $this->plan('skipped');
        }

        // Re-read the indexed fields: another ingester may have changed them since $existing was read.
        $publication = $this->reindexPlan((int) $existing->id, Predb::query()->find($existing->id, ['id', 'title', 'filename', 'source']), $title);

        return $this->plan($publication !== null ? 'updated' : 'skipped', $publication);
    }

    /** @return null|array{method: 'update', document: array<string, mixed>} */
    private function reindexPlan(int $id, ?Predb $row, string $expectedTitle): ?array
    {
        if ($row === null || PredbSearchDocument::exactValue((string) $row->title) !== PredbSearchDocument::exactValue($expectedTitle)) {
            return null;
        }

        return ['method' => 'update', 'document' => [
            'id' => $id,
            'title' => $row->title,
            'filename' => $row->filename,
            'source' => $row->source,
        ]];
    }

    /**
     * @param  'inserted'|'updated'|'skipped'  $outcome
     * @param  null|array{method: 'insert'|'update', document: array<string, mixed>}  $publication
     * @return array{outcome: 'inserted'|'updated'|'skipped', publication: null|array{method: 'insert'|'update', document: array<string, mixed>}, source_failure: QueryException|null}
     */
    private function plan(string $outcome, ?array $publication = null, ?QueryException $sourceFailure = null): array
    {
        return ['outcome' => $outcome, 'publication' => $publication, 'source_failure' => $sourceFailure];
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

    /** @return array{outcome: 'inserted'|'updated'|'skipped', publication: null|array{method: 'insert'|'update', document: array<string, mixed>}, source_failure: QueryException|null} */
    private function insert(string $title, PredbFeedEntry $entry, Connection $connection): array
    {
        $written = (new PredbIdentityWriter($connection))->insertFeed($title, $entry);
        if ($written['status'] === 'source_rejected') {
            if (! $written['source_failure'] instanceof QueryException) {
                throw new RuntimeException('PreDB writer source rejection lacks its original failure.');
            }

            return $this->plan('skipped', null, $written['source_failure']);
        }
        if ($written['status'] !== 'inserted' || $written['id'] === null) {
            throw new RuntimeException('PreDB writer did not return the inserted feed owner.');
        }

        return $this->plan('inserted', ['method' => 'insert', 'document' => [
            'id' => $written['id'],
            'title' => $title,
            'filename' => '',
            'source' => $entry->source,
        ]]);
    }

    /** @param array{method: 'insert'|'update', document: array<string, mixed>} $publication */
    private function publish(Connection $connection, bool $ambient, array $publication): void
    {
        $publish = static function () use ($publication): void {
            if ($publication['method'] === 'insert') {
                Search::insertPredb($publication['document']);
            } else {
                Search::updatePreDb($publication['document']);
            }
        };
        if ($ambient) {
            $connection->afterCommit($publish);
        } else {
            $publish();
        }
    }

    public static function setUseRustFfi(bool $enabled): void
    {
        self::$useRustFfi = $enabled;
    }

    public static function usesRustFfi(): bool
    {
        return self::$useRustFfi;
    }

    private static function ffi(): ?FFI
    {
        self::$ffi ??= RustPipelineLibrary::resolveDomain('predb', self::FFI_CDEF) ?? false;

        return self::$ffi === false ? null : self::$ffi;
    }

    /**
     * Only fill details the row is missing, and record nuke status changes.
     *
     * @return array<string, mixed>
     */
    private function changesFor(Predb $existing, PredbFeedEntry $entry): array
    {
        if (self::$useRustFfi) {
            $supported = false;
            $native = $this->changesForNative($existing, $entry, $supported);
            if ($supported && $native !== null) {
                return $native;
            }
        }

        return $this->changesForPhp($existing, $entry);
    }

    /**
     * Rust changesFor(); $supported is false for non-string attributes, invalid UTF-8 or an
     * unavailable library.
     *
     * @return array<string, mixed>|null
     */
    public function changesForNative(Predb $existing, PredbFeedEntry $entry, bool &$supported = false): ?array
    {
        $supported = false;
        $ffi = self::ffi();
        if ($ffi === null) {
            return null;
        }
        $strings = [
            $existing->category,
            $existing->size,
            $existing->files,
            $existing->nukereason,
            $entry->category,
            $entry->size,
            $entry->files,
            $entry->predate?->format('Y-m-d H:i:s'),
            $entry->nukeReason,
        ];
        $mask = 0;
        foreach ($strings as $bit => $value) {
            if ($value === null) {
                continue;
            }
            if (! is_string($value)) {
                return null;
            }
            $mask |= 1 << $bit;
        }
        [$existingCategory, $existingSize, $existingFiles, $existingReason, $category, $size, $files, $predate, $reason] = $strings;
        $call = static fn (FFI\CData $scratch, int $capacity): mixed => $ffi->nntmux_predb_feed_importer_changes_for_json( // @phpstan-ignore method.notFound
            $existingCategory, $existingCategory === null ? 0 : strlen($existingCategory),
            $existingSize, $existingSize === null ? 0 : strlen($existingSize),
            $existingFiles, $existingFiles === null ? 0 : strlen($existingFiles),
            $existingReason, $existingReason === null ? 0 : strlen($existingReason),
            $category, $category === null ? 0 : strlen($category),
            $size, $size === null ? 0 : strlen($size),
            $files, $files === null ? 0 : strlen($files),
            $predate, $predate === null ? 0 : strlen($predate),
            $reason, $reason === null ? 0 : strlen($reason),
            $mask,
            $existing->predate === null ? 1 : 0,
            (int) $existing->nuked,
            $entry->nuked,
            $scratch,
            $capacity,
        );
        try {
            $capacity = 0;
            $scratch = RustPipelineLibrary::obtainScratch($ffi, 4096, $capacity);
            $written = $scratch === null ? null : $call($scratch, $capacity);
            if (is_int($written) && $written < -1) {
                $scratch = RustPipelineLibrary::obtainScratch($ffi, -$written, $capacity);
                $written = $scratch === null ? null : $call($scratch, $capacity);
            }
        } catch (Throwable) {
            return null;
        }
        if (! is_int($written) || $written < 0 || $scratch === null) {
            return null;
        }
        if ($written === 0) {
            $supported = true;

            return [];
        }
        $changes = json_decode(FFI::string($scratch, $written), true);
        if (! is_array($changes)) {
            return null;
        }
        $supported = true;

        /** @var array<string, mixed> $changes */
        return $changes;
    }

    /**
     * @return array<string, mixed>
     */
    public function changesForPhp(Predb $existing, PredbFeedEntry $entry): array
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
