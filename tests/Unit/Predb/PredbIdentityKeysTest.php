<?php

declare(strict_types=1);

namespace Tests\Unit\Predb;

use App\Services\NameFixing\OriginalFilenameEvidence;
use App\Services\Predb\PredbIdentityKeys;
use App\Support\PredbSearchDocument;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PredbIdentityKeysTest extends TestCase
{
    public function test_blank_alias_has_only_the_exact_canonical_key(): void
    {
        self::assertSame([
            ['kind' => 1, 'key_digest' => hash('sha256', PredbSearchDocument::exactValue('Synthetic.Canonical-GRP'))],
        ], (new PredbIdentityKeys)->forRow((object) ['id' => 7, 'title' => 'Synthetic.Canonical-GRP', 'filename' => '']));
    }

    public static function aliases(): array
    {
        return [
            ['Synthetic.Alias-GRP.rar.par2'],
            ['folder/Synthetic.Alias-GRP.mkv'],
            ['ΣΥΝΘΕΤΙΚΟΣ-GRP.mkv'],
            ['123.mkv'],
        ];
    }

    #[DataProvider('aliases')]
    public function test_alias_projection_preserves_every_actual_helper_nomination(string $alias): void
    {
        $keys = (new PredbIdentityKeys)->forRow((object) ['id' => 7, 'title' => 'Synthetic.Canonical-GRP', 'filename' => $alias]);
        self::assertNotNull($keys);
        $actual = array_column(array_filter($keys, static fn (array $key): bool => $key['kind'] === 2), 'key_digest');
        $nominations = (new OriginalFilenameEvidence($alias))->canonicalNominationKeys();
        self::assertNotNull($nominations);
        self::assertSame(array_map(static fn (string $key): string => hash('sha256', $key), $nominations), $actual);
    }

    public static function unsupportedRows(): array
    {
        $valid = ['id' => 7, 'title' => 'Synthetic.Canonical-GRP', 'filename' => ''];

        return [
            [array_replace($valid, ['id' => 0])],
            [array_replace($valid, ['id' => 4294967296])],
            [array_replace($valid, ['title' => ''])],
            [array_replace($valid, ['title' => "Invalid\xff"])],
            [array_replace($valid, ['title' => "Invalid\nTitle"])],
            [array_replace($valid, ['title' => 'folder/Title'])],
            [array_replace($valid, ['title' => str_repeat('a', 256)])],
            [array_replace($valid, ['filename' => str_repeat('a', 256)])],
            [array_replace($valid, ['filename' => str_repeat('.', 128).'mkv'])],
            [array_replace($valid, ['filename' => 'Malformed__NZBSPLIT__payload'])],
            [array_replace($valid, ['filename' => null])],
            [array_diff_key($valid, ['filename' => true])],
        ];
    }

    #[DataProvider('unsupportedRows')]
    public function test_unsupported_owner_is_unknown_instead_of_an_omitted_alias(array $row): void
    {
        self::assertNull((new PredbIdentityKeys)->forRow((object) $row));
    }
}
