<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Binaries\BinariesConfig;
use App\Services\Binaries\BinaryHandler;
use App\Services\Binaries\CollectionHandler;
use App\Services\Binaries\HeaderStorageService;
use App\Services\Binaries\PartHandler;
use App\Support\Par2CandidateProgress;
use App\Support\RustPipelineLibrary;
use FFI;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class RustPartHandlerPar2CheckpointParityTest extends TestCase
{
    private ?FFI $ffi = null;

    protected function setUp(): void
    {
        parent::setUp();

        PartHandler::setUseRustFfi(true);
        HeaderStorageService::setUseRustFfi(true);
        Par2CandidateProgress::setUseRustFfi(true);

        $this->ffi = RustPipelineLibrary::resolveDomain(
            'additional',
            PartHandler::FFI_CDEF."\n"
            .HeaderStorageService::FFI_CDEF."\n"
            .Par2CandidateProgress::FFI_CDEF
        );

        if ($this->ffi === null) {
            $this->markTestSkipped('Rust pipeline FFI library is not built in this environment.');
        }
    }

    protected function tearDown(): void
    {
        PartHandler::setUseRustFfi(true);
        HeaderStorageService::setUseRustFfi(true);
        Par2CandidateProgress::setUseRustFfi(true);

        parent::tearDown();
    }

    public function test_part_handler_validation_error_parity(): void
    {
        $headers = [
            ['matches' => [2 => 1], 'Message-ID' => '<part1@usenet.example.com>'],
            ['matches' => [2 => '42'], 'Message-ID' => "  <trimmed.valid@usenet.example.com> \t\r\n"],
            ['matches' => [2 => 1], 'Message-ID' => str_repeat('a', 255)],
            ['matches' => [2 => 0], 'Message-ID' => '<valid@usenet.example.com>'],
            ['matches' => [2 => -5], 'Message-ID' => '<valid@usenet.example.com>'],
            ['Message-ID' => '<missing.matches@usenet.example.com>'],
            ['matches' => [2 => 1], 'Message-ID' => ''],
            ['matches' => [2 => 1], 'Message-ID' => "   \t\r\n  "],
            ['matches' => [2 => 1], 'Message-ID' => str_repeat('a', 256)],
            ['matches' => [2 => 1], 'Message-ID' => "<bad\x00null@usenet.example.com>"],
            ['matches' => [2 => 1], 'Message-ID' => "<bad\x1Fctrl@usenet.example.com>"],
            ['matches' => [2 => 1], 'Message-ID' => "<bad\x7Fdel@usenet.example.com>"],
            ['matches' => [2 => 1], 'Message-ID' => "<bad\nnewline@usenet.example.com>"],
            ['matches' => [2 => 1], 'Message-ID' => '<utf8-é@usenet.example.com>'],
        ];

        foreach ($headers as $idx => $header) {
            $supported = false;
            $php = PartHandler::validationErrorPhp($header);
            $native = PartHandler::validationErrorNative($header, $supported);
            $prod = PartHandler::validationError($header);

            $this->assertTrue($supported, "validationErrorNative should be supported for case {$idx}");
            $this->assertSame($php, $native, "validationError mismatch at case {$idx}");
            $this->assertSame($php, $prod);
        }

        PartHandler::setUseRustFfi(false);
        $supported = true;
        $this->assertNull(PartHandler::validationErrorNative($headers[3], $supported));
        $this->assertFalse($supported);
    }

    public function test_part_handler_should_prefer_parity(): void
    {
        $handler = new PartHandler;
        $pairs = [
            // Non-empty messageid beats empty/whitespace messageid
            [
                ['binaries_id' => 1, 'number' => 200, 'messageid' => '<msg@example>', 'partnumber' => 1, 'size' => 100],
                ['binaries_id' => 1, 'number' => 100, 'messageid' => '   ', 'partnumber' => 1, 'size' => 999],
            ],
            [
                ['binaries_id' => 1, 'number' => 100, 'messageid' => '', 'partnumber' => 1, 'size' => 999],
                ['binaries_id' => 1, 'number' => 200, 'messageid' => '<msg@example>', 'partnumber' => 1, 'size' => 100],
            ],
            // Larger size beats smaller size when messageid presence matches
            [
                ['binaries_id' => 1, 'number' => 200, 'messageid' => '<a@example>', 'partnumber' => 1, 'size' => 500],
                ['binaries_id' => 1, 'number' => 100, 'messageid' => '<b@example>', 'partnumber' => 1, 'size' => 400],
            ],
            [
                ['binaries_id' => 1, 'number' => 100, 'messageid' => '<a@example>', 'partnumber' => 1, 'size' => 400],
                ['binaries_id' => 1, 'number' => 200, 'messageid' => '<b@example>', 'partnumber' => 1, 'size' => 500],
            ],
            // Lower article number beats higher article number when messageid presence and size match
            [
                ['binaries_id' => 1, 'number' => 100, 'messageid' => '<a@example>', 'partnumber' => 1, 'size' => 500],
                ['binaries_id' => 1, 'number' => 200, 'messageid' => '<b@example>', 'partnumber' => 1, 'size' => 500],
            ],
            [
                ['binaries_id' => 1, 'number' => 200, 'messageid' => '<a@example>', 'partnumber' => 1, 'size' => 500],
                ['binaries_id' => 1, 'number' => 100, 'messageid' => '<b@example>', 'partnumber' => 1, 'size' => 500],
            ],
            // Equal size and number keeps existing (false)
            [
                ['binaries_id' => 1, 'number' => 100, 'messageid' => '<a@example>', 'partnumber' => 1, 'size' => 500],
                ['binaries_id' => 1, 'number' => 100, 'messageid' => '<b@example>', 'partnumber' => 1, 'size' => 500],
            ],
        ];

        foreach ($pairs as $idx => [$candidate, $current]) {
            $php = $handler->shouldPreferPhp($candidate, $current);
            $native = $handler->shouldPreferNative($candidate, $current);
            $prod = $handler->shouldPrefer($candidate, $current);

            $this->assertSame($php, $native, "shouldPrefer mismatch at pair {$idx}");
            $this->assertSame($php, $prod);
        }

        PartHandler::setUseRustFfi(false);
        $this->assertNull($handler->shouldPreferNative($pairs[0][0], $pairs[0][1]));
    }

    public function test_part_handler_deduplicate_and_sort_primary_key_parity(): void
    {
        $handler = new PartHandler;

        $this->assertSame([], $handler->deduplicatePartsNative([]));
        $this->assertSame([], $handler->sortPartsByPrimaryKeyNative([]));

        $parts = [
            ['binaries_id' => 30, 'number' => 1005, 'messageid' => '', 'partnumber' => 2, 'size' => 900],
            ['binaries_id' => 10, 'number' => 1002, 'messageid' => '<10-3-small@ex>', 'partnumber' => 3, 'size' => 500],
            ['binaries_id' => 30, 'number' => 1006, 'messageid' => '<30-2-nonempty@ex>', 'partnumber' => 2, 'size' => 100],
            ['binaries_id' => 10, 'number' => 1001, 'messageid' => '<10-1-first@ex>', 'partnumber' => 1, 'size' => 700],
            ['binaries_id' => 10, 'number' => 1004, 'messageid' => '<10-3-larger@ex>', 'partnumber' => 3, 'size' => 600],
            ['binaries_id' => 20, 'number' => 1010, 'messageid' => '<20-1-high-num@ex>', 'partnumber' => 1, 'size' => 800],
            ['binaries_id' => 20, 'number' => 1008, 'messageid' => '<20-1-low-num@ex>', 'partnumber' => 1, 'size' => 800],
            ['binaries_id' => 20, 'number' => 1009, 'messageid' => '<20-1-mid-num@ex>', 'partnumber' => 1, 'size' => 800],
        ];

        $phpDedup = $handler->deduplicatePartsPhp($parts);
        $nativeDedup = $handler->deduplicatePartsNative($parts, false);
        $this->assertSame($phpDedup, $nativeDedup);
        $this->assertSame($phpDedup, $handler->deduplicateParts($parts));

        $phpSortedDedup = $handler->sortPartsByPrimaryKeyPhp($phpDedup);
        $nativeSortedDedup = $handler->deduplicatePartsNative($parts, true);
        $this->assertSame($phpSortedDedup, $nativeSortedDedup);

        $phpSortedAll = $handler->sortPartsByPrimaryKeyPhp($parts);
        $nativeSortedAll = $handler->sortPartsByPrimaryKeyNative($parts);
        $prodSortedAll = $handler->sortPartsByPrimaryKey($parts);
        $this->assertSame($phpSortedAll, $nativeSortedAll);
        $this->assertSame($phpSortedAll, $prodSortedAll);

        PartHandler::setUseRustFfi(false);
        $this->assertNull($handler->deduplicatePartsNative($parts));
        $this->assertNull($handler->sortPartsByPrimaryKeyNative($parts));
        $this->assertSame($phpSortedAll, $handler->sortPartsByPrimaryKey($parts));
    }

    public function test_header_storage_file_counter_and_batch_parity(): void
    {
        $config = new BinariesConfig;
        $service = new HeaderStorageService(
            new CollectionHandler(sqlChunkSize: $config->sqlChunkSize),
            new BinaryHandler($config->sqlChunkSize),
            new PartHandler($config->sqlChunkSize),
            $config
        );

        $subjects = [
            'Release.Name.2026 [01/25] - "archive.part01.rar" yEnc (1/50)',
            'Release.Name.2026 (12/34) - "archive.r11" yEnc (1/50)',
            'Release.Name.2026 5/10 "archive.r04" yEnc (1/50)',
            "Release.Name.2026\t3/8\n\"archive.r02\" yEnc (1/50)",
            'Release.Name.2026 [1 of 10] - "archive.rar" yEnc (1/50)',
            'Release.Name.2026 (2_OF_20) - "archive.rar" yEnc (1/50)',
            "Release.Name.2026 [3\tof\t30) - \"archive.rar\" yEnc (1/50)",
            'Release.Name.2026 (4-15] "archive.rar" yEnc (1/50)',
            'Release.Name.2026 [7/9$ "archive.rar"',
            'Release.Name.2026 (8/9: "archive.rar"',
            'Release.Name.2026 10/20[ "archive.rar"',
            'Max.Five.Digits [12345/67890] "archive.rar"',
            'Too.Many.Digits [123456/789] "archive.rar"',
            'Multiple.Counters [02/15] inner (09/99) "archive.rar"',
            'No.File.Counter.At.All "archive.rar" yEnc',
            '',
            "Null\x00Prefix [04/12] \"archive.rar\"",
        ];

        $headersList = [];
        $headersAssoc = [];
        foreach ($subjects as $idx => $subject) {
            $phpCount = $service->getFileCountPhp($subject);
            $nativeCount = $service->getFileCountNative($subject);
            $prodCount = $service->getFileCount($subject);

            $this->assertSame($phpCount, $nativeCount, "getFileCount mismatch for subject #{$idx}: {$subject}");
            $this->assertSame($phpCount, $prodCount);

            $header = ['matches' => [1 => $subject]];
            $phpPair = $service->extractFileNumberAndTotalPhp($header);
            $nativePair = $service->extractFileNumberAndTotalNative($header);
            $prodPair = $service->extractFileNumberAndTotal($header);

            $this->assertSame($phpPair, $nativePair, "extractFileNumberAndTotal mismatch for subject #{$idx}");
            $this->assertSame($phpPair, $prodPair);

            $headersList[] = $header;
            $headersAssoc[($idx + 1) * 10] = $header;
        }

        // Sequential list without NUL
        $listWithoutNul = array_slice($headersList, 0, -1);
        $this->assertSame(
            $service->extractFileNumbersAndTotalsBatchPhp($listWithoutNul),
            $service->extractFileNumbersAndTotalsBatchNative($listWithoutNul)
        );

        // Sequential list with embedded NUL
        $this->assertSame(
            $service->extractFileNumbersAndTotalsBatchPhp($headersList),
            $service->extractFileNumbersAndTotalsBatchNative($headersList)
        );
        $this->assertSame(
            $service->extractFileNumbersAndTotalsBatchPhp($headersList),
            $service->extractFileNumbersAndTotalsBatch($headersList)
        );

        // Associative keys
        $assocWithoutNul = array_slice($headersAssoc, 0, -1, true);
        $this->assertSame(
            $service->extractFileNumbersAndTotalsBatchPhp($assocWithoutNul),
            $service->extractFileNumbersAndTotalsBatchNative($assocWithoutNul)
        );
        $this->assertSame(
            $service->extractFileNumbersAndTotalsBatchPhp($headersAssoc),
            $service->extractFileNumbersAndTotalsBatchNative($headersAssoc)
        );
        $this->assertSame([[], []], $service->extractFileNumbersAndTotalsBatchNative([]));

        HeaderStorageService::setUseRustFfi(false);
        $this->assertNull($service->getFileCountNative($subjects[0]));
        $this->assertNull($service->extractFileNumberAndTotalNative($headersList[0]));
        $this->assertNull($service->extractFileNumbersAndTotalsBatchNative($listWithoutNul));
    }

    public function test_par2_checkpoint_validate_arguments_parity(): void
    {
        $validSig = str_repeat('a1b2c3d4', 8);
        $otherSig = str_repeat('0f', 32);

        $cases = [
            [1, $validSig, $otherSig, 1, 1],
            [999999, $validSig, $otherSig, 1000000, 4294967295],
            [0, $validSig, $otherSig, 10, 1024],
            [-1, $validSig, $otherSig, 10, 1024],
            [1, $validSig, $otherSig, 0, 1024],
            [1, $validSig, $otherSig, 1000001, 1024],
            [1, $validSig, $otherSig, 10, 0],
            [1, $validSig, $otherSig, 10, 4294967296],
            [1, strtoupper($validSig), $otherSig, 10, 1024],
            [1, substr($validSig, 0, 63), $otherSig, 10, 1024],
            [1, $validSig.'0', $otherSig, 10, 1024],
            [1, $validSig."\n", $otherSig, 10, 1024],
            [1, $validSig, str_repeat('g', 64), 10, 1024],
        ];

        foreach ($cases as $idx => [$releaseId, $manifest, $candidate, $segCount, $maxBytes]) {
            $phpErr = null;
            try {
                Par2CandidateProgress::validateArgumentsPhp($releaseId, $manifest, $candidate, $segCount, $maxBytes);
            } catch (InvalidArgumentException $e) {
                $phpErr = $e->getMessage();
            }

            $nativeErr = null;
            $nativeOk = false;
            try {
                $nativeOk = Par2CandidateProgress::validateArgumentsNative($releaseId, $manifest, $candidate, $segCount, $maxBytes);
            } catch (InvalidArgumentException $e) {
                $nativeErr = $e->getMessage();
            }

            $this->assertSame($phpErr, $nativeErr, "validateArguments mismatch at case {$idx}");
            if ($phpErr === null) {
                $this->assertTrue($nativeOk);
            }
        }

        Par2CandidateProgress::setUseRustFfi(false);
        $this->assertFalse(Par2CandidateProgress::validateArgumentsNative(1, $validSig, $otherSig, 10, 1024));
    }

    public function test_par2_checkpoint_validate_cursor_parity(): void
    {
        $cases = [
            [0, [], 10],
            [5, [0, 2, 4], 10],
            [10, [0, 1, 2, 9], 10],
            [-1, [], 10],
            [11, [], 10],
            [1, [0, 1], 10],
            [5, [1 => 0, 2 => 1], 10],
            [5, [-1], 10],
            [5, [5], 10],
            [5, [6], 10],
            [5, [1, 3, 1], 10],
            [5, ['1'], 10],
        ];

        foreach ($cases as $idx => [$nextSegment, $retries, $segmentCount]) {
            $phpErr = null;
            try {
                Par2CandidateProgress::validateCursorPhp($nextSegment, $retries, $segmentCount);
            } catch (InvalidArgumentException $e) {
                $phpErr = $e->getMessage();
            }

            $nativeErr = null;
            $nativeOk = false;
            try {
                $nativeOk = Par2CandidateProgress::validateCursorNative($nextSegment, $retries, $segmentCount);
            } catch (InvalidArgumentException $e) {
                $nativeErr = $e->getMessage();
            }

            $this->assertSame($phpErr, $nativeErr, "validateCursor mismatch at case {$idx}");
            if ($phpErr === null) {
                $this->assertTrue($nativeOk);
            }
        }

        Par2CandidateProgress::setUseRustFfi(false);
        $this->assertFalse(Par2CandidateProgress::validateCursorNative(0, [], 10));
    }

    public function test_par2_checkpoint_validate_block_parity(): void
    {
        $cases = [
            null,
            'a',
            'state_limit',
            'malformed_state',
            'a'.str_repeat('b0_', 21), // 64 chars
            '',
            '1starts_with_digit',
            '_starts_with_underscore',
            'Uppercase_not_allowed',
            'hyphen-not-allowed',
            "state_limit\n",
            'a'.str_repeat('b', 64), // 65 chars
        ];

        foreach ($cases as $idx => $reason) {
            $phpErr = null;
            try {
                Par2CandidateProgress::validateBlockPhp($reason);
            } catch (InvalidArgumentException $e) {
                $phpErr = $e->getMessage();
            }

            $nativeErr = null;
            $nativeOk = false;
            try {
                $nativeOk = Par2CandidateProgress::validateBlockNative($reason);
            } catch (InvalidArgumentException $e) {
                $nativeErr = $e->getMessage();
            }

            $this->assertSame($phpErr, $nativeErr, "validateBlock mismatch at case {$idx}");
            if ($phpErr === null) {
                $this->assertTrue($nativeOk);
            }
        }

        Par2CandidateProgress::setUseRustFfi(false);
        $this->assertFalse(Par2CandidateProgress::validateBlockNative('state_limit'));
    }

    public function test_par2_checkpoint_canonical_identity_parity(): void
    {
        $validB64 = base64_encode('Movie.2026.1080p.BluRay.x264-GROUP.par2');

        $cases = [
            [null, 0, false],
            [null, 0, true],
            [['name_base64' => $validB64, 'size' => 4096, 'part' => null], 0, false],
            [['name_base64' => $validB64, 'size' => 4096, 'part' => 1], 4096, false],
            [['name_base64' => $validB64, 'size' => 4096, 'part' => 1000000], 2048, false],
            [null, -1, false],
            [['name_base64' => $validB64, 'size' => 4096, 'part' => 1], -5, false],
            [['name_base64' => $validB64, 'size' => 4096, 'part' => 1], 0, true],
            [['name_base64' => $validB64, 'size' => 4096], 0, false],
            [['name_base64' => $validB64, 'size' => 4096, 'part' => 1, 'extra' => 1], 0, false],
            [['name_base64' => $validB64, 'size' => 0, 'part' => 1], 0, false],
            [['name_base64' => $validB64, 'size' => 100, 'part' => 1], 101, false],
            [['name_base64' => $validB64, 'size' => 100, 'part' => 0], 0, false],
            [['name_base64' => $validB64, 'size' => 100, 'part' => 1000001], 0, false],
            [['name_base64' => '', 'size' => 100, 'part' => 1], 0, false],
            [['name_base64' => 'not!valid!base64', 'size' => 100, 'part' => 1], 0, false],
            [['name_base64' => 'YQ', 'size' => 100, 'part' => 1], 0, false], // missing '=' padding
            [['name_base64' => 'YWI=', 'size' => 100, 'part' => 1], 0, false], // non-zero trailing pad bits
            [['name_base64' => base64_encode("bad\nname.par2"), 'size' => 100, 'part' => 1], 0, false],
            [['name_base64' => base64_encode("bad\rname.par2"), 'size' => 100, 'part' => 1], 0, false],
            [['name_base64' => base64_encode("bad\0name.par2"), 'size' => 100, 'part' => 1], 0, false],
            [['name_base64' => base64_encode(str_repeat('x', 65537)), 'size' => 100, 'part' => 1], 0, false],
        ];

        foreach ($cases as $idx => [$identity, $decodedOffset, $tombstone]) {
            $phpRes = null;
            $phpErr = null;
            try {
                $phpRes = Par2CandidateProgress::canonicalIdentityPhp($identity, $decodedOffset, $tombstone);
            } catch (InvalidArgumentException $e) {
                $phpErr = $e->getMessage();
            }

            $nativeRes = null;
            $nativeErr = null;
            $supported = false;
            try {
                $nativeRes = Par2CandidateProgress::canonicalIdentityNative($identity, $decodedOffset, $tombstone, $supported);
            } catch (InvalidArgumentException $e) {
                $nativeErr = $e->getMessage();
            }

            $this->assertTrue($supported, "canonicalIdentityNative should be supported at case {$idx}");
            $this->assertSame($phpErr, $nativeErr, "canonicalIdentity exception mismatch at case {$idx}");
            $this->assertSame($phpRes, $nativeRes, "canonicalIdentity result mismatch at case {$idx}");
        }

        Par2CandidateProgress::setUseRustFfi(false);
        $supported = true;
        $this->assertNull(Par2CandidateProgress::canonicalIdentityNative(null, 0, false, $supported));
        $this->assertFalse($supported);
    }

    public function test_par2_checkpoint_stored_integer_parity(): void
    {
        $cases = [
            [0, 1000000],
            [1000000, 1000000],
            ['0', 1000000],
            ['999999', 1000000],
            ['1000000', 1000000],
            [(string) PHP_INT_MAX, PHP_INT_MAX],
            [-1, 1000000],
            [1000001, 1000000],
            ['', 1000000],
            ['00', 1000000],
            ['01', 1000000],
            ['+1', 1000000],
            ['-1', 1000000],
            ['1000001', 1000000],
            ['9999999', 1000000],
            ['12a', 1000000],
            ["12\n", 1000000],
            [' 12', 1000000],
            [null, 1000000],
            [true, 1000000],
            [12.5, 1000000],
        ];

        foreach ($cases as $idx => [$value, $maximum]) {
            $phpRes = null;
            $phpErr = null;
            try {
                $phpRes = Par2CandidateProgress::storedIntegerPhp($value, $maximum);
            } catch (InvalidArgumentException $e) {
                $phpErr = $e->getMessage();
            }

            $nativeRes = null;
            $nativeErr = null;
            try {
                $nativeRes = Par2CandidateProgress::storedIntegerNative($value, $maximum);
            } catch (InvalidArgumentException $e) {
                $nativeErr = $e->getMessage();
            }

            $this->assertSame($phpErr, $nativeErr, "storedInteger exception mismatch at case {$idx}");
            $this->assertSame($phpRes, $nativeRes, "storedInteger result mismatch at case {$idx}");
        }

        Par2CandidateProgress::setUseRustFfi(false);
        $this->assertNull(Par2CandidateProgress::storedIntegerNative('42', 100));
    }

    public function test_c_abi_null_pointer_and_small_buffer_contracts(): void
    {
        $ffi = $this->ffi;
        $this->assertNotNull($ffi);

        // Null pointer checks return -1
        // @phpstan-ignore method.notFound
        $this->assertSame(-1, $ffi->nntmux_part_validation_error(1, null, 5));
        // @phpstan-ignore method.notFound
        $this->assertSame(-1, $ffi->nntmux_part_should_prefer(null, 5, 100, 1, '<b@ex>', 6, 100, 2));
        // @phpstan-ignore method.notFound
        $this->assertSame(-1, $ffi->nntmux_part_deduplicate(null, 36, 1, 0, null, 0));
        // @phpstan-ignore method.notFound
        $this->assertSame(-1, $ffi->nntmux_part_sort_primary_key(null, 16, 1, null, 0));
        // @phpstan-ignore method.notFound
        $this->assertSame(-1, $ffi->nntmux_header_extract_file_numbers(null, 5));
        // @phpstan-ignore method.notFound
        $this->assertSame(-1, $ffi->nntmux_header_get_file_count_span(null, 5));
        // @phpstan-ignore method.notFound
        $this->assertSame(-1, $ffi->nntmux_header_extract_file_numbers_batch_nul(null, 5, 1, null, 0));
        // @phpstan-ignore method.notFound
        $this->assertSame(-1, $ffi->nntmux_par2_checkpoint_validate_arguments(1, null, 64, str_repeat('a', 64), 64, 1, 1));
        // @phpstan-ignore method.notFound
        $this->assertSame(-1, $ffi->nntmux_par2_checkpoint_validate_cursor(2, 10, null, 8, 1));
        // @phpstan-ignore method.notFound
        $this->assertSame(-1, $ffi->nntmux_par2_checkpoint_validate_block(null, 5, 0));
        // @phpstan-ignore method.notFound
        $this->assertSame(-1, $ffi->nntmux_par2_checkpoint_validate_identity(null, 4, 100, 0, 0, 0, 0));
        // @phpstan-ignore method.notFound
        $this->assertSame(-1, $ffi->nntmux_par2_checkpoint_stored_integer(null, 2, 100));

        // Buffer resize contract returns -(needed)
        $tiny = $ffi->new('char[2]');

        $sortInput = pack('q2', 20, 1).pack('q2', 10, 1);
        // @phpstan-ignore method.notFound
        $this->assertSame(-8, $ffi->nntmux_part_sort_primary_key($sortInput, strlen($sortInput), 2, $tiny, 2));

        $dedupInput = pack('q4L', 10, 1, 500, 100, 4).'<id>';
        // @phpstan-ignore method.notFound
        $this->assertSame(-4, $ffi->nntmux_part_deduplicate($dedupInput, strlen($dedupInput), 1, 0, $tiny, 2));

        $batchSubjects = "Subject [1/2]\0Subject [2/2]";
        // @phpstan-ignore method.notFound
        $this->assertSame(-16, $ffi->nntmux_header_extract_file_numbers_batch_nul($batchSubjects, strlen($batchSubjects), 2, $tiny, 2));
    }
}
