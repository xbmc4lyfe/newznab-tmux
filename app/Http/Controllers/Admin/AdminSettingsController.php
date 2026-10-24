<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Enums\ConfigurationDomain;
use App\Http\Controllers\BasePageController;
use App\Http\Requests\Admin\UpdateDomainConfigurationRequest;
use App\Models\TmuxConfiguration;
use App\Services\Configuration\ConfigurationProvider;
use App\Services\Configuration\DomainConfigurationRepository;
use App\Services\SiteLogoService;
use App\Support\Configuration\SettingsPageCatalog;
use App\Support\SizeUnit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Throwable;

final class AdminSettingsController extends BasePageController
{
    // Keep PHP as the production default until the Rust performance gate passes.
    private static bool $useRustFfi = false;

    public static function setUseRustFfi(?bool $useRustFfi): void
    {
        self::$useRustFfi = $useRustFfi ?? false;
    }

    public static function usesRustFfi(): bool
    {
        return self::$useRustFfi;
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    public static function normalizeBinaryModes(array $attributes): array
    {
        return self::$useRustFfi ? self::normalizeBinaryModesNative($attributes) : self::normalizeBinaryModesPhp($attributes);
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    public static function normalizeBinaryModesPhp(array $attributes): array
    {
        foreach (['post_non_mode', 'post_amazon_mode'] as $column) {
            if (array_key_exists($column, $attributes)) {
                $attributes[$column] = (int) $attributes[$column] > 0 ? 1 : 0;
            }
        }

        return $attributes;
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    public static function normalizeBinaryModesNative(array $attributes): array
    {
        $result = AdminPaymentController::transformNative(0, [$attributes]);

        return is_array($result) ? $result : self::normalizeBinaryModesPhp($attributes);
    }

    /** @return array<string, string|null> */
    public static function resolveSensitiveField(string $column, bool $clear, bool $filled, string $value): array
    {
        return self::$useRustFfi ? self::resolveSensitiveFieldNative($column, $clear, $filled, $value) : self::resolveSensitiveFieldPhp($column, $clear, $filled, $value);
    }

    /** @return array<string, string|null> */
    public static function resolveSensitiveFieldPhp(string $column, bool $clear, bool $filled, string $value): array
    {
        if ($clear) {
            return [$column => null];
        }

        return $filled ? [$column => $value] : [];
    }

    /** @return array<string, string|null> */
    public static function resolveSensitiveFieldNative(string $column, bool $clear, bool $filled, string $value): array
    {
        $result = AdminPaymentController::transformNative(1, [$column, $clear, $filled, $value]);

        return is_array($result) ? $result : self::resolveSensitiveFieldPhp($column, $clear, $filled, $value);
    }

    public static function coerceNullableString(string $column, mixed $value): mixed
    {
        return self::$useRustFfi ? self::coerceNullableStringNative($column, $value) : self::coerceNullableStringPhp($column, $value);
    }

    public static function coerceNullableStringPhp(string $column, mixed $value): mixed
    {
        return $value === null && in_array($column, ['terms', 'dereferrer_link'], true) ? '' : $value;
    }

    public static function coerceNullableStringNative(string $column, mixed $value): mixed
    {
        $result = AdminPaymentController::transformNative(2, [$column, $value]);
        $fallback = self::coerceNullableStringPhp($column, $value);

        return get_debug_type($result) === get_debug_type($fallback) ? $result : $fallback;
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array{attributes: array<string, mixed>, movie: array<string, mixed>, tmux: array<string, mixed>}
     */
    public static function partitionAttributes(array $attributes, bool $postProcessing): array
    {
        return self::$useRustFfi ? self::partitionAttributesNative($attributes, $postProcessing) : self::partitionAttributesPhp($attributes, $postProcessing);
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array{attributes: array<string, mixed>, movie: array<string, mixed>, tmux: array<string, mixed>}
     */
    public static function partitionAttributesPhp(array $attributes, bool $postProcessing): array
    {
        $movie = $postProcessing ? array_intersect_key($attributes, array_flip(SettingsPageCatalog::MOVIE_PROCESSING_COLUMNS)) : [];
        $tmux = $postProcessing ? array_intersect_key($attributes, array_flip(SettingsPageCatalog::VIDEO_PANE_COLUMNS)) : [];

        return ['attributes' => array_diff_key($attributes, $movie, $tmux), 'movie' => $movie, 'tmux' => $tmux];
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array{attributes: array<string, mixed>, movie: array<string, mixed>, tmux: array<string, mixed>}
     */
    public static function partitionAttributesNative(array $attributes, bool $postProcessing): array
    {
        $result = AdminPaymentController::transformNative(3, [$attributes, $postProcessing, SettingsPageCatalog::MOVIE_PROCESSING_COLUMNS, SettingsPageCatalog::VIDEO_PANE_COLUMNS]);

        return is_array($result) && isset($result['attributes'], $result['movie'], $result['tmux'])
            && is_array($result['attributes']) && is_array($result['movie']) && is_array($result['tmux'])
            ? $result : self::partitionAttributesPhp($attributes, $postProcessing);
    }

    public function __construct(
        private readonly SettingsPageCatalog $catalog,
        private readonly DomainConfigurationRepository $repository,
        private readonly ConfigurationProvider $configurationProvider,
        private readonly SiteLogoService $siteLogoService,
    ) {
        parent::__construct();
    }

    public function show(string $domain): View
    {
        $configurationDomain = ConfigurationDomain::from($domain);
        $modelClass = $this->catalog->modelClass($configurationDomain);
        $model = $modelClass::query()->findOrFail(1);
        $configurationValues = $model->attributesToArray();

        if ($configurationDomain === ConfigurationDomain::PostProcessing) {
            $metadata = $this->configurationProvider->metadata(fresh: true);
            $configurationValues['movie_lookup'] = $metadata->movieLookup->value;
            $configurationValues['max_movies_processed'] = $metadata->maxMoviesProcessed;
            $configurationValues['post_non_mode'] = $this->configurationProvider->tmux(fresh: true)->postNonMode;
        }

        $configurationValues = self::normalizeBinaryModes($configurationValues);

        return view('admin.settings.show', [
            ...$this->viewData,
            'domain' => $configurationDomain,
            'domains' => ConfigurationDomain::cases(),
            'fields' => $this->catalog->fieldsForPage($configurationDomain),
            'configuration' => $model,
            'configurationValues' => $configurationValues,
            'sizeUnits' => SizeUnit::UNITS,
            'sizeValues' => $this->sizeValues($configurationDomain, $model->getAttributes()),
            'selectedCleanupRules' => $model instanceof TmuxConfiguration ? $model->cleanupRules()->pluck('rule')->all() : [],
            'selectedColorExclusions' => $model instanceof TmuxConfiguration ? $model->colorExclusions()->pluck('color')->all() : [],
            'title' => $configurationDomain->label().' Settings',
            'meta_title' => $configurationDomain->label().' Settings',
        ]);
    }

    public function legacySite(): RedirectResponse
    {
        return redirect()->route('admin.settings.show', ['domain' => ConfigurationDomain::Site->value]);
    }

    public function legacyTmux(): RedirectResponse
    {
        return redirect()->route('admin.settings.show', ['domain' => ConfigurationDomain::Tmux->value]);
    }

    public function update(UpdateDomainConfigurationRequest $request, string $domain): RedirectResponse
    {
        $configurationDomain = ConfigurationDomain::from($domain);
        $attributes = [];
        $storedLogoPath = null;
        $currentLogoPath = $configurationDomain === ConfigurationDomain::Site
            ? $this->configurationProvider->site(fresh: true)->logoPath
            : null;

        foreach ($this->catalog->fieldsForPage($configurationDomain) as $field) {
            if ($configurationDomain === ConfigurationDomain::PostProcessing
                && in_array($field->column, [...SettingsPageCatalog::MOVIE_PROCESSING_COLUMNS, ...SettingsPageCatalog::VIDEO_PANE_COLUMNS], true)
                && ! $request->exists($field->column)) {
                continue;
            }

            if (in_array($field->column, ['cleanup_rules', 'color_exclusions', 'site_logo'], true)) {
                continue;
            }

            if ($field->sensitive) {
                $clear = $request->boolean('clear_'.$field->column);
                $filled = $request->filled($field->column);
                $attributes = array_replace($attributes, self::resolveSensitiveField(
                    $field->column,
                    $clear,
                    $filled,
                    ! $clear && $filled ? $request->string($field->column)->toString() : '',
                ));

                continue;
            }

            $value = self::coerceNullableString($field->column, $request->validated($field->column));
            $attributes[$field->column] = $field->control === 'bytes'
                ? SizeUnit::toBytes($value, (string) $request->validated($field->column.'_unit'))
                : $value;
        }

        if ($configurationDomain === ConfigurationDomain::Site) {
            $uploadedLogo = $request->file('site_logo');
            if ($uploadedLogo instanceof UploadedFile) {
                $storedLogoPath = $this->siteLogoService->store($uploadedLogo);
                $attributes['site_logo'] = $storedLogoPath;
            } elseif ($request->boolean('remove_site_logo')) {
                $attributes['site_logo'] = null;
            }
        }

        try {
            DB::transaction(function () use ($configurationDomain, $attributes, $request): void {
                $partition = self::partitionAttributes($attributes, $configurationDomain === ConfigurationDomain::PostProcessing);
                $movieAttributes = $partition['movie'];
                $tmuxAttributes = $partition['tmux'];
                $this->repository->update(
                    $configurationDomain,
                    $partition['attributes'],
                    $configurationDomain === ConfigurationDomain::Tmux ? array_values($request->validated('cleanup_rules', [])) : null,
                    $configurationDomain === ConfigurationDomain::Tmux ? $request->colorExclusions() : null,
                );

                if ($movieAttributes !== []) {
                    $this->repository->update(ConfigurationDomain::Metadata, $movieAttributes);
                }

                if ($tmuxAttributes !== []) {
                    $this->repository->update(ConfigurationDomain::Tmux, $tmuxAttributes);
                }
            });
        } catch (Throwable $throwable) {
            if ($storedLogoPath !== null) {
                $this->siteLogoService->delete($storedLogoPath);
            }

            throw $throwable;
        }

        if ($configurationDomain === ConfigurationDomain::Site && array_key_exists('site_logo', $attributes) && $currentLogoPath !== $attributes['site_logo']) {
            $this->siteLogoService->delete($currentLogoPath);
        }

        return redirect()->route('admin.settings.show', ['domain' => $configurationDomain->value])
            ->with('success', $configurationDomain->label().' settings updated successfully.');
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, array{value: int|float, unit: string}>
     */
    private function sizeValues(ConfigurationDomain $domain, array $attributes): array
    {
        $values = [];
        foreach ($this->catalog->fields($domain) as $field) {
            if ($field->control === 'bytes') {
                $values[$field->column] = SizeUnit::fromBytes($attributes[$field->column] ?? 0);
            }
        }

        return $values;
    }
}
