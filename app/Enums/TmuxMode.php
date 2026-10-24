<?php

declare(strict_types=1);

namespace App\Enums;

use App\Support\RustPipelineLibrary;
use FFI;
use FFI\CData;
use Throwable;

enum TmuxMode: int
{
    public const string FFI_CDEF = <<<'C'
        ssize_t nntmux_tmux_mode_tasks_nul(int64_t mode, size_t *out_pair_count, char *out_buf, size_t out_cap);
        C;

    case Full = 0;
    case Basic = 1;
    case Stripped = 2;

    public static function setUseRustFfi(?bool $use = true): void
    {
        self::toggleState($use, true);
        if ($use === null) {
            self::categorizeFfi(true);
        }
    }

    public static function usesRustFfi(): bool
    {
        return self::toggleState() ?? false;
    }

    public static function setFfiForTesting(FFI|false|null $override = null): void
    {
        self::categorizeFfi(true, $override);
    }

    private static function toggleState(?bool $next = null, bool $write = false): ?bool
    {
        static $useRustFfi = null;
        if ($write) {
            $useRustFfi = $next;
        }

        return $useRustFfi;
    }

    public static function categorizeFfi(bool $reset = false, FFI|false|null $override = null): ?FFI
    {
        static $ffi = null;
        if ($reset) {
            $ffi = $override;
            if ($override !== null) {
                return $ffi === false ? null : $ffi;
            }
        }
        if ($ffi === null) {
            $ffi = RustPipelineLibrary::resolveDomain('categorize', self::FFI_CDEF) ?? false;
        }

        return $ffi === false ? null : $ffi;
    }

    /** @return array<string, TmuxPaneRole> */
    public function tasks(): array
    {
        return self::usesRustFfi() ? $this->tasksNative() : $this->tasksPhp();
    }

    /** @return array<string, TmuxPaneRole> */
    public function tasksPhp(): array
    {
        $tasks = match ($this) {
            self::Full => ['binaries' => TmuxPaneRole::Binaries, 'backfill' => TmuxPaneRole::Backfill, 'releases' => TmuxPaneRole::Releases],
            self::Basic => ['releases' => TmuxPaneRole::Releases],
            self::Stripped => ['main' => TmuxPaneRole::Sequential],
        };
        $tasks['fixnames'] = TmuxPaneRole::FixNames;
        if ($this !== self::Stripped) {
            $tasks += ['removecrap' => TmuxPaneRole::RemoveCrap, 'ppadditional' => TmuxPaneRole::PostAdditional, 'tv' => TmuxPaneRole::PostTv, 'movies' => TmuxPaneRole::PostMovies];
        }
        $tasks['amazon'] = TmuxPaneRole::PostMetadata;
        $tasks['scraper'] = TmuxPaneRole::IrcScraper;

        return $tasks;
    }

    /** @return array<string, TmuxPaneRole> */
    public function tasksNative(): array
    {
        $ffi = self::categorizeFfi();
        $outPairCount = $ffi === null ? null : self::pairCountSlot($ffi);
        if ($ffi === null || $outPairCount === null) {
            return $this->tasksPhp();
        }

        $cap = 0;
        $scratch = RustPipelineLibrary::obtainScratch($ffi, 512, $cap);
        if ($scratch === null) {
            return $this->tasksPhp();
        }

        try {
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_tmux_mode_tasks_nul($this->value, $outPairCount, $scratch, $cap);
            if (\is_int($written) && $written < -2) {
                $scratch = RustPipelineLibrary::obtainScratch($ffi, -$written, $cap);
                if ($scratch === null) {
                    return $this->tasksPhp();
                }
                // @phpstan-ignore method.notFound
                $written = $ffi->nntmux_tmux_mode_tasks_nul($this->value, $outPairCount, $scratch, $cap);
            }
            if (! \is_int($written) || $written <= 0 || $written > $cap) {
                return $this->tasksPhp();
            }

            /** @var int $expectedPairs */
            $expectedPairs = $outPairCount[0];
            $tokens = explode("\0", FFI::string($scratch, $written));
            if (\count($tokens) !== $expectedPairs * 2) {
                return $this->tasksPhp();
            }

            $tasks = [];
            for ($i = 0; $i < $expectedPairs; $i++) {
                $key = $tokens[$i * 2];
                $role = TmuxPaneRole::tryFrom($tokens[$i * 2 + 1]);
                if ($key === '' || $role === null) {
                    return $this->tasksPhp();
                }
                $tasks[$key] = $role;
            }

            return $tasks;
        } catch (Throwable) {
            return $this->tasksPhp();
        }
    }

    private static function pairCountSlot(FFI $ffi): ?CData
    {
        static $slot = null;
        static $owner = null;
        if ($owner !== $ffi || $slot === null) {
            try {
                $slot = $ffi->new('size_t[1]');
                $owner = $ffi;
            } catch (Throwable) {
                return null;
            }
        }

        return $slot;
    }
}
