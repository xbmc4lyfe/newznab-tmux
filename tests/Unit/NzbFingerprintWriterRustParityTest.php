<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Nzb\NzbArticleFingerprint;
use App\Support\RustPipelineLibrary;
use FFI;
use PHPUnit\Framework\TestCase;

/**
 * The Rust NZB fingerprint, Message-ID and password-metadata functions must reproduce the PHP implementations.
 */
final class NzbFingerprintWriterRustParityTest extends TestCase
{
    private const CDEF = <<<'C'
        ssize_t nntmux_nzb_normalize_message_id(const char *input, size_t input_len, char *out_buf, size_t out_cap);
        ssize_t nntmux_nzb_validate_message_id(const char *input, size_t input_len, char *out_buf, size_t out_cap);
        ssize_t nntmux_nzb_article_fingerprint_batch(const char *buf, size_t buf_len, uint32_t count, uint32_t already_validated, char *out_hex_40);
        uint32_t nntmux_nzb_validate_passwords(const char *buf, size_t buf_len, uint32_t count);
        C;

    private FFI $ffi;

    protected function setUp(): void
    {
        parent::setUp();
        $ffi = RustPipelineLibrary::resolveDomain('nzb_fingerprint_writer', self::CDEF);
        if ($ffi === null) {
            self::markTestSkipped('The Rust pipeline library is not built.');
        }
        $this->ffi = $ffi;
    }

    private function normalizeNative(string $id): string
    {
        $out = $this->ffi->new('char[512]');
        // @phpstan-ignore method.notFound
        $written = $this->ffi->nntmux_nzb_normalize_message_id($id, strlen($id), $out, 512);

        return FFI::string($out, $written);
    }

    private function validateNative(string $id): ?string
    {
        $out = $this->ffi->new('char[512]');
        // @phpstan-ignore method.notFound
        $written = $this->ffi->nntmux_nzb_validate_message_id($id, strlen($id), $out, 512);

        return $written > 0 ? FFI::string($out, $written) : null;
    }

    private function batchNative(string $buffer, int $count, int $validated = 0): int
    {
        $out = $this->ffi->new('char[64]');

        // @phpstan-ignore method.notFound
        return $this->ffi->nntmux_nzb_article_fingerprint_batch($buffer, strlen($buffer), $count, $validated, $out);
    }

    private function passwordsNative(mixed $values): bool
    {
        if (! is_array($values) || ! array_is_list($values) || count($values) > 64) {
            return false;
        }
        foreach ($values as $value) {
            if (! is_string($value) || strlen($value) > 4096) {
                return false;
            }
        }
        $joined = implode("\0", $values);

        // @phpstan-ignore method.notFound
        return $this->ffi->nntmux_nzb_validate_passwords($joined, strlen($joined), count($values)) === 1;
    }

    private static function seed(): int
    {
        $override = getenv('NZB_FUZZ_SEED');

        return $override !== false && ctype_digit($override) ? (int) $override : 8_675_309;
    }

    private static function randomBytes(int $length, string $alphabet): string
    {
        $out = '';
        $max = strlen($alphabet) - 1;
        for ($i = 0; $i < $length; $i++) {
            $out .= $alphabet[mt_rand(0, $max)];
        }

        return $out;
    }

    /** @return list<string> */
    private static function messageIdCorpus(int $count): array
    {
        mt_srand(self::seed());
        $wrappers = [['', ''], ['<', '>'], ['"', '"'], ["'", "'"], ['"<', '>"'], [' <', '> '], ["\t'", "'\n"], ['<<', '>>'], ['"', "'"], ["\x0b", "\x0b"], ["\x0c", ''], ['< ', ' >']];
        $alphabet = 'abcdefghijklmnopqrstuvwxyz0123456789@.-_=+$%'."\x7f \x01\xc3\xa9";
        $ids = [];
        for ($i = 0; $i < $count; $i++) {
            [$left, $right] = $wrappers[mt_rand(0, count($wrappers) - 1)];
            $body = self::randomBytes(mt_rand(0, 12) === 0 ? mt_rand(250, 260) : mt_rand(0, 40), $alphabet);
            $ids[] = $left.$body.$right;
        }
        $ids[] = '';
        $ids[] = '<>';
        $ids[] = '"';
        $ids[] = "'<>'";
        $ids[] = str_repeat('x', 255);
        $ids[] = '<'.str_repeat('x', 255).'>';
        $ids[] = str_repeat('x', 256);

        return $ids;
    }

    public function test_normalize_and_validate_match_php(): void
    {
        foreach (self::messageIdCorpus(4000) as $id) {
            self::assertSame(NzbArticleFingerprint::normalizeMessageId($id), $this->normalizeNative($id), bin2hex($id));
            self::assertSame(NzbArticleFingerprint::validatedMessageId($id), $this->validateNative($id), bin2hex($id));
        }
    }

    public function test_normalize_embedded_nul_is_trimmed_like_php(): void
    {
        foreach (["\0<a@b>\0", "a\0b", "\0", " \0 <x> \0 "] as $id) {
            self::assertSame(NzbArticleFingerprint::normalizeMessageId($id), $this->normalizeNative($id));
        }
    }

    /** @param list<string> $ids */
    private static function phpFingerprint(array $ids): ?string
    {
        $fingerprint = new NzbArticleFingerprint(static fn () => tmpfile());
        foreach ($ids as $id) {
            $fingerprint->add($id);
        }

        return $fingerprint->value();
    }

    public function test_fingerprint_matches_php_streaming_implementation(): void
    {
        mt_srand(self::seed());
        $alphabet = 'abcdefghijklmnopqrstuvwxyz0123456789@.-_';
        foreach ([1, 2, 3, 17, 250, 1000, 4000, 4096, 5000] as $size) {
            $ids = [];
            for ($i = 0; $i < $size; $i++) {
                $ids[] = self::randomBytes(mt_rand(1, 60), $alphabet).'@x.invalid';
            }
            // Duplicates and decorations must not change the identity.
            for ($i = 0; $i < intdiv($size, 5); $i++) {
                $ids[] = '<'.$ids[mt_rand(0, $size - 1)].'>';
            }
            shuffle($ids);
            $expected = self::phpFingerprint($ids);
            self::assertNotNull($expected);

            self::assertSame($expected, NzbArticleFingerprint::fromMessageIds($ids), "fromMessageIds size {$size}");
            self::assertSame($expected, NzbArticleFingerprint::fromMessageIds((static function () use ($ids) {
                yield from $ids;
            })()), "generator size {$size}");

            if (count($ids) < 4096) {
                $native = new NzbArticleFingerprint;
                foreach ($ids as $id) {
                    $native->add($id);
                }
                self::assertSame($expected, $native->value(), "value size {$size}");
                self::assertSame($expected, $native->value(), "repeat value size {$size}");
            }
        }
    }

    public function test_value_after_additional_ids_covers_buffered_ids(): void
    {
        $fingerprint = new NzbArticleFingerprint;
        $fingerprint->add('<a@x>');
        $first = $fingerprint->value();
        self::assertSame(self::phpFingerprint(['a@x']), $first);
        $fingerprint->add('b@x');
        self::assertSame(self::phpFingerprint(['a@x', 'b@x']), $fingerprint->value());
    }

    public function test_unsupported_and_empty_inputs_remain_unknown(): void
    {
        self::assertNull(NzbArticleFingerprint::fromMessageIds([]));
        self::assertNull(NzbArticleFingerprint::fromMessageIds(['a@x', 'bad id']));
        self::assertNull(NzbArticleFingerprint::fromMessageIds(['a@x', '']));
        self::assertNull(NzbArticleFingerprint::fromMessageIds(['a@x', "b\0c"]));
        self::assertNull(NzbArticleFingerprint::fromMessageIds(['a@x', str_repeat('y', 256)]));
        self::assertNull(self::phpFingerprint(['a@x', "b\0c"]));
    }

    public function test_batch_rejects_count_mismatch(): void
    {
        $buf = "a@x\0b@x";
        self::assertSame(0, $this->batchNative($buf, 0));
        self::assertSame(0, $this->batchNative($buf, 1));
        self::assertSame(0, $this->batchNative($buf, 3));
        self::assertSame(40, $this->batchNative($buf, 2));
    }

    private static function phpPasswordsValid(mixed $values): bool
    {
        if (! is_array($values) || ! array_is_list($values) || count($values) > 64) {
            return false;
        }
        foreach ($values as $value) {
            if (! is_string($value) || strlen($value) > 4096
                || preg_match('/[^\x{0009}\x{000A}\x{000D}\x{0020}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', $value) !== 0) {
                return false;
            }
        }

        return true;
    }

    public function test_password_validation_matches_php_reference(): void
    {
        $samples = [
            [], [''], ['secret'], ["tab\tnl\ncr\r"], ["\u{10000}\u{E000}\u{FFFD}\u{D7FF}"], ["bad\x01"], ["nul\0byte"],
            ["\u{FFFE}"], ["\u{FFFF}"], ["\xff"], ["\xed\xa0\x80"], ["\xc0\xaf"], ["\u{7f}\u{80}\u{9f}"], [str_repeat('a', 4096)],
            [str_repeat('a', 4097)], array_fill(0, 64, 'x'), array_fill(0, 65, 'x'), [1], ['a', null], ['k' => 'v'], [1 => 'a'],
            'string', null, ['a', "b\0"], ["\0", 'a'], ["\u{1F600}", 'ok'],
        ];
        mt_srand(self::seed());
        for ($i = 0; $i < 3000; $i++) {
            $values = [];
            for ($j = mt_rand(0, 4); $j > 0; $j--) {
                $values[] = self::randomBytes(mt_rand(0, 12), "ab \t\n\r\x01\x7f\x80\xc3\xa9\xe2\x82\xac\xef\xbf\xbe\xf0\x9f\x98\x80\xed\xa0\xff");
            }
            $samples[] = $values;
        }
        foreach ($samples as $values) {
            self::assertSame(self::phpPasswordsValid($values), $this->passwordsNative($values), json_encode($values, JSON_INVALID_UTF8_SUBSTITUTE) ?: '');
        }
    }
}
