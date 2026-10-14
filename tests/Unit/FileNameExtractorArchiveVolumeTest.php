<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\NameFixing\Extractors\FileNameExtractor;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class FileNameExtractorArchiveVolumeTest extends TestCase
{
    #[DataProvider('supportedArchiveVolumes')]
    public function test_supported_archive_volume_preserves_complete_technical_title(string $suffix): void
    {
        $title = '[pcela] Homefront (2013) - [BD 1080p][H264 8bit][DTS 5 1][MULTI][English+Polish]';

        $result = (new FileNameExtractor)->extractFromFile($title.$suffix);

        $this->assertNotNull($result);
        $this->assertSame($title, $result->newName);
        $this->assertSame('Technical video basename', $result->method);
    }

    #[DataProvider('supportedArchiveVolumes')]
    public function test_supported_archive_volume_preserves_the_observed_archive_parent(string $suffix): void
    {
        $title = 'Canonical.Archive.Release-GRP';

        $result = (new FileNameExtractor)->extractFromFile($title.'/obfuscated'.$suffix);

        $this->assertNotNull($result);
        $this->assertSame($title, $result->newName);
        $this->assertSame('RAR archive path', $result->method);
    }

    /** @return array<string, array{string}> */
    public static function supportedArchiveVolumes(): array
    {
        return [
            'two-digit lower boundary' => ['.r00'],
            'three-digit upper boundary' => ['.r999'],
            'four-digit lower boundary' => ['.r1000'],
            'four-digit upper boundary' => ['.r9999'],
        ];
    }

    #[DataProvider('unsupportedArchiveVolumes')]
    public function test_unsupported_suffix_does_not_recover_the_technical_title_or_archive_parent(string $suffix): void
    {
        $title = '[pcela] Homefront (2013) - [BD 1080p][H264 8bit][DTS 5 1][MULTI][English+Polish]';
        $extractor = new FileNameExtractor;

        $technical = $extractor->extractFromFile($title.$suffix);
        $archive = $extractor->extractFromFile('Canonical.Archive.Release-GRP/obfuscated'.$suffix);

        $this->assertNotSame($title, $technical?->newName);
        $this->assertNotSame('Technical video basename', $technical?->method);
        $this->assertNotSame('Canonical.Archive.Release-GRP', $archive?->newName);
        $this->assertNotSame('RAR archive path', $archive?->method);
        $this->assertNotSame('Scene RAR release', $archive?->method);
    }

    /** @return array<string, array{string}> */
    public static function unsupportedArchiveVolumes(): array
    {
        return [
            'one digit' => ['.r9'],
            'five digits' => ['.r10000'],
            'non-numeric volume' => ['.r100a'],
            'trailing executable suffix' => ['.r1000.exe'],
        ];
    }

    public function test_four_digit_volume_does_not_bypass_technical_title_plausibility(): void
    {
        $title = 'Readme.2026.1080p';

        $result = (new FileNameExtractor)->extractFromFile($title.'.r1000');

        $this->assertNotSame($title, $result?->newName);
        $this->assertNotSame('Technical video basename', $result?->method);
    }
}
