<?php

declare(strict_types=1);

namespace Tests\Unit\Services\NameFixing;

use App\Facades\Search;
use App\Services\NameFixing\FileNameCleaner;
use App\Services\NameFixing\NameFixingService;
use App\Services\NameFixing\PredbMatchSelector;
use App\Services\Search\SearchService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class NumericFilenameAliasGuardTest extends TestCase
{
    protected function tearDown(): void
    {
        Search::clearResolvedInstance(SearchService::class);
        parent::tearDown();
    }

    public function test_ascii_numeric_selector_behavior_remains_unchanged(): void
    {
        $s = $this->service([['id' => 1, 'title' => 'Synthetic.Canonical.2025-GRP', 'filename' => '123456.mkv']]);
        self::assertNull($s->lookup('123456.mkv'));
        self::assertNull((new PredbMatchSelector)->selectBestMatch('123456', [['id' => 2, 'title' => '123456']]));
    }

    public static function stems(): array
    {
        return array_map(fn ($s) => [$s], ['١٢٣٤٥٦', '１２３４５６', '१२३४५६', '¹²³⁴⁵⁶', 'ⅢⅣⅤⅥ', '١２३⁴Ⅴ', '١٢٣.٤٥٦', '１２３-４５６', '１２３_４５６', '１２３　４５６', '١٢٣٫٤٥٦', '(１２３４５６)']);
    }

    private function service(array $hits): NameFixingService
    {
        Search::swap(new class($hits)
        {
            public function __construct(private array $hits) {}

            public function searchPredb($q): array
            {
                return $this->hits;
            }
        });

        return new class extends NameFixingService
        {
            public function __construct()
            {
                $this->fileNameCleaner = new FileNameCleaner;
                $this->predbMatchSelector = new PredbMatchSelector($this->fileNameCleaner);
            }

            public function lookup(string $file): ?array
            {
                $q = $this->fileNameCleaner->cleanForMatching($file, preserveLeadingNumber: true);

                return $q === false ? null : $this->findBestPredbMatch($q);
            }
        };
    }

    #[DataProvider('stems')]
    public function test_numeric_filename_alias_abstains_in_actual_candidate_path(string $stem): void
    {
        $s = $this->service([['id' => 1, 'title' => 'Synthetic.Canonical.2025-GRP', 'filename' => $stem.'.mkv']]);
        self::assertNull($s->lookup($stem.'.mkv'));
    }

    #[DataProvider('stems')]
    public function test_literal_numeric_title_keeps_precedence(string $stem): void
    {
        $s = $this->service([['id' => 1, 'title' => 'Synthetic.Canonical.2025-GRP', 'filename' => $stem.'.mkv'], ['id' => 2, 'title' => $stem, 'filename' => 'other.mkv']]);
        self::assertSame(2, $s->lookup($stem.'.mkv')['id'] ?? null);
    }

    public static function usefulStems(): array
    {
        return [['第１２話'], ['Серия１２３'], ['Επεισόδιο１２３'], ['Synthetic１２３']];
    }

    #[DataProvider('usefulStems')]
    public function test_alphanumeric_alias_still_works(string $stem): void
    {
        $s = $this->service([['id' => 1, 'title' => 'Synthetic.Canonical.2025-GRP', 'filename' => $stem.'.mkv']]);
        self::assertSame(1, $s->lookup($stem.'.mkv')['id'] ?? null);
    }
}
