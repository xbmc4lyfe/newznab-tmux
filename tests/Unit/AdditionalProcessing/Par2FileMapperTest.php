<?php

declare(strict_types=1);

namespace Tests\Unit\AdditionalProcessing;

use App\Services\AdditionalProcessing\DTO\Par2FileEntry;
use App\Services\AdditionalProcessing\Enums\MappingConfidence;
use App\Services\AdditionalProcessing\Media\Par2FileMapper;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class Par2FileMapperTest extends TestCase
{
    #[Test]
    public function it_names_files_whose_posted_size_fits_only_one_par2_entry(): void
    {
        $mapped = (new Par2FileMapper)->map(
            [$this->entry(3000), $this->entry(50000), $this->entry(60000)],
            [new Par2FileEntry('Movie.sample.mkv', 2900), new Par2FileEntry('Movie.mkv', 48500)],
        );

        $this->assertSame([0, 1], array_keys($mapped));
        $this->assertSame('Movie.sample.mkv', $mapped[0]->file->name);
        $this->assertSame('Movie.mkv', $mapped[1]->file->name);
        $this->assertSame(MappingConfidence::UniqueSize, $mapped[1]->confidence);
    }

    #[Test]
    public function it_leaves_equal_sized_volumes_unnamed(): void
    {
        $mapped = (new Par2FileMapper)->map(
            [$this->entry(51000), $this->entry(51000)],
            [new Par2FileEntry('Movie.part01.rar', 50000), new Par2FileEntry('Movie.part02.rar', 50000)],
        );

        $this->assertSame([], $mapped);
    }

    #[Test]
    public function it_names_the_remaining_volume_once_the_others_were_verified(): void
    {
        $part01 = new Par2FileEntry('Movie.part01.rar', 50000);
        $part02 = new Par2FileEntry('Movie.part02.rar', 50000);

        $mapped = (new Par2FileMapper)->map(
            [$this->entry(51000), $this->entry(51000)],
            [$part01, $part02],
            verified: [0 => $part02],
        );

        $this->assertSame($part02, $mapped[0]->file);
        $this->assertSame(MappingConfidence::Verified, $mapped[0]->confidence);
        $this->assertSame($part01, $mapped[1]->file);
        $this->assertSame(MappingConfidence::UniqueSize, $mapped[1]->confidence);
    }

    #[Test]
    public function it_requires_the_segment_count_to_fit_when_the_article_size_is_known(): void
    {
        $nzb = [$this->entry(10400, segments: 3), $this->entry(10400, segments: 2)];
        $file = new Par2FileEntry('Movie.sample.mkv', 10000);

        $this->assertSame([], (new Par2FileMapper)->map($nzb, [$file]));
        $this->assertSame([1], array_keys((new Par2FileMapper)->map($nzb, [$file], articlePartSize: 5000)));
    }

    #[Test]
    public function it_ignores_entries_that_merge_several_posted_files(): void
    {
        $mapped = (new Par2FileMapper)->map(
            [$this->entry(3000, fileCount: 2)],
            [new Par2FileEntry('Movie.sample.mkv', 2900)],
        );

        $this->assertSame([], $mapped);
    }

    #[Test]
    public function it_preserves_inclusive_size_ratio_thresholds_and_adjacent_bytes(): void
    {
        $mapper = new Par2FileMapper;
        // One fixed posted size makes every boundary an exact integer-byte fixture.
        foreach ([89999 => false, 90000 => true, 90001 => true,
            100099 => true, 100100 => true, 100101 => false] as $size => $fits) {
            $file = new Par2FileEntry('Movie.sample.mkv', $size);
            $nzb = [$this->entry(100000)];
            $this->assertSame($fits ? [0] : [], $mapper->candidateIndexes($nzb, $file), 'Decoded size '.$size);
            $mapped = $mapper->map($nzb, [$file]);
            $this->assertSame($fits ? [0] : [], array_keys($mapped), 'Decoded size '.$size);
            if ($fits) {
                $this->assertSame($file, $mapped[0]->file);
                $this->assertSame(MappingConfidence::UniqueSize, $mapped[0]->confidence);
            }
        }
    }

    #[Test]
    public function it_keeps_both_size_ratio_boundaries_ambiguous_in_both_directions(): void
    {
        $mapper = new Par2FileMapper;
        foreach ([90000, 100100] as $size) {
            $first = new Par2FileEntry('Movie.part01.rar', $size);
            $second = new Par2FileEntry('Movie.part02.rar', $size);
            $entry = $this->entry(100000);
            // Two descriptor identities fitting one source must not select a name.
            $this->assertSame([], $mapper->map([$entry], [$first, $second]));
            // One descriptor fitting two sources must not select a source.
            $this->assertSame([], $mapper->map([$entry, $this->entry(100000)], [$first]));
        }
    }

    #[Test]
    public function it_applies_known_article_part_size_at_each_ratio_boundary(): void
    {
        $mapper = new Par2FileMapper;
        foreach ([90000 => 3, 100100 => 4] as $size => $segments) {
            $file = new Par2FileEntry('Movie.sample.mkv', $size);
            $nzb = [$this->entry(100000, segments: $segments),
                $this->entry(100000, segments: $segments + 1)];
            $this->assertSame([0, 1], $mapper->candidateIndexes($nzb, $file));
            $this->assertSame([0], $mapper->candidateIndexes($nzb, $file, articlePartSize: 30000));
            $mapped = $mapper->map($nzb, [$file], articlePartSize: 30000);
            $this->assertSame([0], array_keys($mapped));
            $this->assertSame($file, $mapped[0]->file);
            $this->assertSame(MappingConfidence::UniqueSize, $mapped[0]->confidence);
        }
    }

    #[Test]
    public function it_preserves_source_indexes_when_boundary_entries_are_permuted(): void
    {
        $mapper = new Par2FileMapper;
        $lower = new Par2FileEntry('Movie.part01.rar', 90000);
        $upper = new Par2FileEntry('Movie.part02.rar', 100100);
        $threeParts = $this->entry(100000, segments: 3);
        $fourParts = $this->entry(100000, segments: 4);
        foreach ([false, true] as $reverseSources) {
            $nzb = $reverseSources ? [$fourParts, $threeParts] : [$threeParts, $fourParts];
            foreach ([[$lower, $upper], [$upper, $lower]] as $files) {
                $mapped = $mapper->map($nzb, $files, articlePartSize: 30000);
                $this->assertSame([0, 1], array_keys($mapped));
                $this->assertSame($reverseSources ? $upper : $lower, $mapped[0]->file);
                $this->assertSame($reverseSources ? $lower : $upper, $mapped[1]->file);
                foreach ($mapped as $mapping) {
                    $this->assertSame(MappingConfidence::UniqueSize, $mapping->confidence);
                }
            }
        }
    }

    #[Test]
    public function it_propagates_object_to_integer_conversion_warnings(): void
    {
        foreach (['size', 'filecount'] as $field) {
            $entry = $this->entry(51000);
            $entry[$field] = new \stdClass;
            $caught = null;
            set_error_handler(static function (int $severity, string $message, string $file, int $line): never {
                throw new \ErrorException($message, 0, $severity, $file, $line);
            }, E_WARNING);
            try {
                (new Par2FileMapper)->map([$entry], [new Par2FileEntry('Movie.rar', 50000)]);
            } catch (\ErrorException $exception) {
                $caught = $exception;
            } finally {
                restore_error_handler();
            }
            $this->assertInstanceOf(\ErrorException::class, $caught, $field);
            $this->assertSame(E_WARNING, $caught->getSeverity());
            $this->assertStringContainsString('Object of class stdClass could not be converted to int', $caught->getMessage());
        }
    }

    #[Test]
    public function it_excludes_verified_descriptors_by_object_identity_rather_than_value(): void
    {
        $verified = new Par2FileEntry('Movie.rar', 50000);
        $equalButDistinct = new Par2FileEntry('Movie.rar', 50000);
        $this->assertNotSame($verified, $equalButDistinct);
        $mapped = (new Par2FileMapper)->map(
            [$this->entry(51000), $this->entry(51000)],
            [$verified, $equalButDistinct],
            verified: [0 => $verified],
        );
        $this->assertSame([0, 1], array_keys($mapped));
        $this->assertSame($verified, $mapped[0]->file);
        $this->assertSame(MappingConfidence::Verified, $mapped[0]->confidence);
        $this->assertSame($equalButDistinct, $mapped[1]->file);
        $this->assertSame(MappingConfidence::UniqueSize, $mapped[1]->confidence);
    }

    #[Test]
    public function it_preserves_verified_precedence_despite_incompatible_size_and_segments(): void
    {
        $file = new Par2FileEntry('Movie.rar', 50000);
        $nzb = [$this->entry(1000, segments: 1)];
        $mapper = new Par2FileMapper;
        $this->assertSame([], $mapper->candidateIndexes($nzb, $file, articlePartSize: 10000));
        $mapped = $mapper->map($nzb, [$file], articlePartSize: 10000, verified: [0 => $file]);
        $this->assertSame([0], array_keys($mapped));
        $this->assertSame($file, $mapped[0]->file);
        $this->assertSame(0, $mapped[0]->sourceIndex);
        $this->assertSame(MappingConfidence::Verified, $mapped[0]->confidence);
    }

    /**
     * @return array{title: string, size: int, filecount: int, segments: list<string>}
     */
    private function entry(int $size, int $segments = 1, int $fileCount = 1): array
    {
        static $counter = 0;
        $counter++;

        return [
            'title' => '"'.md5((string) $counter).'" yEnc (1/'.$segments.')',
            'size' => $size,
            'filecount' => $fileCount,
            'segments' => array_map(static fn (int $part): string => '<'.$counter.'.'.$part.'>', range(1, $segments)),
        ];
    }
}
