<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Category;
use App\Support\RustPipelineLibrary;
use FFI;
use Illuminate\View\View;
use InvalidArgumentException;
use Throwable;

class ApiHelpController extends BasePageController
{
    public const string FFI_CDEF = <<<'CDEF'
    int32_t nntmux_api_help_metadata(uint32_t version, size_t *out_lens, char *out, size_t cap);
    CDEF;

    public const int API_V1 = 1;

    public const int API_V2 = 2;

    private const string META_KEYWORDS = 'view,nzb,api,details,help,json,rss,atom';

    /**
     * Production default stays false until benchmark/parity sign-off; see
     * tests/Unit/RustApiV1ManticoreMediaIndexCommandsParityTest.php.
     */
    private static bool $useRustFfi = false;

    private static FFI|false|null $ffi = null;

    public function __construct()
    {
        parent::__construct();
    }

    /**
     * @throws \Exception
     */
    public function index(): View
    {
        $this->setAdminPrefs();

        $this->viewData = [...$this->viewData, ...self::helpViewMetadata(self::API_V1)];
        $this->viewData['catClass'] = Category::class;

        return view('api.apidesc', $this->viewData);
    }

    /**
     * @throws \Exception
     */
    public function apiv2(): View
    {
        $this->setAdminPrefs();

        $this->viewData = [...$this->viewData, ...self::helpViewMetadata(self::API_V2)];
        $this->viewData['catClass'] = Category::class;

        return view('api.apiv2desc', $this->viewData);
    }

    /**
     * Overrides the base page toggle for this controller's helpers; `null` restores the PHP default.
     */
    public static function setUseRustFfi(?bool $useRustFfi): void
    {
        self::$useRustFfi = $useRustFfi ?? false;
    }

    public static function usesRustFfi(): bool
    {
        return self::$useRustFfi;
    }

    public static function setFfiForTesting(FFI|false|null $ffi): void
    {
        self::$ffi = $ffi;
    }

    /**
     * Page title and meta tags for the API v1 or v2 help view.
     *
     * @return array{title: string, meta_title: string, meta_keywords: string, meta_description: string}
     *
     * @throws InvalidArgumentException for an unknown API version
     */
    public static function helpViewMetadata(int $version): array
    {
        return self::$useRustFfi ? self::helpViewMetadataNative($version) : self::helpViewMetadataPhp($version);
    }

    /**
     * @return array{title: string, meta_title: string, meta_keywords: string, meta_description: string}
     *
     * @throws InvalidArgumentException for an unknown API version
     */
    public static function helpViewMetadataPhp(int $version): array
    {
        return match ($version) {
            self::API_V1 => [
                'title' => 'Api Help',
                'meta_title' => 'Api Help Topics',
                'meta_keywords' => self::META_KEYWORDS,
                'meta_description' => 'View description of the site Nzb Api.',
            ],
            self::API_V2 => [
                'title' => 'Api V2 Help',
                'meta_title' => 'Api V2 Help Topics',
                'meta_keywords' => self::META_KEYWORDS,
                'meta_description' => 'View description of the site Nzb version 2 Api.',
            ],
            default => throw new InvalidArgumentException("Unknown API help version {$version}."),
        };
    }

    /**
     * @return array{title: string, meta_title: string, meta_keywords: string, meta_description: string}
     *
     * @throws InvalidArgumentException for an unknown API version
     */
    public static function helpViewMetadataNative(int $version): array
    {
        $ffi = self::ffi();
        if ($ffi === null || ($version !== self::API_V1 && $version !== self::API_V2)) {
            return self::helpViewMetadataPhp($version);
        }

        try {
            $capacity = 0;
            $out = RustPipelineLibrary::obtainScratch($ffi, 256, $capacity);
            if ($out === null) {
                return self::helpViewMetadataPhp($version);
            }
            $lens = $ffi->new('size_t[4]');
            $written = self::callFfi($ffi, 'nntmux_api_help_metadata', $version, $lens, $out, $capacity);
            if (! \is_int($written) || $written <= 0 || $written > $capacity) {
                return self::helpViewMetadataPhp($version);
            }

            $payload = FFI::string($out, $written);
            $parts = [];
            $offset = 0;
            for ($index = 0; $index < 4; $index++) {
                $length = (int) $lens[$index];
                $parts[] = substr($payload, $offset, $length);
                $offset += $length;
            }
            if ($offset !== $written) {
                return self::helpViewMetadataPhp($version);
            }

            return ['title' => $parts[0], 'meta_title' => $parts[1], 'meta_keywords' => $parts[2], 'meta_description' => $parts[3]];
        } catch (Throwable) {
            return self::helpViewMetadataPhp($version);
        }
    }

    private static function callFfi(FFI $ffi, string $symbol, mixed ...$args): mixed
    {
        return $ffi->$symbol(...$args);
    }

    private static function ffi(): ?FFI
    {
        if (self::$ffi === false) {
            return null;
        }

        if (self::$ffi instanceof FFI) {
            return self::$ffi;
        }

        $ffi = RustPipelineLibrary::resolveDomain('search', self::FFI_CDEF);
        self::$ffi = $ffi ?? false;

        return $ffi;
    }
}
