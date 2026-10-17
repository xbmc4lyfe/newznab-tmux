<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Api\V1\ReleaseItemPayloadBuilder;
use App\Services\Api\V1\ReleaseRows;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PartialReleaseApiAttributesTest extends TestCase
{
    public function test_extended_attributes_report_found_and_declared_counts_only_for_partial_releases(): void
    {
        $builder = new ReleaseItemPayloadBuilder(['extended' => '1', 'token' => 'token'], ['server' => ['url' => 'https://indexer.invalid']]);

        $complete = $builder->build($this->release('complete'))['attr'];
        $partial = $builder->build($this->release('partial') + ['articles_found' => 236, 'articles_expected' => 934,
            'files_found' => 3, 'files_expected' => 4])['attr'];

        $this->assertArrayNotHasKey('articles_expected', $complete);
        $this->assertSame([236, 934, 3, 4], [$partial['articles_found'], $partial['articles_expected'], $partial['files_found'], $partial['files_expected']]);
    }

    public function test_search_rows_without_the_counts_receive_them_by_guid(): void
    {
        Schema::dropIfExists('releases');
        Schema::create('releases', function (Blueprint $table): void {
            $table->id();
            $table->string('guid');
            foreach (['articles_found', 'articles_expected', 'files_found', 'files_expected'] as $column) {
                $table->unsignedInteger($column)->nullable();
            }
        });
        DB::table('releases')->insert([['guid' => 'partial', 'articles_found' => 236, 'articles_expected' => 934, 'files_found' => 3, 'files_expected' => 4],
            ['guid' => 'complete', 'articles_found' => null, 'articles_expected' => null, 'files_found' => null, 'files_expected' => null]]);

        // Search projections and index documents carry neither column; arrays and objects both occur.
        $rows = (new ReleaseRows([$this->release('partial'), (object) $this->release('complete')]))->rows();

        $this->assertSame(934, (int) $rows[0]['articles_expected']);
        $this->assertSame(3, (int) $rows[0]['files_found']);
        $this->assertObjectNotHasProperty('articles_expected', $rows[1]);
    }

    /** @return array<string, mixed> */
    private function release(string $guid): array
    {
        return ['guid' => $guid, 'searchname' => 'Movie', 'adddate' => '2026-10-17 00:00:00', 'postdate' => '2026-10-17 00:00:00',
            'categories_id' => 2000, 'size' => 1000, 'totalpart' => 2, 'category_name' => 'Movies > HD'];
    }
}
