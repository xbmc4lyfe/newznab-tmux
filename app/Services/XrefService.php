<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\RustPipelineLibrary;

class XrefService
{
    /**
     * Regex patterns to parse Xref tokens (e.g. alt.binaries.* optionally followed by :number).
     */
    private const XREF_PATTERN_WITH_NUM = '/(^[a-zA-Z]{2,3}\\.(bin(aries|arios|aer))\\.[a-zA-Z0-9]?.+)(:\\d+)/';

    private const XREF_PATTERN_NO_NUM = '/(^[a-zA-Z]{2,3}\\.(bin(aries|arios|aer))\\.[a-zA-Z0-9]?.+)/';

    public function __construct(private readonly bool $useNative = true) {}

    /**
     * Extracts valid Xref tokens from a space-separated Xref string.
     *
     * @return array<int|string, mixed>
     */
    public function extractTokens(?string $xref): array
    {
        if (empty($xref)) {
            return [];
        }
        if ($this->useNative) {
            $native = RustPipelineLibrary::xrefExtractTokens($xref);
            if ($native !== null) {
                return $native;
            }
        }

        return $this->extractTokensPhp($xref);
    }

    /**
     * Pure-PHP reference implementation of {@see extractTokens()}.
     *
     * @return list<string>
     */
    public function extractTokensPhp(?string $xref): array
    {
        if (empty($xref)) {
            return [];
        }
        $tokens = [];
        foreach (explode(' ', $xref) as $token) {
            if (preg_match(self::XREF_PATTERN_WITH_NUM, $token, $m) || preg_match(self::XREF_PATTERN_NO_NUM, $token, $m)) {
                $tokens[] = $m[0];
            }
        }

        return $tokens;
    }

    /**
     * Returns tokens that appear in $headerXref but not in $existingXref.
     *
     * @return list<string>
     */
    public function diffNewTokens(?string $existingXref, ?string $headerXref): array
    {
        if (empty($headerXref)) {
            return [];
        }
        if ($this->useNative && \get_class($this) === self::class) {
            $native = RustPipelineLibrary::xrefDiffTokens($existingXref ?? '', $headerXref);
            if ($native !== null) {
                return $native;
            }
        }

        return $this->diffNewTokensPhp($existingXref, $headerXref);
    }

    /**
     * Pure-PHP reference implementation of {@see diffNewTokens()}.
     *
     * @return list<string>
     */
    public function diffNewTokensPhp(?string $existingXref, ?string $headerXref): array
    {
        $existing = $this->extractTokensPhp($existingXref);
        $incoming = $this->extractTokensPhp($headerXref);

        return array_values(array_diff($incoming, $existing));
    }

    /** @return list<string> */
    public function extractGroupNames(?string $xref): array
    {
        if (empty($xref)) {
            return [];
        }
        if ($this->useNative && \get_class($this) === self::class) {
            $native = RustPipelineLibrary::xrefExtractGroups($xref);
            if ($native !== null) {
                return $native;
            }
        }

        return $this->extractGroupNamesPhp($xref);
    }

    /**
     * Pure-PHP reference implementation of {@see extractGroupNames()}.
     *
     * @return list<string>
     */
    public function extractGroupNamesPhp(?string $xref): array
    {
        $groups = [];
        foreach ($this->extractTokensPhp($xref) as $token) {
            $group = preg_replace('/:\d+$/', '', (string) $token);
            if ($group !== null && $group !== '') {
                $groups[$group] = true;
            }
        }

        return array_keys($groups);
    }

    /**
     * Batch extract deduplicated group names for a list of Xref strings.
     *
     * @param  list<string>  $xrefs
     * @return list<list<string>>
     */
    public function extractGroupNamesBatch(array $xrefs): array
    {
        if ($xrefs === []) {
            return [];
        }
        if ($this->useNative && \get_class($this) === self::class) {
            $lines = RustPipelineLibrary::xrefExtractBatchLines($xrefs, 2);
            if ($lines !== null) {
                /** @var array<string, list<string>> $groupsCache */
                $groupsCache = ['' => []];
                $result = [];
                foreach ($lines as $line) {
                    $result[] = $groupsCache[$line] ??= explode(' ', $line);
                }

                return $result;
            }
        }

        $result = [];
        foreach ($xrefs as $xref) {
            $result[] = $this->extractGroupNames($xref);
        }

        return $result;
    }

    /**
     * Batch extract space-joined valid tokens and deduplicated group names for a list of Xref strings.
     *
     * @param  list<string>  $xrefs
     * @return list<array{xref: string, groups: list<string>}>
     */
    public function extractTokensAndGroupNamesBatch(array $xrefs): array
    {
        if ($xrefs === []) {
            return [];
        }
        if ($this->useNative && \get_class($this) === self::class) {
            $lines = RustPipelineLibrary::xrefExtractBatchLines($xrefs, 3);
            if ($lines !== null) {
                /** @var array<string, list<string>> $groupsCache */
                $groupsCache = ['' => []];
                $result = [];
                foreach ($lines as $line) {
                    $tabPos = strpos($line, "\t");
                    if ($tabPos === false) {
                        $result[] = ['xref' => $line, 'groups' => []];

                        continue;
                    }
                    $tokensStr = substr($line, 0, $tabPos);
                    $groupsStr = substr($line, $tabPos + 1);
                    $result[] = [
                        'xref' => $tokensStr,
                        'groups' => $groupsCache[$groupsStr] ??= explode(' ', $groupsStr),
                    ];
                }

                return $result;
            }
        }

        $result = [];
        foreach ($xrefs as $xref) {
            $tokens = $this->extractTokens($xref);
            $result[] = [
                'xref' => implode(' ', $tokens),
                'groups' => $this->extractGroupNames($xref),
            ];
        }

        return $result;
    }
}
