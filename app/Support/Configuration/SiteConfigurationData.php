<?php

declare(strict_types=1);

namespace App\Support\Configuration;

use App\Models\SiteConfiguration;
use App\Support\RustPipelineLibrary;
use FFI;
use FFI\CData;
use Throwable;

final readonly class SiteConfigurationData
{
    public const string FFI_CDEF = <<<'C'
        ssize_t nntmux_site_config_defaults(int64_t *out_scalars, size_t scalars_cap, char *out_buf, size_t out_cap);
        ssize_t nntmux_site_config_normalize_logo(uint32_t has_logo, const char *logo_ptr, size_t logo_len, char *out_buf, size_t out_cap);
        int32_t nntmux_site_config_normalize_scalars(uint32_t has_logo, int64_t trailers_display, int64_t trailer_width, int64_t trailer_height, int64_t *out_scalars, size_t scalars_cap);
        C;

    /** Property names in declaration order (the `toArray()` keys). */
    public const array FIELDS = [
        'title', 'homeLink', 'logoPath', 'strapline', 'metaTitle',
        'metaDescription', 'metaKeywords', 'footer', 'dereferrerLink', 'terms',
        'trailersDisplay', 'trailerWidth', 'trailerHeight',
    ];

    public function __construct(
        public string $title,
        public string $homeLink,
        public ?string $logoPath,
        public string $strapline,
        public string $metaTitle,
        public string $metaDescription,
        public string $metaKeywords,
        public string $footer,
        public string $dereferrerLink,
        public string $terms,
        public bool $trailersDisplay,
        public int $trailerWidth,
        public int $trailerHeight,
    ) {}

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

    public static function defaults(): self
    {
        return self::usesRustFfi() ? self::defaultsNative() : self::defaultsPhp();
    }

    public static function defaultsPhp(): self
    {
        return new self('NNTmux', '/', null, 'A great usenet indexer', 'An indexer', 'A usenet indexing website', 'usenet,nzbs,cms,community', 'Usenet binary indexer.', '', '', true, 480, 345);
    }

    public static function defaultsNative(): self
    {
        $ffi = self::categorizeFfi();
        $scalarsBuf = $ffi === null ? null : self::scalarsBuffer($ffi);
        if ($ffi === null || $scalarsBuf === null) {
            return self::defaultsPhp();
        }
        $cap = 0;
        $scratch = RustPipelineLibrary::obtainScratch($ffi, 256, $cap);
        if ($scratch === null) {
            return self::defaultsPhp();
        }

        try {
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_site_config_defaults($scalarsBuf, 4, $scratch, $cap);
            if (\is_int($written) && $written < -1) {
                $scratch = RustPipelineLibrary::obtainScratch($ffi, -$written, $cap);
                if ($scratch === null) {
                    return self::defaultsPhp();
                }
                // @phpstan-ignore method.notFound
                $written = $ffi->nntmux_site_config_defaults($scalarsBuf, 4, $scratch, $cap);
            }
            if (! \is_int($written) || $written <= 0 || $written > $cap) {
                return self::defaultsPhp();
            }

            $scalarsRaw = unpack('q4', FFI::string($scalarsBuf, 32));
            $parts = explode("\0", FFI::string($scratch, $written));
        } catch (Throwable) {
            return self::defaultsPhp();
        }

        if (! \is_array($scalarsRaw) || \count($scalarsRaw) !== 4 || \count($parts) !== 10) {
            return self::defaultsPhp();
        }

        /** @var list<int> $scalars */
        $scalars = array_map(static fn (mixed $v): int => (int) $v, array_values($scalarsRaw));

        return new self(
            $parts[0],
            $parts[1],
            $scalars[0] !== 0 ? $parts[2] : null,
            $parts[3],
            $parts[4],
            $parts[5],
            $parts[6],
            $parts[7],
            $parts[8],
            $parts[9],
            $scalars[1] !== 0,
            $scalars[2],
            $scalars[3],
        );
    }

    public static function normalizeLogoPath(?string $siteLogo): ?string
    {
        return self::usesRustFfi() ? self::normalizeLogoPathNative($siteLogo) : self::normalizeLogoPathPhp($siteLogo);
    }

    public static function normalizeLogoPathPhp(?string $siteLogo): ?string
    {
        return $siteLogo === null || $siteLogo === '' ? null : $siteLogo;
    }

    public static function normalizeLogoPathNative(?string $siteLogo): ?string
    {
        $ffi = self::categorizeFfi();
        if ($ffi === null) {
            return self::normalizeLogoPathPhp($siteLogo);
        }

        $hasLogo = $siteLogo !== null ? 1 : 0;
        $logoStr = $siteLogo ?? '';
        $len = \strlen($logoStr);
        $cap = 0;
        $scratch = RustPipelineLibrary::obtainScratch($ffi, max(64, $len + 16), $cap);
        if ($scratch === null) {
            return self::normalizeLogoPathPhp($siteLogo);
        }

        try {
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_site_config_normalize_logo($hasLogo, $logoStr, $len, $scratch, $cap);
            if (\is_int($written) && $written < -1) {
                $scratch = RustPipelineLibrary::obtainScratch($ffi, -$written, $cap);
                if ($scratch === null) {
                    return self::normalizeLogoPathPhp($siteLogo);
                }
                // @phpstan-ignore method.notFound
                $written = $ffi->nntmux_site_config_normalize_logo($hasLogo, $logoStr, $len, $scratch, $cap);
            }
            if (! \is_int($written) || $written < 0 || $written > $cap) {
                return self::normalizeLogoPathPhp($siteLogo);
            }

            return $written === 0 ? null : FFI::string($scratch, $written);
        } catch (Throwable) {
            return self::normalizeLogoPathPhp($siteLogo);
        }
    }

    public static function fromModel(SiteConfiguration $model): self
    {
        return self::usesRustFfi() ? self::fromModelNative($model) : self::fromModelPhp($model);
    }

    public static function fromModelPhp(SiteConfiguration $model): self
    {
        return new self(
            (string) $model->title,
            (string) $model->home_link,
            self::normalizeLogoPathPhp($model->site_logo === null ? null : (string) $model->site_logo),
            (string) $model->strapline,
            (string) $model->meta_title,
            (string) $model->meta_description,
            (string) $model->meta_keywords,
            (string) $model->footer,
            (string) $model->dereferrer_link,
            (string) $model->terms,
            (bool) $model->trailers_display,
            (int) $model->trailers_size_x,
            (int) $model->trailers_size_y,
        );
    }

    public static function fromModelNative(SiteConfiguration $model): self
    {
        $ffi = self::categorizeFfi();
        $scalarsBuf = $ffi === null ? null : self::scalarsBuffer($ffi);
        if ($ffi === null || $scalarsBuf === null) {
            return self::fromModelPhp($model);
        }

        $logo = self::normalizeLogoPathNative($model->site_logo === null ? null : (string) $model->site_logo);

        try {
            // @phpstan-ignore method.notFound
            $rc = $ffi->nntmux_site_config_normalize_scalars(
                $logo !== null ? 1 : 0,
                (int) (bool) $model->trailers_display,
                (int) $model->trailers_size_x,
                (int) $model->trailers_size_y,
                $scalarsBuf,
                4
            );
            if ($rc !== 4) {
                return self::fromModelPhp($model);
            }
            $scalarsRaw = unpack('q4', FFI::string($scalarsBuf, 32));
        } catch (Throwable) {
            return self::fromModelPhp($model);
        }

        if (! \is_array($scalarsRaw) || \count($scalarsRaw) !== 4) {
            return self::fromModelPhp($model);
        }

        /** @var list<int> $scalars */
        $scalars = array_map(static fn (mixed $v): int => (int) $v, array_values($scalarsRaw));

        return new self(
            (string) $model->title,
            (string) $model->home_link,
            $scalars[0] !== 0 ? $logo : null,
            (string) $model->strapline,
            (string) $model->meta_title,
            (string) $model->meta_description,
            (string) $model->meta_keywords,
            (string) $model->footer,
            (string) $model->dereferrer_link,
            (string) $model->terms,
            $scalars[1] !== 0,
            $scalars[2],
            $scalars[3],
        );
    }

    /** @return array<string, string|int|bool|null> */
    public function toArray(): array
    {
        return self::usesRustFfi() ? $this->toArrayNative() : $this->toArrayPhp();
    }

    /** @return array<string, string|int|bool|null> */
    public function toArrayPhp(): array
    {
        return get_object_vars($this);
    }

    /** @return array<string, string|int|bool|null> */
    public function toArrayNative(): array
    {
        $ffi = self::categorizeFfi();
        $scalarsBuf = $ffi === null ? null : self::scalarsBuffer($ffi);
        if ($ffi === null || $scalarsBuf === null) {
            return $this->toArrayPhp();
        }

        try {
            // @phpstan-ignore method.notFound
            $rc = $ffi->nntmux_site_config_normalize_scalars(
                $this->logoPath !== null ? 1 : 0,
                (int) $this->trailersDisplay,
                $this->trailerWidth,
                $this->trailerHeight,
                $scalarsBuf,
                4
            );
            if ($rc !== 4) {
                return $this->toArrayPhp();
            }
            $scalarsRaw = unpack('q4', FFI::string($scalarsBuf, 32));
        } catch (Throwable) {
            return $this->toArrayPhp();
        }

        if (! \is_array($scalarsRaw) || \count($scalarsRaw) !== 4) {
            return $this->toArrayPhp();
        }

        /** @var list<int> $scalars */
        $scalars = array_map(static fn (mixed $v): int => (int) $v, array_values($scalarsRaw));

        return array_combine(self::FIELDS, [
            $this->title,
            $this->homeLink,
            $scalars[0] !== 0 ? $this->logoPath : null,
            $this->strapline,
            $this->metaTitle,
            $this->metaDescription,
            $this->metaKeywords,
            $this->footer,
            $this->dereferrerLink,
            $this->terms,
            $scalars[1] !== 0,
            $scalars[2],
            $scalars[3],
        ]);
    }

    private static function scalarsBuffer(FFI $ffi): ?CData
    {
        static $buffer = null;
        static $owner = null;
        if ($owner !== $ffi || $buffer === null) {
            try {
                $buffer = $ffi->new('int64_t[4]');
                $owner = $ffi;
            } catch (Throwable) {
                return null;
            }
        }

        return $buffer;
    }
}
