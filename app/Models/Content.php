<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\UserRole;
use App\Support\RustPipelineLibrary;
use FFI;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Throwable;

/**
 * App\Models\Content.
 *
 * @property int $id
 * @property string $title
 * @property string|null $url
 * @property string|null $body
 * @property string $metadescription
 * @property string $metakeywords
 * @property int $contenttype
 * @property int $status
 * @property int|null $ordinal
 * @property int $role
 *
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\Content whereBody($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\Content whereContenttype($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\Content whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\Content whereMetadescription($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\Content whereMetakeywords($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\Content whereOrdinal($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\Content whereRole($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\Content whereStatus($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\Content whereTitle($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\Content whereUrl($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\Content active()
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\Content ofType($type)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\Content forRole($role)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\Content frontPage()
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\Content ordered()
 *
 * @mixin \Eloquent
 *
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\Content newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\Content newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\Content query()
 */
class Content extends Model
{
    // Content type constants
    public const TYPE_USEFUL = 1;

    public const TYPE_INDEX = 3;

    // Status constants
    public const STATUS_ENABLED = 1;

    public const STATUS_DISABLED = 0;

    // Role constants (aligned with User roles)
    public const ROLE_EVERYONE = 0;

    public const ROLE_LOGGED_IN = 1;

    public const ROLE_ADMIN = 2;

    // URL classifications returned by classifyUrl()
    public const URL_EMPTY = 0;

    public const URL_ABSOLUTE = 1;

    public const URL_BARE_DOMAIN = 2;

    public const URL_INTERNAL = 3;

    public const string FFI_CDEF = <<<'C'
        ssize_t nntmux_content_label(int32_t kind, int32_t has_value, int64_t value, char *out, size_t cap);
        int32_t nntmux_content_url_kind(const char *ptr, size_t len);
        C;

    // PHP remains the production default until benchmarks qualify Rust.
    private static ?bool $useRustFfi = null;

    private static FFI|false|null $ffi = null;

    /**
     * @var string
     */
    protected $table = 'content';

    /**
     * @var bool
     */
    public $timestamps = false;

    protected $dateFormat = false;

    /**
     * @var array<string>
     */
    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'contenttype' => 'integer',
            'status' => 'integer',
            'ordinal' => 'integer',
            'role' => 'integer',
        ];
    }

    /**
     * Scope: Get only active content.
     *
     * @param  Builder<Content>  $query
     * @return Builder<Content>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_ENABLED);
    }

    /**
     * Scope: Get content of a specific type.
     *
     * @param  Builder<Content>  $query
     * @return Builder<Content>
     */
    public function scopeOfType(Builder $query, int $type): Builder
    {
        return $query->where('contenttype', $type);
    }

    /**
     * Scope: Get content accessible by a specific role.
     *
     * @param  Builder<Content>  $query
     * @return Builder<Content>
     */
    public function scopeForRole(Builder $query, int $role): Builder
    {
        // Admins and moderators can see everything
        if (\in_array($role, [UserRole::ADMIN->value, UserRole::MODERATOR->value], true)) {
            return $query;
        }

        // Others can only see content for their role or everyone
        return $query->where(function ($q) use ($role) {
            $q->where('role', self::ROLE_EVERYONE)
                ->orWhere('role', $role);
        });
    }

    /**
     * Scope: Order content by type and ordinal within each type.
     *
     * @param  Builder<Content>  $query
     * @return Builder<Content>
     */
    public function scopeOrdered(Builder $query): Builder
    {
        return $query
            ->orderBy('contenttype')
            ->orderByRaw('COALESCE(ordinal, 1000000) ASC, id ASC');
    }

    /**
     * Scope: Get front page content.
     *
     * @param  Builder<Content>  $query
     * @return Builder<Content>
     */
    public function scopeFrontPage(Builder $query): Builder
    {
        return $query->active()
            ->ofType(self::TYPE_INDEX)
            ->ordered();
    }

    /**
     * Get content type label.
     */
    public function getContentTypeLabel(): string
    {
        return self::contentTypeLabelFor($this->contenttype);
    }

    /**
     * Get role label.
     */
    public function getRoleLabel(): string
    {
        return self::roleLabelFor($this->role);
    }

    /**
     * Check if content is active.
     */
    public function isActive(): bool
    {
        return $this->status === self::STATUS_ENABLED;
    }

    /**
     * Check if content is a homepage type.
     */
    public function isHomepage(): bool
    {
        return $this->contenttype === self::TYPE_INDEX;
    }

    /**
     * Resolve the content URL: external URLs are used as-is (domains without
     * protocol get https://), internal paths are prefixed with the site URL.
     *
     * @return Attribute<string|null, never>
     */
    protected function resolvedUrl(): Attribute
    {
        return Attribute::make(
            get: function (): ?string {
                $url = $this->url;

                return match (self::classifyUrl($url)) {
                    self::URL_EMPTY => null,
                    self::URL_ABSOLUTE => $url,
                    // Domain pattern without protocol (e.g. google.com, chatgpt.ai)
                    self::URL_BARE_DOMAIN => 'https://'.$url,
                    default => url((string) $url),
                };
            }
        );
    }

    /**
     * Whether the content URL points to an external site.
     *
     * @return Attribute<bool, never>
     */
    protected function isExternalUrl(): Attribute
    {
        return Attribute::make(
            get: fn (): bool => \in_array(self::classifyUrl($this->url), [self::URL_ABSOLUTE, self::URL_BARE_DOMAIN], true)
        );
    }

    public static function enableRustFfi(): void
    {
        self::$useRustFfi = true;
    }

    public static function disableRustFfi(): void
    {
        self::$useRustFfi = false;
    }

    /**
     * Restore the production default and re-resolve the library on next use.
     */
    public static function resetRustFfi(): void
    {
        self::$useRustFfi = null;
        self::$ffi = null;
    }

    public static function usesRustFfi(): bool
    {
        return self::$useRustFfi ?? false;
    }

    public static function setFfiForTesting(FFI|false|null $override = null): void
    {
        self::$ffi = $override;
    }

    /**
     * Admin label for a content type.
     */
    public static function contentTypeLabelFor(?int $type): string
    {
        return self::usesRustFfi() ? self::contentTypeLabelForNative($type) : self::contentTypeLabelForPhp($type);
    }

    public static function contentTypeLabelForPhp(?int $type): string
    {
        return match ($type) {
            self::TYPE_USEFUL => 'Useful Link',
            self::TYPE_INDEX => 'Homepage',
            default => 'Unknown',
        };
    }

    public static function contentTypeLabelForNative(?int $type): string
    {
        return self::labelNative(0, $type) ?? self::contentTypeLabelForPhp($type);
    }

    /**
     * Admin label for a content visibility role.
     */
    public static function roleLabelFor(?int $role): string
    {
        return self::usesRustFfi() ? self::roleLabelForNative($role) : self::roleLabelForPhp($role);
    }

    public static function roleLabelForPhp(?int $role): string
    {
        return match ($role) {
            self::ROLE_EVERYONE => 'Everyone',
            self::ROLE_LOGGED_IN => 'Logged in Users',
            self::ROLE_ADMIN => 'Admins',
            default => 'Unknown',
        };
    }

    public static function roleLabelForNative(?int $role): string
    {
        return self::labelNative(1, $role) ?? self::roleLabelForPhp($role);
    }

    /**
     * One of the URL_* classifications: empty, absolute http(s), a bare domain that gets
     * `https://`, or an internal path resolved against the site URL.
     */
    public static function classifyUrl(?string $url): int
    {
        return self::usesRustFfi() ? self::classifyUrlNative($url) : self::classifyUrlPhp($url);
    }

    public static function classifyUrlPhp(?string $url): int
    {
        if (empty($url)) {
            return self::URL_EMPTY;
        }

        if (str_starts_with($url, 'http://') || str_starts_with($url, 'https://')) {
            return self::URL_ABSOLUTE;
        }

        if (! str_starts_with($url, '/') && preg_match('/^[a-zA-Z0-9][a-zA-Z0-9-]*\.[a-zA-Z]{2,}/', $url)) {
            return self::URL_BARE_DOMAIN;
        }

        return self::URL_INTERNAL;
    }

    public static function classifyUrlNative(?string $url): int
    {
        if (($ffi = self::ffi()) === null) {
            return self::classifyUrlPhp($url);
        }
        try {
            // @phpstan-ignore method.notFound
            $kind = $ffi->nntmux_content_url_kind($url, $url === null ? 0 : \strlen($url));
        } catch (Throwable) {
            return self::classifyUrlPhp($url);
        }

        return \is_int($kind) && $kind >= self::URL_EMPTY && $kind <= self::URL_INTERNAL ? $kind : self::classifyUrlPhp($url);
    }

    private static function labelNative(int $kind, ?int $value): ?string
    {
        if (($ffi = self::ffi()) === null) {
            return null;
        }
        $capacity = 0;
        $buffer = RustPipelineLibrary::obtainScratch($ffi, 32, $capacity);
        if ($buffer === null) {
            return null;
        }
        try {
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_content_label($kind, $value === null ? 0 : 1, $value ?? 0, $buffer, $capacity);
            if (! \is_int($written) || $written <= 0 || $written > $capacity) {
                return null;
            }

            return FFI::string($buffer, $written);
        } catch (Throwable) {
            return null;
        }
    }

    private static function ffi(): ?FFI
    {
        if (self::$ffi === null) {
            self::$ffi = RustPipelineLibrary::resolveDomain('additional', self::FFI_CDEF) ?? false;
        }

        return self::$ffi === false ? null : self::$ffi;
    }
}
