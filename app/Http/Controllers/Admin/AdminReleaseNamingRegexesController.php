<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\BasePageController;
use App\Models\Category;
use App\Services\RegexService;
use FFI;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AdminReleaseNamingRegexesController extends BasePageController
{
    /**
     * @throws \Exception
     */
    public function index(Request $request): mixed
    {
        $this->setAdminPrefs();
        $regexes = new RegexService('release_naming_regexes');

        $meta_title = $title = 'Release Naming Regex List';

        $group = $this->scalarInput($request, 'group');
        $regex = $regexes->getRegex($group);

        $this->viewData = array_merge($this->viewData, [
            'group' => $group,
            'regex' => $regex,
            'title' => $title,
            'meta_title' => $meta_title,
        ]);

        return view('admin.regexes.release-naming-list', $this->viewData);
    }

    /**
     * @return RedirectResponse|View
     *
     * @throws \Exception
     */
    public function edit(Request $request)
    {
        $this->setAdminPrefs();
        $regexes = new RegexService('release_naming_regexes');

        // Set the current action.
        $action = $this->formAction($request);
        $regex = ['id' => '', 'group_regex' => '', 'regex' => '', 'description' => '', 'ordinal' => '', 'status' => 1];
        $meta_title = $title = 'Release Naming Regex';

        $state = self::validateForm($action, $request->all());
        $error = $state['error'];

        switch ($action) {
            case 'submit':
                if (array_key_exists('description', $state['input'])) {
                    $request->merge(['description' => $state['input']['description']]);
                }
                if ($error !== '') {
                    break;
                }

                if (empty($request->input('id'))) {
                    $regexes->addRegex($request->all());
                } else {
                    $regexes->updateRegex($request->all());
                }

                return redirect()->to('admin/release_naming_regexes-list');

            case 'view':
            default:
                if ($request->has('id')) {
                    $meta_title = $title = 'Release Naming Regex Edit';
                    $id = $state['id'];
                    if ($id === false) {
                        abort(404);
                    }

                    $regex = $regexes->getRegexByID($id);
                } else {
                    $meta_title = $title = 'Release Naming Regex Add';
                    $regex = ['status' => 1];
                }
                break;
        }

        $this->viewData = array_merge($this->viewData, [
            'error' => $error,
            'regex' => (object) $regex,
            'status_ids' => [Category::STATUS_ACTIVE, Category::STATUS_INACTIVE],
            'status_names' => ['Yes', 'No'],
            'title' => $title,
            'meta_title' => $meta_title,
        ]);

        return view('admin.regexes.release-naming-edit', $this->viewData);
    }

    /**
     * @throws \Exception
     */
    public function testRegex(Request $request): mixed
    {
        $this->setAdminPrefs();
        $meta_title = $title = 'Release Naming Regex Test';

        $parameters = self::normalizeTestParameters($request->all());
        $group = $parameters['group'];
        $regex = $parameters['regex'];
        $showLimit = $parameters['showlimit'];
        $queryLimit = $parameters['querylimit'];

        $data = null;
        if ($group && $regex) {
            $data = (new RegexService('release_naming_regexes'))->testReleaseNamingRegex($group, $regex, $showLimit, $queryLimit);
        }

        $this->viewData = array_merge($this->viewData, [
            'group' => $group,
            'regex' => $regex,
            'showlimit' => $showLimit,
            'querylimit' => $queryLimit,
            'data' => $data,
            'title' => $title,
            'meta_title' => $meta_title,
        ]);

        return view('admin.regexes.release-naming-test', $this->viewData);
    }

    // PHP stays the default until every paired common/p99 benchmark clears 1.5x.
    private static ?bool $useRustFfi = null;

    public static function setUseRustFfi(?bool $useRustFfi): void
    {
        self::$useRustFfi = $useRustFfi;
    }

    public static function usesRustFfi(): bool
    {
        return self::$useRustFfi ?? false;
    }

    /** @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public static function validateForm(string $action, array $input): array
    {
        return self::usesRustFfi() ? self::validateFormNative($action, $input) : self::validateFormPhp($action, $input);
    }

    /** @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public static function validateFormPhp(string $action, array $input): array
    {
        $error = '';
        $id = null;
        if ($action === 'submit') {
            if (empty($input['group_regex'])) {
                $error = 'Group regex must not be empty!';
            } elseif (empty($input['regex'])) {
                $error = 'Regex cannot be empty';
            } else {
                if (empty($input['description'])) {
                    $input['description'] = '';
                }
                if (! is_numeric($input['ordinal'] ?? null) || $input['ordinal'] < 0) {
                    $error = 'Ordinal must be a number, 0 or higher.';
                }
            }
        } elseif (array_key_exists('id', $input)) {
            $id = filter_var($input['id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        }

        return ['error' => $error, 'input' => $input, 'id' => $id];
    }

    /** @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public static function validateFormNative(string $action, array $input): array
    {
        return AdminBlacklistController::wave28Native(7, ['action' => $action, 'input' => $input]) ?? self::validateFormPhp($action, $input);
    }

    /** @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public static function normalizeTestParameters(array $input): array
    {
        return self::usesRustFfi() ? self::normalizeTestParametersNative($input) : self::normalizeTestParametersPhp($input);
    }

    /** @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public static function normalizeTestParametersPhp(array $input): array
    {
        return [
            'group' => trim(! empty($input['group']) ? $input['group'] : ''),
            'regex' => trim(! empty($input['regex']) ? $input['regex'] : ''),
            'showlimit' => is_numeric($input['showlimit'] ?? null) ? $input['showlimit'] : 250,
            'querylimit' => is_numeric($input['querylimit'] ?? null) ? $input['querylimit'] : 100000,
        ];
    }

    /** @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public static function normalizeTestParametersNative(array $input): array
    {
        return AdminBlacklistController::wave28Native(8, ['input' => $input]) ?? self::normalizeTestParametersPhp($input);
    }

    /**
     * PHP remains the default: the paired benchmark in
     * tests/Unit/RustAdminRegexCategoryGroupReleaseW39ParityTest.php is below 1.5x.
     */
    public const bool W391_RUST_FFI_DEFAULT = false;

    private const array W391_AUDIT_KEYS = ['rows', 'audited', 'truncated', 'invalid_rows', 'active', 'valid_delimiters', 'invalid_delimiters', 'named_reqid', 'named_parts', 'named_name', 'ordinal_duplicates', 'ordinal_gaps', 'wildcard_groups'];

    /** Group regexes that match every group, after `trim()`. */
    private const array W391_WILDCARD_GROUPS = ['', '*', '.*', '.+', '^.*$', '^.*', '.*$'];

    /** Closing delimiters for PCRE's bracket-style opening delimiters. */
    private const array W391_BRACKETS = ['(' => ')', '[' => ']', '{' => '}', '<' => '>'];

    private static ?bool $w391UseRustFfi = null;

    private static FFI|false|null $w391Ffi = null;

    /**
     * Audits up to `$maxRows` release-naming regex rows (`status`, `regex`, `group_regex`, `ordinal`): active rows
     * (status 1), regexes with a valid PCRE delimiter pair and modifiers, `reqid`/`parts`/`name` named groups,
     * repeated ordinals, gaps between consecutive distinct non-negative ordinals, and group regexes that are
     * missing or match every group. `invalid_delimiters` counts audited array rows without a valid pattern.
     *
     * @param  array<array-key, mixed>  $rows
     * @return array<string, int|bool>
     */
    public static function summarizeNamingRegexAudit(array $rows, int $maxRows = 500): array
    {
        return (self::usesW391RustFfi() ? self::summarizeNamingRegexAuditNative($rows, $maxRows) : null) ?? self::summarizeNamingRegexAuditPhp($rows, $maxRows);
    }

    /**
     * @param  array<array-key, mixed>  $rows
     * @return array<string, int|bool>
     */
    private static function summarizeNamingRegexAuditPhp(array $rows, int $maxRows): array
    {
        $total = count($rows);
        $audited = min($total, max(0, $maxRows));
        $summary = array_fill_keys(self::W391_AUDIT_KEYS, 0);
        $summary['rows'] = $total;
        $summary['audited'] = $audited;
        $summary['truncated'] = $total > $audited;
        $seen = [];
        $left = $audited;
        foreach ($rows as $row) {
            if ($left-- <= 0) {
                break;
            }
            if (! is_array($row)) {
                $summary['invalid_rows']++;

                continue;
            }
            if (AdminBlacklistController::w391Int($row['status'] ?? null) === 1) {
                $summary['active']++;
            }
            $regex = $row['regex'] ?? null;
            if (is_string($regex)) {
                if (self::w391ValidDelimitedPattern($regex)) {
                    $summary['valid_delimiters']++;
                }
                foreach (['reqid', 'parts', 'name'] as $name) {
                    if (str_contains($regex, "(?P<{$name}>") || str_contains($regex, "(?<{$name}>") || str_contains($regex, "(?'{$name}'")) {
                        $summary['named_'.$name]++;
                    }
                }
            }
            $group = $row['group_regex'] ?? null;
            if (! is_string($group) || in_array(trim($group), self::W391_WILDCARD_GROUPS, true)) {
                $summary['wildcard_groups']++;
            }
            $ordinal = AdminBlacklistController::w391Int($row['ordinal'] ?? null);
            if ($ordinal !== null) {
                if (isset($seen[$ordinal])) {
                    $summary['ordinal_duplicates']++;
                } else {
                    $seen[$ordinal] = true;
                }
            }
        }
        $summary['invalid_delimiters'] = $audited - $summary['invalid_rows'] - $summary['valid_delimiters'];
        $ordinals = array_keys($seen);
        sort($ordinals);
        $previous = null;
        foreach ($ordinals as $ordinal) {
            if ($previous !== null && $previous >= 0 && $ordinal - $previous > 1) {
                $summary['ordinal_gaps']++;
            }
            $previous = $ordinal;
        }

        return $summary;
    }

    /**
     * @param  array<array-key, mixed>  $rows
     * @return array<string, int|bool>|null
     */
    private static function summarizeNamingRegexAuditNative(array $rows, int $maxRows): ?array
    {
        $left = min(count($rows), max(0, $maxRows));
        $words = [$maxRows, count($rows), $left];
        $blob = [];
        foreach ($rows as $row) {
            if ($left-- <= 0) {
                break;
            }
            if (is_array($row)) {
                AdminBlacklistController::w391PackRow($words, $blob, [$row['status'] ?? null, $row['regex'] ?? null, $row['group_regex'] ?? null, $row['ordinal'] ?? null]);
            } else {
                $words[] = 0;
            }
        }
        $values = AdminBlacklistController::w391Invoke(self::$w391Ffi, 1, $words, $blob);
        if ($values === null || count($values) !== count(self::W391_AUDIT_KEYS) || ($values[2] & ~1) !== 0) {
            return null;
        }
        $summary = array_combine(self::W391_AUDIT_KEYS, $values);
        $summary['truncated'] = $values[2] === 1;

        return $summary;
    }

    /**
     * PHP's `preg_*` delimiter scan: an opening byte that is not alphanumeric, a backslash, NUL or whitespace;
     * its closing delimiter (nesting for `([{<`) with backslash escapes skipped; then only modifiers PHP accepts.
     */
    private static function w391ValidDelimitedPattern(string $pattern): bool
    {
        $length = strlen($pattern);
        if ($length < 2) {
            return false;
        }
        $open = $pattern[0];
        if ($open === '\\' || strspn($open, "\0 \t\n\x0b\x0c\rabcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789") === 1) {
            return false;
        }
        $close = self::W391_BRACKETS[$open] ?? $open;
        // Jump between backslashes and delimiters instead of stepping through every byte.
        $stops = '\\'.$close.($close === $open ? '' : $open);
        $depth = 1;
        $i = 1;
        while ($i < $length) {
            $i += strcspn($pattern, $stops, $i);
            if ($i >= $length) {
                break;
            }
            $char = $pattern[$i];
            if ($char === '\\') {
                $i += 2;
            } elseif ($char !== $close) {
                $depth++;
                $i++;
            } elseif ($close === $open || --$depth === 0) {
                break;
            } else {
                $i++;
            }
        }
        if ($i >= $length) {
            return false;
        }
        $modifiers = substr($pattern, $i + 1);

        return strspn($modifiers, "imsxADSUXJun \n\r") === strlen($modifiers);
    }

    public static function useW391RustFfi(?bool $enabled): void
    {
        self::$w391UseRustFfi = $enabled;
    }

    public static function usesW391RustFfi(): bool
    {
        return self::$w391UseRustFfi ?? self::W391_RUST_FFI_DEFAULT;
    }

    public static function resetW391RustFfi(): void
    {
        self::$w391UseRustFfi = null;
        self::$w391Ffi = null;
    }
}
