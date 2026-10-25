<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Facades\Search;
use App\Models\Predb;
use App\Models\Release;
use App\Services\NameFixing\ConstrainedPredbMatcher;
use App\Services\NameFixing\NameFixingService;
use App\Services\NameFixing\PredbIdentityKey;
use App\Support\RustPipelineLibrary;
use FFI;
use Illuminate\Console\Command;
use Throwable;

class ReleaseIdentitySuggestions extends Command
{
    /**
     * Production default stays false: the paired benchmark in
     * tests/Unit/RustPredbCheckRebuildSuggestionsImportParityTest.php is below 1.5x.
     */
    public const bool RUST_FFI_DEFAULT = false;

    public const string FFI_CDEF = <<<'C'
        ssize_t nntmux_w373_release_identity_plan(
            uint32_t flags,
            const char *query_ptr,
            size_t query_len,
            const char *release_ptr,
            size_t release_len,
            const char *ids_ptr,
            size_t ids_len,
            size_t id_count,
            char *out_buf,
            size_t out_cap
        );
        C;

    public const int MAX_QUERY_BYTES = 512;

    public const int MAX_CANDIDATES = 50;

    private const array SOURCES = ['argument', 'release', 'none'];

    private const array STATUSES = ['ok', 'needs_release_lookup', 'invalid_query', 'too_many_candidates', 'invalid_candidate'];

    private const array STATUS_MESSAGES = [
        'ok' => null,
        'needs_release_lookup' => null,
        'invalid_query' => 'Provide a release ID or a valid query of at most 512 bytes.',
        'too_many_candidates' => 'At most 50 candidate IDs may be supplied.',
        'invalid_candidate' => 'Candidate IDs must be positive integers.',
    ];

    private const int HAS_QUERY = 1;

    private const int HAS_RELEASE_ID = 2;

    private const string FIELD_SEPARATOR = "\x1F";

    private static ?bool $rustFfiOverride = null;

    private static FFI|false|null $rustFfiHandle = null;

    protected $signature = 'releases:identity-suggestions {query? : Filename or partial release title}
        {--release-id= : Read the current search name of this release}
        {--candidate-id=* : Restrict to these known PreDB IDs (maximum 50)}';

    protected $description = 'Report constrained, read-only identity suggestions; never rename or link releases';

    public function handle(ConstrainedPredbMatcher $matcher): int
    {
        $query = (string) ($this->argument('query') ?? '');
        if ($query === '' && ctype_digit((string) $this->option('release-id'))) {
            $query = (string) Release::query()->whereKey((int) $this->option('release-id'))->value('searchname');
        }
        if ($query === '' || strlen($query) > 512 || ! mb_check_encoding($query, 'UTF-8')) {
            $this->error('Provide a release ID or a valid query of at most 512 bytes.');

            return self::FAILURE;
        }
        $ids = $this->option('candidate-id');
        if (! is_array($ids) || count($ids) > 50) {
            $this->error('At most 50 candidate IDs may be supplied.');

            return self::FAILURE;
        }
        foreach ($ids as $id) {
            if (! ctype_digit((string) $id) || (int) $id < 1) {
                $this->error('Candidate IDs must be positive integers.');

                return self::FAILURE;
            }
        }
        try {
            if ($ids === []) {
                $hits = Search::searchPredb(NameFixingService::predbSearchText($query));
                if (count($hits) > 50) {
                    $this->line(json_encode(['candidate_predb_id' => null, 'reason' => 'candidate_overflow', 'auto_link' => false], JSON_THROW_ON_ERROR));

                    return self::SUCCESS;
                }
                $ids = array_column($hits, 'id');
            }
            $hits = Predb::query()->whereIn('id', $ids)->get(['id', 'title'])->map(static fn (Predb $row): array => [
                'id' => (int) $row->id, 'title' => (string) $row->title,
            ])->all();
            $suggestion = $matcher->suggest($query, $hits);
            $complete = $matcher->completeEvidence($query, $hits);
            $this->line(json_encode(['query' => $query, 'candidate_predb_id' => $suggestion['id'],
                'known_title' => $suggestion['title'] ?? null, 'score' => $suggestion['score'] ?? null,
                'reason' => $suggestion['reason'], 'auto_link' => false,
                'marker_evidence' => $complete['id'] !== null ? 'complete' : 'unresolved_variant',
                'canonical_name_agreement' => isset($suggestion['title']) && PredbIdentityKey::normalize($query) === PredbIdentityKey::normalize($suggestion['title']),
                'candidate_scope' => 'bounded_database_revalidated_candidates',
                'requires' => 'independent exact filename or registered alias corroboration'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
        } catch (Throwable $error) {
            $this->error('Identity suggestion unavailable: '.$error->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    public static function useRustFfi(?bool $enabled): void
    {
        self::$rustFfiOverride = $enabled;
    }

    public static function usesRustFfi(): bool
    {
        return self::$rustFfiOverride ?? self::RUST_FFI_DEFAULT;
    }

    public static function resetRustFfi(): void
    {
        self::$rustFfiOverride = null;
        self::$rustFfiHandle = null;
    }

    /**
     * Pre-validates a suggestion request in handle()'s order without database or search access.
     * An empty query with a digit release ID needs a release lookup (its query is validated after
     * the lookup). Valid candidate IDs are returned without leading zeros, de-duplicated in order.
     *
     * @param  list<string>  $candidateIds
     * @return array{query_source: string, query_bytes: int, query_valid: bool, candidate_count: int, candidate_scope: string, status: string, message: ?string, candidate_ids: list<string>}
     */
    public static function planSuggestionRequest(?string $query, ?string $releaseId, array $candidateIds): array
    {
        return (self::usesRustFfi() ? self::planSuggestionRequestNative($query, $releaseId, $candidateIds) : null)
            ?? self::planSuggestionRequestPhp($query, $releaseId, $candidateIds);
    }

    /**
     * @param  list<string>  $candidateIds
     * @return array{query_source: string, query_bytes: int, query_valid: bool, candidate_count: int, candidate_scope: string, status: string, message: ?string, candidate_ids: list<string>}|null
     */
    private static function planSuggestionRequestNative(?string $query, ?string $releaseId, array $candidateIds): ?array
    {
        $flags = ($query === null ? 0 : self::HAS_QUERY) | ($releaseId === null ? 0 : self::HAS_RELEASE_ID);
        $query ??= '';
        $releaseId ??= '';
        $count = count($candidateIds);
        $joined = $count > self::MAX_CANDIDATES ? '' : implode(self::FIELD_SEPARATOR, $candidateIds);
        $fields = self::callNative(
            'nntmux_w373_release_identity_plan',
            [$flags, $query, strlen($query), $releaseId, strlen($releaseId), $joined, strlen($joined), $count],
            64 + strlen($joined),
        );
        if ($fields === null || count($fields) < 7 || ! in_array($fields[0], ['0', '1', '2'], true) || ! ctype_digit($fields[1])
            || ! in_array($fields[5], ['0', '1', '2', '3', '4'], true) || ! ctype_digit($fields[6]) || count($fields) !== 7 + (int) $fields[6]
            || $fields[3] !== (string) $count) {
            return null;
        }
        $status = self::STATUSES[(int) $fields[5]];

        return [
            'query_source' => self::SOURCES[(int) $fields[0]],
            'query_bytes' => (int) $fields[1],
            'query_valid' => $fields[2] === '1',
            'candidate_count' => $count,
            'candidate_scope' => $fields[4] === '1' ? 'explicit' : 'search',
            'status' => $status,
            'message' => self::STATUS_MESSAGES[$status],
            'candidate_ids' => array_slice($fields, 7),
        ];
    }

    /**
     * @param  list<string>  $candidateIds
     * @return array{query_source: string, query_bytes: int, query_valid: bool, candidate_count: int, candidate_scope: string, status: string, message: ?string, candidate_ids: list<string>}
     */
    private static function planSuggestionRequestPhp(?string $query, ?string $releaseId, array $candidateIds): array
    {
        $query ??= '';
        $source = $query !== '' ? 'argument' : (ctype_digit($releaseId ?? '') ? 'release' : 'none');
        $queryValid = $source === 'argument' && strlen($query) <= self::MAX_QUERY_BYTES && mb_check_encoding($query, 'UTF-8');
        $count = count($candidateIds);
        $candidatesValid = true;
        foreach ($candidateIds as $id) {
            if (! ctype_digit($id) || ltrim($id, '0') === '') {
                $candidatesValid = false;
                break;
            }
        }
        $status = match (true) {
            $source === 'none' || ($source === 'argument' && ! $queryValid) => 'invalid_query',
            $count > self::MAX_CANDIDATES => 'too_many_candidates',
            ! $candidatesValid => 'invalid_candidate',
            $source === 'release' => 'needs_release_lookup',
            default => 'ok',
        };
        $ids = [];
        if ($status === 'ok' || $status === 'needs_release_lookup') {
            $seen = [];
            foreach ($candidateIds as $id) {
                $normalized = ltrim($id, '0');
                if (! isset($seen[$normalized])) {
                    $seen[$normalized] = true;
                    $ids[] = $normalized;
                }
            }
        }

        return [
            'query_source' => $source,
            'query_bytes' => strlen($query),
            'query_valid' => $queryValid,
            'candidate_count' => $count,
            'candidate_scope' => $count > 0 ? 'explicit' : 'search',
            'status' => $status,
            'message' => self::STATUS_MESSAGES[$status],
            'candidate_ids' => $ids,
        ];
    }

    /**
     * @param  list<int|string>  $args  arguments preceding the caller-owned output buffer
     * @return list<string>|null
     */
    private static function callNative(string $symbol, array $args, int $minBytes, int $limit = PHP_INT_MAX): ?array
    {
        $ffi = self::rustFfi();
        if ($ffi === null) {
            return null;
        }

        try {
            $capacity = 0;
            $buffer = RustPipelineLibrary::obtainScratch($ffi, $minBytes, $capacity);
            if ($buffer === null) {
                return null;
            }
            $written = $ffi->$symbol(...[...$args, $buffer, $capacity]);
            if (is_int($written) && $written < -1) {
                $buffer = RustPipelineLibrary::obtainScratch($ffi, -$written, $capacity);
                if ($buffer === null) {
                    return null;
                }
                $written = $ffi->$symbol(...[...$args, $buffer, $capacity]);
            }
            if (! is_int($written) || $written < 0 || $written > $capacity) {
                return null;
            }

            return explode(self::FIELD_SEPARATOR, FFI::string($buffer, $written), $limit);
        } catch (Throwable) {
            return null;
        }
    }

    private static function rustFfi(): ?FFI
    {
        if (self::$rustFfiHandle === null) {
            self::$rustFfiHandle = RustPipelineLibrary::resolveDomain('categorize_w373', self::FFI_CDEF) ?? false;
        }

        return self::$rustFfiHandle === false ? null : self::$rustFfiHandle;
    }
}
