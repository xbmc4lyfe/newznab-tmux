<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\HtmlString;
use JsonException;

/** Presentation only. Laravel owns release selection, routes and authorization. */
final class AdminReleaseNames
{
    // Per-request native binding costs more than PHP for tiny result batches.
    public const int MIN_NATIVE_ROWS = 16;

    /**
     * @param  iterable<object>  $releases
     * @return list<HtmlString>
     */
    public function render(iterable $releases, bool $native = true): array
    {
        $rows = [];
        foreach ($releases as $release) {
            $rows[] = [
                'searchname' => $release->searchname,
                'name' => $release->name,
                'guid' => $release->guid,
            ];
        }
        if ($rows === []) {
            return [];
        }

        // Only the dependency-free, preloaded wrapper may access the native scope.
        if (count($rows) >= self::MIN_NATIVE_ROWS && $native && function_exists('nntmux_render_release_names')) {
            try {
                // Laravel resolves host, deployment prefix and route defaults once.
                // Rust only expands unreserved GUID segments; unusual GUIDs fall back.
                $marker = '__NNTMUX_RELEASE_GUID__';
                $template = route('details', ['guid' => $marker]);
                if (substr_count($template, $marker) !== 1) {
                    throw new JsonException('Ambiguous release URL template.');
                }
                [$prefix, $suffix] = explode($marker, $template, 2);
                $input = json_encode(['url_prefix' => $prefix, 'url_suffix' => $suffix, 'releases' => $rows], JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
                $html = \nntmux_render_release_names($input);
                if (is_array($html) && array_is_list($html) && count($html) === count($rows)
                    && count(array_filter($html, is_string(...))) === count($rows)) {
                    return array_map(static fn (string $item): HtmlString => new HtmlString($item), $html);
                }
            } catch (JsonException) {
                // Invalid native output or unavailable scope must never break navigation.
            }
        }

        return array_map(static function (array $row): HtmlString {
            $display = e(ReleaseNameDisplay::clean($row['searchname']));
            $original = e(ReleaseNameDisplay::clean($row['name']));
            $html = '<a href="'.e(route('details', ['guid' => $row['guid']])).'" class="text-gray-900 dark:text-gray-100 font-medium max-w-md wrap-break-word break-all block hover:text-blue-600 dark:hover:text-blue-400 hover:underline focus-visible:underline" title="'.$display.'">'.$display.'</a>';
            if ($original !== '' && $original !== $display) {
                $html .= '<div class="text-gray-500 dark:text-gray-400 text-xs mt-1 max-w-md truncate" title="'.$original.'">'.$original.'</div>';
            }

            return new HtmlString($html);
        }, $rows);
    }
}
