<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\Search\Contracts\SearchServiceInterface;
use App\Support\RustPipelineLibrary;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Throwable;

class SearchSuggestController extends Controller
{
    private SearchServiceInterface $searchEngine;

    public function __construct(SearchServiceInterface $searchEngine)
    {
        $this->searchEngine = $searchEngine;
    }

    /**
     * Get autocomplete suggestions for search input.
     */
    public function autocomplete(Request $request): JsonResponse
    {
        $query = $request->input('q', '');
        // Use the index from search engine config, or allow override via request
        $index = $request->input('index') ?? $this->searchEngine->getReleasesIndex();

        // Rate limiting: 60 requests per minute per IP
        $key = 'autocomplete:'.$request->ip();
        if (RateLimiter::tooManyAttempts($key, 60)) {
            return response()->json([
                'success' => false,
                'error' => 'Too many requests',
            ], 429);
        }
        RateLimiter::hit($key, 60);

        if (! $this->searchEngine->isAutocompleteEnabled()) {
            return response()->json([
                'success' => true,
                'suggestions' => [],
            ]);
        }

        if (strlen($query) < 2) {
            return response()->json([
                'success' => true,
                'suggestions' => [],
            ]);
        }

        try {
            $suggestions = $this->searchEngine->autocomplete($query, $index);

            return response()->json([
                'success' => true,
                'query' => $query,
                'suggestions' => array_map(fn ($s) => $s['suggest'], $suggestions),
            ]);
        } catch (Throwable $e) {
            return response()->json([
                'success' => true,
                'query' => $query,
                'suggestions' => [],
            ]);
        }
    }

    /**
     * Get spell correction suggestions ("Did you mean?").
     */
    public function suggest(Request $request): JsonResponse
    {
        $query = $request->input('q', '');
        $index = $request->input('index') ?? $this->searchEngine->getReleasesIndex();

        // Rate limiting: 30 requests per minute per IP
        $key = 'suggest:'.$request->ip();
        if (RateLimiter::tooManyAttempts($key, 30)) {
            return response()->json([
                'success' => false,
                'error' => 'Too many requests',
            ], 429);
        }
        RateLimiter::hit($key, 60);

        if (! $this->searchEngine->isSuggestEnabled()) {
            return response()->json([
                'success' => true,
                'suggestions' => [],
            ]);
        }

        if (empty($query)) {
            return response()->json([
                'success' => true,
                'suggestions' => [],
            ]);
        }

        try {
            $suggestions = $this->searchEngine->suggest($query, $index);

            // Get the best suggestion (highest doc count)
            $bestSuggestion = null;
            if (! empty($suggestions)) {
                $suggestions = self::sortSuggestions($suggestions);
                $bestSuggestion = $suggestions[0]['suggest'] ?? null;
            }

            return response()->json([
                'success' => true,
                'query' => $query,
                'suggestions' => array_map(fn ($s) => $s['suggest'], $suggestions),
                'best' => $bestSuggestion,
            ]);
        } catch (Throwable $e) {
            return response()->json([
                'success' => true,
                'query' => $query,
                'suggestions' => [],
            ]);
        }
    }

    /**
     * Combined endpoint for search assistance (autocomplete + suggest).
     */
    public function searchAssist(Request $request): JsonResponse
    {
        $query = $request->input('q', '');
        $index = $request->input('index') ?? $this->searchEngine->getReleasesIndex();

        // Rate limiting
        $key = 'searchassist:'.$request->ip();
        if (RateLimiter::tooManyAttempts($key, 60)) {
            return response()->json([
                'success' => false,
                'error' => 'Too many requests',
            ], 429);
        }
        RateLimiter::hit($key, 60);

        $response = [
            'success' => true,
            'query' => $query,
            'autocomplete' => [],
            'suggest' => null,
        ];

        try {
            // Get autocomplete suggestions
            if ($this->searchEngine->isAutocompleteEnabled() && strlen($query) >= 2) {
                $autocomplete = $this->searchEngine->autocomplete($query, $index);
                $response['autocomplete'] = array_map(fn ($s) => $s['suggest'], $autocomplete);
            }

            // Get spell suggestion if query is complete (user stopped typing)
            if ($this->searchEngine->isSuggestEnabled() && ! empty($query)) {
                $suggestions = $this->searchEngine->suggest($query, $index);
                if (! empty($suggestions)) {
                    $suggestions = self::sortSuggestions($suggestions);
                    $response['suggest'] = $suggestions[0]['suggest'] ?? null;
                }
            }
        } catch (Throwable $e) {
            // Return empty results on error
        }

        return response()->json($response);
    }

    /**
     * Suggestions ordered by document count, highest first, keeping the engine's order
     * for ties (PHP's stable usort). Rust is opt-in; it is slower in the benchmark.
     *
     * @param  array<array-key, mixed>  $suggestions
     * @return list<mixed>
     */
    public static function sortSuggestions(array $suggestions, bool $useRustFfi = false): array
    {
        return ($useRustFfi ? self::sortSuggestionsNative($suggestions) : null) ?? self::sortSuggestionsPhp($suggestions);
    }

    /**
     * @param  array<array-key, mixed>  $suggestions
     * @return list<mixed>
     */
    public static function sortSuggestionsPhp(array $suggestions): array
    {
        usort($suggestions, fn ($a, $b) => $b['docs'] - $a['docs']);

        return $suggestions;
    }

    /**
     * Native ordering for integer document counts; null defers other shapes to PHP.
     *
     * @param  array<array-key, mixed>  $suggestions
     * @return list<mixed>|null
     */
    public static function sortSuggestionsNative(array $suggestions): ?array
    {
        $suggestions = array_values($suggestions);
        $count = count($suggestions);
        $docs = [];
        foreach ($suggestions as $suggestion) {
            if (! is_array($suggestion) || ! is_int($suggestion['docs'] ?? null)) {
                return null;
            }
            $docs[] = $suggestion['docs'];
        }
        if ($count < 2) {
            return $suggestions;
        }
        static $ffi, $docBuffer, $orderBuffer, $capacity = 0;
        $ffi ??= RustPipelineLibrary::resolveDomain('search', 'int32_t nntmux_search_suggest_rank(const int64_t *docs, size_t count, uint32_t *order);') ?? false;
        if ($ffi === false) {
            return null;
        }
        try {
            if ($capacity < $count) {
                $capacity = max(64, $count * 2);
                $docBuffer = $ffi->new("int64_t[{$capacity}]");
                $orderBuffer = $ffi->new("uint32_t[{$capacity}]");
            }
            foreach ($docs as $i => $value) {
                $docBuffer[$i] = $value;
            }
            if ($ffi->nntmux_search_suggest_rank($docBuffer, $count, $orderBuffer) !== 0) {
                return null;
            }
            $sorted = [];
            for ($i = 0; $i < $count; $i++) {
                $sorted[] = $suggestions[$orderBuffer[$i]];
            }

            return $sorted;
        } catch (Throwable) {
            return null;
        }
    }
}
