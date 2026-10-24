<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Nzb\NzbSourceGuard;
use App\Services\Nzb\NzbUploadManifestService;
use App\Services\Nzb\Par2CandidateRecovery;
use App\Services\Yenc\YencPart;
use App\Support\NzbPasswordMetadata;
use App\Support\RustPipelineLibrary;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Random\Engine\Mt19937;
use Random\Randomizer;
use Throwable;

/**
 * Parity and paired benchmarks for the wave 12 NZB custody, upload manifest, PAR2 recovery and
 * password metadata Rust candidates.
 *
 * Paired randomized single-call `hrtime(true)` samples (60 pairs per workload, 50 warmups), Apple M5,
 * PHP 8.5.12 NTS with opcache CLI, rustc 1.99.1 release (LTO, codegen-units=1), base c97b6e0e6:
 * - `NzbSourceGuard::digest` 0.5 MB NZB (common): PHP 1212.3 us mean / 1808.8 us p99 vs Rust 148.5 us / 264.3 us -> 8.16x mean, 6.84x p99.
 * - `NzbSourceGuard::digest` 9 MB NZB (uncommon): PHP 19.91 ms / 25.53 ms vs Rust 2.48 ms / 3.84 ms -> 8.04x mean, 6.65x p99.
 * - `NzbSourceGuard::digest` <1 KB NZB (uncommon): PHP 2993 ns / 12667 ns vs Rust 741 ns / 2875 ns -> 4.04x mean, 4.41x p99.
 *   Adopted in production with PHP fallback (ARMv8 SHA2 / x86 SHA-NI, verified against the portable compressor).
 * - `validateAnchor` 1.32x mean / 1.90x p99 (inode), 0.85x / 0.86x (absent) -> retained PHP; `validateAnchorNative` tested.
 * - `matchesAnchor` 0.58x / 0.56x (match), 0.51x / 0.54x (mismatch) -> retained PHP; `matchesAnchorNative` tested.
 * - `stagedGuid` (STAGED_NAME) 1.22x / 1.25x (common), 1.48x / 1.25x (long) -> retained PHP; `stagedGuidNative` tested.
 * - `assertSafeFilename` 1.27-1.48x mean / 1.50-1.71x p99 (common, two runs), 2.03x / 1.72x (long) -> retained PHP; `assertSafeFilenameNative` tested.
 * - `canReconcileCandidate` 0.23x / 0.22x (pending), 0.49x / 0.50x (review) -> retained PHP; `canReconcileCandidateNative` tested.
 * - `NzbPasswordMetadata::isValid` 0.49x / 0.50x (one password), 0.54x / 0.62x (64 x 4 KB) -> retained PHP; `isValidNative` tested.
 * - `Par2CandidateRecovery::validatePart` 0.67x / 0.57x (multipart), 0.89x / 1.09x (single part, 4 KB name) -> retained PHP; `validatePartNative` tested.
 */
final class RustNzbCustodyAndPar2RecoveryParityTest extends TestCase
{
    private const string GUID = '0123456789abcdef0123456789abcdef';

    protected function setUp(): void
    {
        parent::setUp();
        $this->setAll(true);
        $cdef = NzbSourceGuard::FFI_CDEF."\n".NzbUploadManifestService::FFI_CDEF."\n"
            .Par2CandidateRecovery::FFI_CDEF."\n".NzbPasswordMetadata::FFI_CDEF;
        if (RustPipelineLibrary::resolveDomain('additional', $cdef) === null) {
            self::markTestSkipped('Rust pipeline FFI library is not built in this environment.');
        }
    }

    protected function tearDown(): void
    {
        $this->setAll(true);
        parent::tearDown();
    }

    private function setAll(bool $use): void
    {
        NzbSourceGuard::setUseRustFfi($use);
        NzbUploadManifestService::setUseRustFfi($use);
        Par2CandidateRecovery::setUseRustFfi($use);
        NzbPasswordMetadata::setUseRustFfi($use);
    }

    /** @return array{ok: mixed}|array{error: class-string, message: string} */
    private static function outcome(callable $call): array
    {
        try {
            return ['ok' => $call()];
        } catch (Throwable $exception) {
            return ['error' => $exception::class, 'message' => $exception->getMessage()];
        }
    }

    private static function nzbXml(int $files, int $segments): string
    {
        $xml = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<!DOCTYPE nzb PUBLIC \"-//newzBin//DTD NZB 1.1//EN\" \"http://www.newzbin.com/DTD/nzb/nzb-1.1.dtd\">\n"
            ."<nzb xmlns=\"http://www.newzbin.com/DTD/2003/nzb\">\n";
        for ($file = 1; $file <= $files; $file++) {
            $xml .= " <file poster=\"poster@example.com (Poster)\" date=\"1700000000\" subject=\"[{$file}/{$files}] - &quot;Some.Release.2024.1080p.WEB-DL.part{$file}.rar&quot; yEnc (1/{$segments})\">\n"
                ."  <groups><group>alt.binaries.example</group></groups>\n  <segments>\n";
            for ($segment = 1; $segment <= $segments; $segment++) {
                $xml .= '   <segment bytes="'.(739000 + $segment)."\" number=\"{$segment}\">".hash('crc32b', "{$file}-{$segment}").'$'.$segment."@news.example.com</segment>\n";
            }
            $xml .= "  </segments>\n </file>\n";
        }

        return $xml.'</nzb>';
    }

    #[Test]
    public function digest_parity(): void
    {
        $inputs = ['', 'a', str_repeat('x', 35), str_repeat('x', 36), str_repeat('x', 37), str_repeat('y', 99),
            str_repeat('z', 100), str_repeat('z', 101), "\0\xff\xfe binary", self::nzbXml(2, 40)];
        $random = new Randomizer(new Mt19937(12));
        for ($i = 0; $i < 200; $i++) {
            $inputs[] = $random->getBytes($random->getInt(1, 600));
        }
        foreach ($inputs as $index => $input) {
            $php = NzbSourceGuard::digestPhp($input);
            self::assertSame($php, NzbSourceGuard::digestNative($input), "digest case {$index}");
            self::assertSame($php, NzbSourceGuard::digest($input));
        }
        NzbSourceGuard::setUseRustFfi(false);
        self::assertNull(NzbSourceGuard::digestNative('x'));
        self::assertSame(NzbSourceGuard::digestPhp('x'), NzbSourceGuard::digest('x'));
    }

    #[Test]
    public function staged_guid_parity(): void
    {
        $names = [
            self::GUID.'.nzb.gz.c12-0123456789abcdef.next',
            'a.nzb.gz.c1.nzb.gz.c2-0123456789abcdef.next',
            '.nzb.gz.c2-0123456789abcdef.next',
            'x.nzb.gz.c-0123456789abcdef.next',
            'x.nzb.gz.c1-0123456789ABCDEF.next',
            'x.nzb.gz.c1-0123456789abcde.next',
            'x.nzb.gz.c1-0123456789abcdef0.next',
            "x\n.nzb.gz.c1-0123456789abcdef.next",
            "x.nzb.gz.c1-0123456789abcdef.next\n",
            "\r.nzb.gz.c1-0123456789abcdef.next",
            'x.nzb.gz.c٣-0123456789abcdef.next',
            'é.nzb.gz.c007-ffffffffffffffff.next',
            self::GUID.'.nzb.gz',
            '',
            '.next',
        ];
        $random = new Randomizer(new Mt19937(7));
        $alphabet = "ab.\n9cz-gnxte0f";
        for ($i = 0; $i < 3000; $i++) {
            $prefix = '';
            for ($j = $random->getInt(0, 4); $j > 0; $j--) {
                $prefix .= $alphabet[$random->getInt(0, strlen($alphabet) - 1)];
            }
            $digits = substr('0123456789', 0, $random->getInt(0, 3));
            $hex = substr(str_repeat('a1', 9), 0, $random->getInt(15, 17));
            $names[] = $prefix.($random->getInt(0, 4) === 0 ? '.nzb.gz.' : '.nzb.gz.c').$digits.'-'.$hex.($random->getInt(0, 5) === 0 ? '.nex' : '.next');
        }
        foreach ($names as $index => $name) {
            $supported = false;
            $php = NzbSourceGuard::stagedGuidPhp($name);
            self::assertSame($php, NzbSourceGuard::stagedGuidNative($name, $supported), "staged case {$index}: ".json_encode($name));
            self::assertTrue($supported);
            self::assertSame($php, NzbSourceGuard::stagedGuid($name));
        }
    }

    /** @return list<array<string, mixed>> */
    private static function anchorReceipts(): array
    {
        $guid = self::GUID;
        $dir = '/srv/nzb/0/1';
        $inode = ['version' => 1, 'kind' => 'inode', 'guid' => $guid, 'path' => "{$dir}/{$guid}.nzb.gz",
            'anchor' => "{$dir}/.archive-audit-{$guid}-00112233445566778899aabbccddeeff.anchor", 'dev' => '16777231', 'ino' => '98765432'];
        $receipts = [$inode, ['version' => 1, 'kind' => 'absent', 'guid' => $guid, 'path' => "{$dir}/{$guid}.nzb"]];
        $mutations = [
            ['version' => '1'], ['version' => 2], ['kind' => 'database'], ['kind' => null], ['guid' => ''], ['guid' => 5],
            ['guid' => "a\0b"], ['guid' => str_repeat('a', 41)], ['guid' => 'bad guid'], ['guid' => 'ABC_def-1'],
            ['path' => "{$dir}/{$guid}.nzb.gz/"], ['path' => "{$guid}.nzb.gz"], ['path' => "{$dir}/{$guid}.nzb.bz2"],
            ['path' => "{$dir}/x{$guid}.nzb"], ['path' => "{$dir}//{$guid}.nzb.gz"], ['anchor' => "{$dir}//.archive-audit-{$guid}-00112233445566778899aabbccddeeff.anchor"],
            ['anchor' => "/elsewhere/.archive-audit-{$guid}-00112233445566778899aabbccddeeff.anchor"],
            ['anchor' => "{$dir}/.archive-audit-{$guid}-00112233445566778899AABBCCDDEEFF.anchor"],
            ['anchor' => "{$dir}/.archive-audit-{$guid}-00112233445566778899aabbccddeef.anchor"],
            ['anchor' => "{$dir}/.archive-audit-{$guid}-00112233445566778899aabbccddeeff.anchor\n"],
            ['anchor' => "{$dir}/.archive-audit-{$guid}-00112233445566778899aabbccddeeff.anchor/"],
            ['anchor' => ''], ['anchor' => 7], ['dev' => '-1'], ['dev' => '1.5'], ['dev' => ''], ['dev' => 16777231],
            ['ino' => ' 1'], ['ino' => "1\0"], ['dev' => '00'], ['ino' => '٣'],
        ];
        foreach ($mutations as $mutation) {
            $receipts[] = array_replace($inode, $mutation);
            $receipts[] = array_replace($receipts[1], $mutation);
        }
        foreach (['guid', 'path', 'anchor', 'dev', 'ino', 'version', 'kind'] as $key) {
            $missing = $inode;
            unset($missing[$key]);
            $receipts[] = $missing;
        }
        $root = $inode;
        $root['path'] = "/{$guid}.nzb.gz";
        $root['anchor'] = "/.archive-audit-{$guid}-00112233445566778899aabbccddeeff.anchor";
        $receipts[] = $root;
        $guidDash = array_replace($inode, ['guid' => 'a-b', 'path' => "{$dir}/a-b.nzb.gz", 'anchor' => "{$dir}/.archive-audit-a-b-00112233445566778899aabbccddeeff.anchor"]);
        $receipts[] = $guidDash;

        return $receipts;
    }

    #[Test]
    public function validate_anchor_parity(): void
    {
        foreach (self::anchorReceipts() as $index => $receipt) {
            $supported = false;
            $php = self::outcome(static fn (): array => NzbSourceGuard::validateAnchorPhp($receipt));
            $native = self::outcome(static function () use ($receipt, &$supported): ?array {
                return NzbSourceGuard::validateAnchorNative($receipt, $supported);
            });
            self::assertTrue($supported, "anchor case {$index}");
            self::assertSame($php, $native, "anchor case {$index}: ".json_encode($receipt));
        }
    }

    #[Test]
    public function matches_anchor_parity(): void
    {
        $receipt = ['dev' => '16777231', 'ino' => '98765432'];
        $stats = [false, ['mode' => 0100644, 'dev' => 16777231, 'ino' => 98765432], ['mode' => 0120777, 'dev' => 16777231, 'ino' => 98765432],
            ['mode' => 0040755, 'dev' => 16777231, 'ino' => 98765432], ['mode' => 0100600, 'dev' => 16777232, 'ino' => 98765432],
            ['mode' => 0100600, 'dev' => 16777231, 'ino' => 98765433], ['mode' => 0, 'dev' => 0, 'ino' => 0]];
        $receipts = [$receipt, ['dev' => '016777231', 'ino' => '98765432'], ['dev' => '0', 'ino' => '0'], ['dev' => '-5', 'ino' => '0'],
            ['dev' => (string) PHP_INT_MAX, 'ino' => (string) PHP_INT_MIN]];
        $stats[] = ['mode' => 0100000, 'dev' => -5, 'ino' => 0];
        $stats[] = ['mode' => 0100000, 'dev' => PHP_INT_MAX, 'ino' => PHP_INT_MIN];
        foreach ($stats as $i => $stat) {
            foreach ($receipts as $j => $candidate) {
                $php = NzbSourceGuard::matchesAnchorPhp($stat, $candidate);
                self::assertSame($php, NzbSourceGuard::matchesAnchorNative($stat, $candidate), "matches case {$i}/{$j}");
            }
        }
    }

    #[Test]
    public function upload_manifest_parity(): void
    {
        $names = ['Some.Release.nzb', 'Some.Release.NZB', 'Some.Release.nZb', 'x.nfo', '.nzb', ' .nzb', "\t\n\r\0\x0B.nzb",
            "\x0C.nzb", '.x.nzb', 'a.nzb.', 'a.nzb ', 'a/b.nzb', 'a.nzb/', '/a.nzb', '.', '..', '', "a\0.nzb", 'nzb',
            'a..nzb', 'ä.NZB', 'a.nzbx', 'a.Nfo', '...nzb', 'a.b.c.nfo', 'a\\b.nzb', str_repeat('a', 300).'.nzb', 'É.NZB', "a.nz\xC3\x9F"];
        foreach (['nzb', 'nfo', 'NZB', ''] as $extension) {
            foreach ($names as $index => $name) {
                $php = self::outcome(static function () use ($name, $extension): bool {
                    NzbUploadManifestService::assertSafeFilenamePhp($name, $extension);

                    return true;
                });
                $native = self::outcome(static fn (): bool => NzbUploadManifestService::assertSafeFilenameNative($name, $extension));
                self::assertSame($php, $native, "filename case {$index} ({$extension}): ".json_encode($name));
            }
        }

        $hex = str_repeat('0123456789', 4);
        $manifests = [];
        foreach (['nzb_pending', 'metadata_review', 'metadata_deferred', 'staged', 'nzb_imported', 'NZB_PENDING', '', null, 1, true] as $state) {
            foreach ([$hex, str_repeat('a', 40), str_repeat('A', 40), str_repeat('g', 40), substr($hex, 0, 39), $hex.'0', "{$hex}\n", null, 5, false] as $identity) {
                $manifests[] = ['state' => $state, 'candidate_identity' => $identity];
            }
            $manifests[] = ['state' => $state];
        }
        foreach ($manifests as $index => $manifest) {
            self::assertSame(NzbUploadManifestService::canReconcileCandidatePhp($manifest),
                NzbUploadManifestService::canReconcileCandidateNative($manifest), "reconcile case {$index}");
        }
    }

    #[Test]
    public function password_metadata_parity(): void
    {
        $values = [null, 'x', 1, [], [1 => 'a'], ['a' => 'b'], ['secret'], ['secret', 5], ['secret', null], array_fill(0, 64, 'p'),
            array_fill(0, 65, 'p'), [str_repeat('a', 4096)], [str_repeat('a', 4097)], [str_repeat('é', 2048)], [str_repeat('é', 2048).'a'],
            ["tab\tnl\ncr\r"], ["bad\x01"], ["bad\x7F\u{85}ok"], ["\u{D7FF}\u{E000}\u{FFFD}\u{10000}\u{10FFFF}"], ["\u{FFFE}"], ["\u{FFFF}"],
            ["\xED\xA0\x80"], ["\xC3"], ["\xC3", "\xA9"], ["\xC0\xAF"], ["\xF4\x90\x80\x80"], ['', ''], ['ok', "\x00"], ['пароль', '密码', '🔒']];
        $random = new Randomizer(new Mt19937(99));
        for ($i = 0; $i < 1000; $i++) {
            $list = [];
            for ($j = $random->getInt(0, 3); $j > 0; $j--) {
                $list[] = $random->getInt(0, 1) === 0 ? $random->getBytes($random->getInt(1, 12))
                    : mb_chr($random->getInt(0x20, 0x2FFFF), 'UTF-8').mb_chr($random->getInt(0x80, 0x10FFFF), 'UTF-8');
            }
            $values[] = $list;
        }
        foreach ($values as $index => $value) {
            $php = NzbPasswordMetadata::isValidPhp($value);
            self::assertSame($php, NzbPasswordMetadata::isValidNative($value), "password case {$index}");
            self::assertSame($php, NzbPasswordMetadata::isValid($value));
        }
    }

    #[Test]
    public function validate_part_parity(): void
    {
        $name = 'Some.Release.vol003+04.par2';
        $b64 = base64_encode($name);
        $parts = [null, new YencPart($name, 1000, 2, 501, 1000), new YencPart($name, 1000, null, 501, 1000), new YencPart($name, 1000, 3, 501, 1000),
            new YencPart($name, 1000, 2, 500, 1000), new YencPart($name, 1000, 2, 501, 999), new YencPart($name, 1000, 2, 501, 1001),
            new YencPart($name, 1000, 2, 501, 400), new YencPart($name, 1000, 2, null, 1000), new YencPart($name, 1000, 2, 501, null),
            new YencPart($name, 1000, 2), new YencPart($name, 500, 1), new YencPart($name, 500, null), new YencPart('', 1000, 2, 501, 1000),
            new YencPart("a\rb", 1000, 2, 501, 1000), new YencPart("a\0b", 1000, 2, 501, 1000), new YencPart(str_repeat('n', 65537), 1000, 2, 501, 1000),
            new YencPart(str_repeat('n', 65536), 1000, 2, 501, 1000), new YencPart($name, 0, 2, 501, 1000), new YencPart($name, -1, 2, 501, 1000),
            new YencPart('other.par2', 1000, 2, 501, 1000), new YencPart($name, 999, 2, 501, 999), new YencPart($name, PHP_INT_MAX, 2, PHP_INT_MIN, PHP_INT_MAX),
            new YencPart($name, PHP_INT_MAX, 1, 1, PHP_INT_MAX), new YencPart('ab', 1000, 2, 501, 1000), new YencPart('abc', 1000, 2, 501, 1000),
            new YencPart("\xff\xfe\x80", 1000, 2, 501, 1000)];
        $identities = [null, ['name_base64' => $b64, 'size' => 1000, 'part' => 1], ['name_base64' => $b64, 'size' => 1000, 'part' => null],
            ['name_base64' => $b64, 'size' => 999, 'part' => 1], ['name_base64' => base64_encode('other.par2'), 'size' => 1000, 'part' => 1],
            ['name_base64' => $b64, 'size' => 1000, 'part' => PHP_INT_MAX], ['name_base64' => $b64, 'size' => 1000, 'part' => 2],
            ['name_base64' => rtrim($b64, '='), 'size' => 1000, 'part' => 1], ['name_base64' => '', 'size' => 1000, 'part' => 1],
            ['name_base64' => base64_encode('ab'), 'size' => 1000, 'part' => 1], ['name_base64' => base64_encode('abc'), 'size' => 1000, 'part' => 1],
            ['name_base64' => base64_encode("\xff\xfe\x80"), 'size' => 1000, 'part' => 1], ['name_base64' => $b64, 'size' => PHP_INT_MAX, 'part' => null]];
        $bodies = ['', str_repeat('b', 499), str_repeat('b', 500), str_repeat('b', 1000)];
        $contexts = [[2, 2, 500], [1, 1, 0], [2, 3, 500], [1, 2, 0], [2, 2, 0], [2, 2, -1], [2, 2, PHP_INT_MAX], [2, 2, PHP_INT_MIN], [1, 2, 500]];
        $checked = 0;
        foreach ($parts as $p => $part) {
            foreach ($identities as $i => $identity) {
                foreach ($bodies as $b => $body) {
                    foreach ($contexts as $c => [$segment, $count, $offset]) {
                        $supported = false;
                        $php = self::outcome(static fn (): ?array => Par2CandidateRecovery::validatePartPhp($part, $body, $segment, $count, $offset, $identity));
                        $native = self::outcome(static function () use ($part, $body, $segment, $count, $offset, $identity, &$supported): ?array {
                            return Par2CandidateRecovery::validatePartNative($part, $body, $segment, $count, $offset, $identity, $supported);
                        });
                        self::assertTrue($supported);
                        self::assertSame($php, $native, "part case {$p}/{$i}/{$b}/{$c}");
                        $checked++;
                    }
                }
            }
        }
        self::assertGreaterThan(10000, $checked);
    }

    #[Test]
    public function c_abi_null_pointer_and_small_buffer_contracts(): void
    {
        $ffi = RustPipelineLibrary::resolveDomain('additional', NzbSourceGuard::FFI_CDEF."\n".NzbUploadManifestService::FFI_CDEF."\n"
            .Par2CandidateRecovery::FFI_CDEF."\n".NzbPasswordMetadata::FFI_CDEF);
        self::assertNotNull($ffi);
        $out = $ffi->new('char[64]');
        // @phpstan-ignore method.notFound
        self::assertSame(-1, $ffi->nntmux_nzb_source_digest(null, 1, $out, 64));
        // @phpstan-ignore method.notFound
        self::assertSame(-2, $ffi->nntmux_nzb_source_digest('x', 1, $out, 63));
        // @phpstan-ignore method.notFound
        self::assertSame(64, $ffi->nntmux_nzb_source_digest(null, 0, $out, 64));
        // @phpstan-ignore method.notFound
        self::assertSame(-1, $ffi->nntmux_nzb_source_staged_guid_len(null, 3));
        // @phpstan-ignore method.notFound
        self::assertSame(-1, $ffi->nntmux_nzb_source_validate_anchor(1, null, 1, 'a', 1, null, 0, null, 0, null, 0));
        // @phpstan-ignore method.notFound
        self::assertSame(-1, $ffi->nntmux_nzb_source_matches_anchor(0100644, 1, 1, null, 1, '1', 1));
        // @phpstan-ignore method.notFound
        self::assertSame(-1, $ffi->nntmux_upload_manifest_safe_filename(null, 1, 'nzb', 3));
        // @phpstan-ignore method.notFound
        self::assertSame(-1, $ffi->nntmux_upload_manifest_can_reconcile(null, 1, 1, null, 0, 0));
        // @phpstan-ignore method.notFound
        self::assertSame(-1, $ffi->nntmux_nzb_password_metadata_valid(null, 1, null, 0));
        // @phpstan-ignore method.notFound
        self::assertSame(-1, $ffi->nntmux_nzb_password_metadata_valid('ab', 2, pack('Q', 3), 1));
        // @phpstan-ignore method.notFound
        self::assertSame(-1, $ffi->nntmux_nzb_password_metadata_valid('ab', 2, pack('Q', 1), 1));
        // @phpstan-ignore method.notFound
        self::assertSame(0, $ffi->nntmux_nzb_password_metadata_valid(null, 0, null, 65));
        // @phpstan-ignore method.notFound
        self::assertSame(-1, $ffi->nntmux_par2_recovery_validate_part(1, null, 1, 1, 0, 0, 0, 0, 0, 0, 1, 1, 1, 0, 0, null, 0, 0, 0, 0));
    }

    /**
     * NNTMUX_NZB_CUSTODY_BENCHMARK=1 php -d opcache.enable_cli=1 artisan test --compact
     * tests/Unit/RustNzbCustodyAndPar2RecoveryParityTest.php --filter=paired_benchmark
     */
    #[Test]
    public function paired_benchmark(): void
    {
        if (getenv('NNTMUX_NZB_CUSTODY_BENCHMARK') !== '1') {
            self::markTestSkipped('Opt in with NNTMUX_NZB_CUSTODY_BENCHMARK=1 to run paired randomized benchmarks.');
        }
        $random = new Randomizer(new Mt19937(20261024));
        $guid = self::GUID;
        $nzbCommon = self::nzbXml(30, 120);       // ~0.5 MB: a typical multi-volume release NZB
        $nzbUncommon = self::nzbXml(110, 600);    // ~9 MB: a large season-pack NZB
        $nzbTiny = self::nzbXml(1, 2);            // <1 KB: a minimal single-article NZB
        $receipt = self::anchorReceipts()[0];
        $receiptAbsent = ['version' => 1, 'kind' => 'absent', 'guid' => $guid, 'path' => '/srv/nntmux/resources/nzb/0/1/2/3/'.$guid.'.nzb'];
        $stat = ['mode' => 0100644, 'dev' => 16777231, 'ino' => 98765432];
        $statMismatch = ['mode' => 0100644, 'dev' => 16777231, 'ino' => 98765433];
        $anchorDigits = ['dev' => '16777231', 'ino' => '98765432'];
        $staged = $guid.'.nzb.gz.c4815162342-0123456789abcdef.next';
        $stagedUncommon = str_repeat('Long.Legacy.Release.Name-', 8).'.nzb.gz.c1-0123456789abcdef.next';
        $filename = 'Some.Release.2024.1080p.WEB-DL.H264-GRP.nzb';
        $filenameUncommon = str_repeat('Very.Long.Upload.Name.', 11).'NFO';
        $pending = ['state' => 'nzb_pending', 'candidate_identity' => null];
        $review = ['state' => 'metadata_review', 'candidate_identity' => sha1('candidate')];
        $passwordsCommon = ['secret'];
        $passwordsUncommon = array_fill(0, 64, str_repeat('Pässwörd-密码-', 200));
        $name = 'Some.Release.2024.1080p.WEB-DL.vol031+32.par2';
        $body = str_repeat('b', 768000);
        $partCommon = new YencPart($name, 15360000, 3, 1536001, 2304000);
        $identityCommon = ['name_base64' => base64_encode($name), 'size' => 15360000, 'part' => 2];
        $partUncommon = new YencPart(str_repeat('n', 4096).'.par2', 768000);

        $workloads = [
            'digest[common:0.5MB]' => [static fn () => NzbSourceGuard::digestPhp($nzbCommon), static fn () => NzbSourceGuard::digestNative($nzbCommon), 30],
            'digest[uncommon:9MB]' => [static fn () => NzbSourceGuard::digestPhp($nzbUncommon), static fn () => NzbSourceGuard::digestNative($nzbUncommon), 5],
            'digest[uncommon:tiny]' => [static fn () => NzbSourceGuard::digestPhp($nzbTiny), static fn () => NzbSourceGuard::digestNative($nzbTiny), 50],
            'validateAnchor[common:inode]' => [static fn () => NzbSourceGuard::validateAnchorPhp($receipt), static fn () => NzbSourceGuard::validateAnchorNative($receipt), 50],
            'validateAnchor[uncommon:absent]' => [static fn () => NzbSourceGuard::validateAnchorPhp($receiptAbsent), static fn () => NzbSourceGuard::validateAnchorNative($receiptAbsent), 50],
            'matchesAnchor[common:match]' => [static fn () => NzbSourceGuard::matchesAnchorPhp($stat, $anchorDigits), static fn () => NzbSourceGuard::matchesAnchorNative($stat, $anchorDigits), 50],
            'matchesAnchor[uncommon:mismatch]' => [static fn () => NzbSourceGuard::matchesAnchorPhp($statMismatch, $anchorDigits), static fn () => NzbSourceGuard::matchesAnchorNative($statMismatch, $anchorDigits), 50],
            'stagedGuid[common]' => [static fn () => NzbSourceGuard::stagedGuidPhp($staged), static fn () => NzbSourceGuard::stagedGuidNative($staged), 50],
            'stagedGuid[uncommon:long]' => [static fn () => NzbSourceGuard::stagedGuidPhp($stagedUncommon), static fn () => NzbSourceGuard::stagedGuidNative($stagedUncommon), 50],
            'assertSafeFilename[common]' => [static fn () => NzbUploadManifestService::assertSafeFilenamePhp($filename, 'nzb'), static fn () => NzbUploadManifestService::assertSafeFilenameNative($filename, 'nzb'), 50],
            'assertSafeFilename[uncommon:long]' => [static fn () => NzbUploadManifestService::assertSafeFilenamePhp($filenameUncommon, 'nfo'), static fn () => NzbUploadManifestService::assertSafeFilenameNative($filenameUncommon, 'nfo'), 50],
            'canReconcileCandidate[common:pending]' => [static fn () => NzbUploadManifestService::canReconcileCandidatePhp($pending), static fn () => NzbUploadManifestService::canReconcileCandidateNative($pending), 50],
            'canReconcileCandidate[uncommon:review]' => [static fn () => NzbUploadManifestService::canReconcileCandidatePhp($review), static fn () => NzbUploadManifestService::canReconcileCandidateNative($review), 50],
            'isValid[common:1]' => [static fn () => NzbPasswordMetadata::isValidPhp($passwordsCommon), static fn () => NzbPasswordMetadata::isValidNative($passwordsCommon), 50],
            'isValid[uncommon:64x4KB]' => [static fn () => NzbPasswordMetadata::isValidPhp($passwordsUncommon), static fn () => NzbPasswordMetadata::isValidNative($passwordsUncommon), 20],
            'validatePart[common:multipart]' => [static fn () => Par2CandidateRecovery::validatePartPhp($partCommon, $body, 3, 10, 1536000, $identityCommon), static fn () => Par2CandidateRecovery::validatePartNative($partCommon, $body, 3, 10, 1536000, $identityCommon), 50],
            'validatePart[uncommon:single-4KB-name]' => [static fn () => Par2CandidateRecovery::validatePartPhp($partUncommon, $body, 1, 1, 0, null), static fn () => Par2CandidateRecovery::validatePartNative($partUncommon, $body, 1, 1, 0, null), 50],
        ];
        $p99 = static function (array $values): float {
            sort($values);

            return $values[(int) ceil(count($values) * 0.99) - 1];
        };
        $only = getenv('NNTMUX_NZB_CUSTODY_BENCHMARK_FILTER') ?: null;
        foreach ($workloads as $label => [$phpFn, $rustFn, $calls]) {
            if ($only !== null && ! str_contains($label, $only)) {
                continue;
            }
            self::assertSame($phpFn() ?? true, $rustFn() ?? true, "Parity check failed for {$label}");
            for ($w = 0; $w < 50; $w++) {
                $phpFn();
                $rustFn();
            }
            $samples = ['php' => [], 'rust' => []];
            for ($pair = 0; $pair < 60; $pair++) {
                $order = $random->getInt(0, 1) === 0 ? ['php', 'rust'] : ['rust', 'php'];
                foreach ($order as $variant) {
                    $fn = $variant === 'php' ? $phpFn : $rustFn;
                    for ($call = 0; $call < $calls; $call++) {
                        $start = hrtime(true);
                        $fn();
                        $samples[$variant][] = hrtime(true) - $start;
                    }
                }
            }
            $summary = ['samples' => count($samples['php']),
                'php' => ['mean_ns' => round(array_sum($samples['php']) / count($samples['php']), 1), 'p99_ns' => $p99($samples['php'])],
                'rust' => ['mean_ns' => round(array_sum($samples['rust']) / count($samples['rust']), 1), 'p99_ns' => $p99($samples['rust'])]];
            $summary['mean_speedup'] = round($summary['php']['mean_ns'] / $summary['rust']['mean_ns'], 2);
            $summary['p99_speedup'] = round($summary['php']['p99_ns'] / $summary['rust']['p99_ns'], 2);
            fwrite(STDERR, "{$label} ".json_encode($summary, JSON_THROW_ON_ERROR)."\n");
        }
        $this->addToAssertionCount(1);
    }
}
