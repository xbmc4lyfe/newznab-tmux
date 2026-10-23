<?php

declare(strict_types=1);

namespace App\Services\AdditionalProcessing\Media;

use App\Support\RustPipelineLibrary;

/**
 * Orders the images of a release by how well they show what it contains.
 *
 * Screen captures and contact sheets show the content itself, covers come next,
 * and scans of disc labels, backs, proofs and banners are kept for last.
 */
final class ImageCandidateRanker
{
    private const string CONTENT = '/(?:^|[^a-z])(?:screens?|screenshots?|screencaps?|caps|contact[ ._-]?sheets?|sheet|thumbs?|thumbnails?|previews?|samples?)(?:[^a-z]|$)/i';

    private const string COVER = '/(?:^|[^a-z])(?:cover|front|poster|folder|box|keyart)(?:[^a-z]|$)/i';

    private const string LEAST = '/(?:^|[^a-z])(?:back|rear|cd\d*|dvd\d*|disc\d*|disk\d*|label|inlay|insert|proof|logo|banner|avatar|sig|signature|spine)(?:[^a-z]|$)/i';

    /**
     * Lower is better.
     */
    public static function rank(string $name, ?string $libraryPath = null): int
    {
        $rust = RustPipelineLibrary::imageCandidateRank($name, $libraryPath);
        if ($rust !== null) {
            return $rust;
        }

        return self::rankPhp($name);
    }

    public static function rankPhp(string $name): int
    {
        $name = self::fileName($name);

        // "CD1.screens.jpg" is a contact sheet; "cover.back.jpg" is not a front cover.
        return match (true) {
            preg_match(self::CONTENT, $name) === 1 => 0,
            preg_match(self::LEAST, $name) === 1 => 3,
            preg_match(self::COVER, $name) === 1 => 1,
            default => 2,
        };
    }

    /**
     * Sort by rank, then larger (higher resolution) first.
     *
     * @template T
     *
     * @param  list<T>  $items
     * @param  callable(T): string  $nameOf
     * @param  callable(T): int  $sizeOf
     * @return list<T>
     */
    public static function sort(array $items, callable $nameOf, callable $sizeOf, ?string $libraryPath = null): array
    {
        $count = count($items);
        if ($count <= 1) {
            return array_values($items);
        }

        $ranks = [];
        $negSizes = [];
        $order = [];
        $list = array_values($items);
        foreach ($list as $index => $item) {
            $ranks[$index] = self::rank($nameOf($item), $libraryPath);
            $negSizes[$index] = -$sizeOf($item);
            $order[$index] = $index;
        }

        array_multisort($ranks, SORT_ASC, $negSizes, SORT_ASC, $order, SORT_ASC, $list);

        return $list;
    }

    /**
     * @template T
     *
     * @param  list<T>  $items
     * @param  callable(T): string  $nameOf
     * @param  callable(T): int  $sizeOf
     * @return list<T>
     */
    public static function sortPhp(array $items, callable $nameOf, callable $sizeOf): array
    {
        usort($items, static fn (mixed $a, mixed $b): int => [self::rankPhp($nameOf($a)), -$sizeOf($a)] <=> [self::rankPhp($nameOf($b)), -$sizeOf($b)]);

        return $items;
    }

    /**
     * The file name inside a yEnc subject, or the base name of a path.
     */
    public static function fileName(string $name): string
    {
        if (preg_match('/"([^"]+)"/', $name, $quoted) === 1) {
            $name = $quoted[1];
        }

        return basename(str_replace('\\', '/', trim($name)));
    }
}
