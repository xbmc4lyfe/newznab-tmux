<?php

declare(strict_types=1);

namespace App\Providers;

use App\Support\Forum\CategoryTreeBuilder;
use App\Support\RustPipelineLibrary;
use FFI;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use TeamTeaTime\Forum\Models\Category;
use TeamTeaTime\Forum\Models\Post;
use Throwable;

class ForumServiceProvider extends ServiceProvider
{
    public const string FFI_CDEF = <<<'C'
        ssize_t nntmux_w364_forum_policy_summary(
            int64_t min_post_length,
            int64_t max_post_length,
            int64_t rate_limit_per_minute,
            uint32_t allow_guest_read,
            char *out_buf,
            size_t out_cap
        );
        C;

    /**
     * Production default stays false: the paired benchmark in
     * tests/Unit/RustTvForumRoutePrivacyTermsParityTest.php is below 1.5x.
     */
    private const bool RUST_FFI_DEFAULT = false;

    private const int POST_LENGTH_MIN = 1;

    private const int POST_LENGTH_MAX = 65535;

    private const int RATE_LIMIT_MAX = 600;

    private const string FIELD_SEPARATOR = "\x1F";

    private static ?bool $useRustFfi = null;

    private static FFI|false|null $ffi = null;

    /**
     * Register services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        // Add global scope to eager load author with role for all post queries
        Post::addGlobalScope('withAuthorRole', function (Builder $builder) {
            $builder->with(['author.role']);
        });

        $categoryTreeBuilder = $this->app->make(CategoryTreeBuilder::class);

        View::composer('forum::category.index', function ($view) use ($categoryTreeBuilder): void {
            $view->with('categories', $categoryTreeBuilder->buildAccessibleTo(request()->user()));
        });

        View::composer('forum::category.manage', function ($view) use ($categoryTreeBuilder): void {
            $categories = Category::defaultOrder()->get();
            $tree = $categoryTreeBuilder->build($categories);
            $categoryTreeBuilder->hideManageColumns($tree);

            $view->with('categories', $tree);
        });

        View::composer('forum::thread.show', function ($view) use ($categoryTreeBuilder): void {
            $data = $view->getData();

            if (! empty($data['categories'])) {
                return;
            }

            $view->with('categories', $categoryTreeBuilder->buildThreadDestinations());
        });
    }

    public static function usesRustFfi(): bool
    {
        return self::$useRustFfi ?? self::RUST_FFI_DEFAULT;
    }

    public static function setUseRustFfi(?bool $use = true): void
    {
        self::$useRustFfi = $use;
    }

    /**
     * Summarizes forum posting policy: lengths are clamped to 1-65535 (the maximum never below
     * the minimum), the rate limit to 0-600 posts per minute (0 disables it) and `adjusted`
     * reports whether any value had to change.
     *
     * @return array{min_post_length: int, max_post_length: int, rate_limit_per_minute: int, rate_limited: bool, cooldown_seconds: int, allow_guest_read: bool, adjusted: bool, summary: string}
     */
    public static function summarizeForumPolicyConfig(int $minPostLength, int $maxPostLength, int $rateLimitPerMinute, bool $allowGuestRead): array
    {
        return self::usesRustFfi()
            ? self::summarizeForumPolicyConfigNative($minPostLength, $maxPostLength, $rateLimitPerMinute, $allowGuestRead)
            : self::summarizeForumPolicyConfigPhp($minPostLength, $maxPostLength, $rateLimitPerMinute, $allowGuestRead);
    }

    /**
     * @return array{min_post_length: int, max_post_length: int, rate_limit_per_minute: int, rate_limited: bool, cooldown_seconds: int, allow_guest_read: bool, adjusted: bool, summary: string}
     */
    public static function summarizeForumPolicyConfigNative(int $minPostLength, int $maxPostLength, int $rateLimitPerMinute, bool $allowGuestRead): array
    {
        $fields = self::callNative([$minPostLength, $maxPostLength, $rateLimitPerMinute, $allowGuestRead ? 1 : 0]);
        if ($fields === null || count($fields) !== 8) {
            return self::summarizeForumPolicyConfigPhp($minPostLength, $maxPostLength, $rateLimitPerMinute, $allowGuestRead);
        }

        return [
            'min_post_length' => (int) $fields[0],
            'max_post_length' => (int) $fields[1],
            'rate_limit_per_minute' => (int) $fields[2],
            'rate_limited' => $fields[3] === '1',
            'cooldown_seconds' => (int) $fields[4],
            'allow_guest_read' => $fields[5] === '1',
            'adjusted' => $fields[6] === '1',
            'summary' => $fields[7],
        ];
    }

    /**
     * @return array{min_post_length: int, max_post_length: int, rate_limit_per_minute: int, rate_limited: bool, cooldown_seconds: int, allow_guest_read: bool, adjusted: bool, summary: string}
     */
    public static function summarizeForumPolicyConfigPhp(int $minPostLength, int $maxPostLength, int $rateLimitPerMinute, bool $allowGuestRead): array
    {
        $min = max(self::POST_LENGTH_MIN, min(self::POST_LENGTH_MAX, $minPostLength));
        $max = max($min, self::POST_LENGTH_MIN, min(self::POST_LENGTH_MAX, $maxPostLength));
        $rate = max(0, min(self::RATE_LIMIT_MAX, $rateLimitPerMinute));
        $cooldown = $rate > 0 ? intdiv(60 + $rate - 1, $rate) : 0;
        $summary = sprintf(
            'Posts %d-%d chars; %s; guests %s',
            $min,
            $max,
            $rate > 0 ? $rate.'/min' : 'no rate limit',
            $allowGuestRead ? 'may read' : 'must sign in',
        );

        return [
            'min_post_length' => $min,
            'max_post_length' => $max,
            'rate_limit_per_minute' => $rate,
            'rate_limited' => $rate > 0,
            'cooldown_seconds' => $cooldown,
            'allow_guest_read' => $allowGuestRead,
            'adjusted' => $min !== $minPostLength || $max !== $maxPostLength || $rate !== $rateLimitPerMinute,
            'summary' => $summary,
        ];
    }

    /**
     * @param  list<int>  $args
     * @return list<string>|null
     */
    private static function callNative(array $args): ?array
    {
        $ffi = self::ffi();
        if ($ffi === null) {
            return null;
        }

        try {
            $capacity = 0;
            $buffer = RustPipelineLibrary::obtainScratch($ffi, 256, $capacity);
            if ($buffer === null) {
                return null;
            }
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_w364_forum_policy_summary(...[...$args, $buffer, $capacity]);
            if (is_int($written) && $written < -1) {
                $buffer = RustPipelineLibrary::obtainScratch($ffi, -$written, $capacity);
                if ($buffer === null) {
                    return null;
                }
                // @phpstan-ignore method.notFound
                $written = $ffi->nntmux_w364_forum_policy_summary(...[...$args, $buffer, $capacity]);
            }
            if (! is_int($written) || $written < 0 || $written > $capacity) {
                return null;
            }

            return explode(self::FIELD_SEPARATOR, FFI::string($buffer, $written));
        } catch (Throwable) {
            return null;
        }
    }

    private static function ffi(): ?FFI
    {
        if (self::$ffi === null) {
            self::$ffi = RustPipelineLibrary::resolveDomain('metadata', self::FFI_CDEF) ?? false;
        }

        return self::$ffi === false ? null : self::$ffi;
    }
}
