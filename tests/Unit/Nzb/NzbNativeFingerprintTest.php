<?php

declare(strict_types=1);

namespace Tests\Unit\Nzb;

use App\Services\Nzb\NzbArticleFingerprint;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class NzbNativeFingerprintTest extends TestCase
{
    public function test_a_missing_native_library_cannot_silently_disable_article_identity(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Rust article fingerprint');

        new NzbArticleFingerprint('/missing/nntmux-fingerprint-library.so');
    }

    public function test_the_worker_preserves_digest_identity_when_ffi_is_disabled(): void
    {
        $binary = dirname(__DIR__, 3).'/docker/app/nzb-yenc-ffi/target/release/nntmux-article-fingerprint';
        $fingerprint = new NzbArticleFingerprint(workerPath: $binary);
        $fingerprint->add('a1@x');
        $fingerprint->add(' "<a2@x>" ');
        $this->assertSame('2fd356d1823545ef8a5282116a7a891fdfdaf0a6', $fingerprint->value());
        $fingerprint->add('<a1@x>');
        $this->assertSame('2fd356d1823545ef8a5282116a7a891fdfdaf0a6', $fingerprint->value());
        $fingerprint->add('invalid id');
        $this->assertNull($fingerprint->value());
    }

    public function test_native_state_cannot_be_cloned_and_freed_twice(): void
    {
        $fingerprint = new NzbArticleFingerprint;
        $fingerprint->add('a1@x');
        try {
            clone $fingerprint;
            $this->fail('Raw native state must not be shared by a shallow clone.');
        } catch (\Error) {
            $this->assertSame('764304775c571be7575ff3978f5ca595beeeb322', $fingerprint->value());
        }
    }
}
