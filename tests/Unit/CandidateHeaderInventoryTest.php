<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Releases\Verification\CandidateHeaderInventory;
use PHPUnit\Framework\TestCase;

class CandidateHeaderInventoryTest extends TestCase
{
    public function test_only_valid_missing_headers_wait_for_upload(): void
    {
        $inventory = new CandidateHeaderInventory;
        $manifest = $this->manifest();
        $this->assertSame('ready', $inventory->assessment($manifest)['status']);
        $manifest['files'][0]['totalparts'] = 2;
        $this->assertSame('waiting_upload', $inventory->assessment($manifest)['status']);
        $manifest['expected_files'] = 2;
        $manifest['files'][0]['segments'][0]['number'] = 3;
        $this->assertSame('header_rejected', $inventory->assessment($manifest)['status']);
    }

    public function test_invalid_totals_excess_parts_and_conflicts_are_not_uploads(): void
    {
        $inventory = new CandidateHeaderInventory;
        foreach (['zero', 'excess', 'duplicate', 'conflict'] as $case) {
            $manifest = $this->manifest();
            $manifest['expected_files'] = 2;
            if ($case === 'zero') {
                $manifest['files'][0]['totalparts'] = 0;
            } elseif ($case === 'excess') {
                $manifest['files'][0]['segments'][] = ['number' => 2, 'messageid' => 'b@article', 'bytes' => 5];
            } elseif ($case === 'duplicate') {
                $manifest['files'][0]['totalparts'] = 3;
                $manifest['files'][0]['segments'][] = $manifest['files'][0]['segments'][0];
            } else {
                $manifest['conflict'] = true;
            }
            $this->assertSame('header_rejected', $inventory->assessment($manifest)['status'], $case);
        }
    }

    private function manifest(): array
    {
        return ['expected_files' => 1, 'files' => [['key' => 'movie', 'totalparts' => 1,
            'segments' => [['number' => 1, 'messageid' => 'a@article', 'bytes' => 5]]]]];
    }
}
