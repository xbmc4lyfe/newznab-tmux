<?php

declare(strict_types=1);

namespace App\Services\Predb;

use App\Services\NameFixing\Data\FilenamePass;
use App\Services\NameFixing\DonorMatchSelector;
use App\Services\NameFixing\FileNameCleaner;
use App\Services\NameFixing\FilePrioritizer;
use App\Services\NameFixing\NameFixingQueryService;
use App\Services\NameFixing\NameFixingService;
use App\Services\NameFixing\NzbSplitUnwrapper;
use App\Services\NameFixing\OriginalFilenameEvidence;
use App\Services\NameFixing\PredbMatchSelector;
use App\Services\NameFixing\ReleaseUpdateService;
use App\Support\PredbSearchDocument;
use App\Traits\DetectsHashedNames;
use App\Traits\OwnsPredbIdentityTransactions;
use ReflectionClass;
use Throwable;

/** Loaded-definition provenance only; this does not certify catalogue completeness. */
final class PredbIdentityPolicy
{
    public const PREDB_IDENTITY_POLICY = '97990b5974a604ca29af3976547d5440318e07475147f4cf8e39c1460b168ada';

    /** @var array<class-string, string> */
    private const DEFINITIONS = [
        self::class => 'PREDB_IDENTITY_POLICY',
        PredbIdentitySchema::class => 'PREDB_IDENTITY_POLICY',
        PredbIdentitySchemaAttestation::class => 'PREDB_IDENTITY_POLICY',
        PredbIdentityKeys::class => 'PREDB_IDENTITY_POLICY',
        PredbIdentityLookup::class => 'PREDB_IDENTITY_POLICY',
        PredbIdentityProjector::class => 'PREDB_IDENTITY_POLICY',
        PredbIdentityWriter::class => 'PREDB_IDENTITY_POLICY',
        PredbIdentityRebuild::class => 'PREDB_IDENTITY_POLICY',
        FilenamePass::class => 'PREDB_IDENTITY_POLICY',
        OriginalFilenameEvidence::class => 'PREDB_IDENTITY_POLICY',
        NzbSplitUnwrapper::class => 'PREDB_IDENTITY_POLICY',
        FileNameCleaner::class => 'PREDB_IDENTITY_POLICY',
        PredbSearchDocument::class => 'PREDB_IDENTITY_POLICY',
        PredbMatchSelector::class => 'PREDB_IDENTITY_POLICY',
        DetectsHashedNames::class => 'PREDB_HASHED_NAMES_POLICY',
        OwnsPredbIdentityTransactions::class => 'PREDB_OWNED_TRANSACTION_POLICY',
        NameFixingService::class => 'PREDB_IDENTITY_POLICY',
        DonorMatchSelector::class => 'PREDB_IDENTITY_POLICY',
        NameFixingQueryService::class => 'PREDB_IDENTITY_POLICY',
        ReleaseUpdateService::class => 'PREDB_IDENTITY_POLICY',
        FilePrioritizer::class => 'PREDB_IDENTITY_POLICY',
    ];

    public function fingerprint(): ?string
    {
        $definitions = [];
        foreach (self::DEFINITIONS as $class => $marker) {
            $fingerprint = $this->definitionFingerprint($class, $marker);
            if ($fingerprint === null) {
                return null;
            }
            $definitions[$class] = $fingerprint;
        }

        return hash('sha256', json_encode([
            'version' => 1,
            'definitions' => $definitions,
            'php' => PHP_VERSION,
            'php_int_size' => PHP_INT_SIZE,
            'mbstring' => phpversion('mbstring'),
            'pcre' => PCRE_VERSION,
            'encoding' => mb_internal_encoding(),
            'pcre_backtrack_limit' => ini_get('pcre.backtrack_limit'),
            'pcre_recursion_limit' => ini_get('pcre.recursion_limit'),
            'pcre_jit' => ini_get('pcre.jit'),
            'ctype_locale' => setlocale(LC_CTYPE, '0'),
        ], JSON_THROW_ON_ERROR));
    }

    public function definitionIsCurrent(string $class, string $marker = 'PREDB_IDENTITY_POLICY'): bool
    {
        return $this->definitionFingerprint($class, $marker) !== null;
    }

    private function definitionFingerprint(string $class, string $marker): ?string
    {
        if (preg_match('/\APREDB_[A-Z_]+_POLICY\z/D', $marker) !== 1) {
            return null;
        }
        try {
            $definition = new ReflectionClass($class);
            $constant = $definition->getReflectionConstant($marker);
            $path = $definition->getFileName();
            if ($constant === false || ! $constant->isPublic()
                || $constant->getDeclaringClass()->getName() !== $definition->getName()
                || ! is_string($path)) {
                return null;
            }
            $loaded = $constant->getValue();
            if (! is_string($loaded) || preg_match('/\A[0-9a-f]{64}\z/D', $loaded) !== 1) {
                return null;
            }
            $source = @file_get_contents($path, length: 1048577);
            if (! is_string($source) || strlen($source) > 1048576) {
                return null;
            }
            $pattern = '/^    public const '.preg_quote($marker, '/')." = '[0-9a-f]{64}';\\r?\\n/m";
            $normalized = preg_replace($pattern, '', $source, count: $count);
            if ($count !== 1 || ! is_string($normalized) || ! hash_equals($loaded, hash('sha256', $normalized))) {
                return null;
            }

            return $loaded;
        } catch (Throwable) {
            return null;
        }
    }
}
