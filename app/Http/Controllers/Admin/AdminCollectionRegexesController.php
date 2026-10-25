<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\BasePageController;
use App\Models\Category;
use App\Services\RegexService;
use App\Support\RustPipelineLibrary;
use FFI;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Throwable;

class AdminCollectionRegexesController extends BasePageController
{
    /**
     * @throws \Exception
     */
    public function index(Request $request): mixed
    {
        $this->setAdminPrefs();
        $regexes = new RegexService('collection_regexes');

        $meta_title = $title = 'Collections Regex List';

        $group = $this->scalarInput($request, 'group');
        $regex = $regexes->getRegex($group);

        $this->viewData = array_merge($this->viewData, [
            'group' => $group,
            'regex' => $regex,
            'title' => $title,
            'meta_title' => $meta_title,
        ]);

        return view('admin.regexes.collection-list', $this->viewData);
    }

    /**
     * @return RedirectResponse|View
     *
     * @throws \Exception
     */
    public function edit(Request $request)
    {
        $this->setAdminPrefs();
        $regexes = new RegexService('collection_regexes');
        $regex = self::defaultRegex();
        $meta_title = $title = 'Collections Regex';

        $action = $this->formAction($request);
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

                return redirect()->to('admin/collection_regexes-list');

            case 'view':
            default:
                if ($request->has('id')) {
                    $meta_title = $title = 'Collections Regex Edit';
                    $id = $state['id'];
                    if ($id === false) {
                        abort(404);
                    }

                    $regex = $regexes->getRegexByID($id);
                } else {
                    $meta_title = $title = 'Collections Regex Add';
                    $regex += ['status' => 1];
                }
                break;
        }

        $this->viewData = array_merge($this->viewData, [
            'regex' => (object) $regex,
            'error' => $error,
            'status_ids' => [Category::STATUS_ACTIVE, Category::STATUS_INACTIVE],
            'status_names' => ['Yes', 'No'],
            'title' => $title,
            'meta_title' => $meta_title,
        ]);

        return view('admin.regexes.collection-edit', $this->viewData);
    }

    /**
     * @throws \Exception
     */
    public function testRegex(Request $request): mixed
    {
        $this->setAdminPrefs();
        $meta_title = $title = 'Collections Regex Test';

        $parameters = self::normalizeTestParameters($request->all());
        $group = $parameters['group'];
        $regex = $parameters['regex'];
        $limit = $parameters['limit'];

        $data = null;
        if ($group && $regex) {
            $data = (new RegexService('collection_regexes'))->testCollectionRegex($group, $regex, $limit);
        }

        $this->viewData = array_merge($this->viewData, [
            'group' => $group,
            'regex' => $regex,
            'limit' => $limit,
            'data' => $data,
            'title' => $title,
            'meta_title' => $meta_title,
        ]);

        return view('admin.regexes.collection-test', $this->viewData);
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
        return AdminBlacklistController::wave28Native(4, ['action' => $action, 'input' => $input]) ?? self::validateFormPhp($action, $input);
    }

    /** @return array<string, mixed> */
    public static function defaultRegex(): array
    {
        return self::usesRustFfi() ? self::defaultRegexNative() : self::defaultRegexPhp();
    }

    /** @return array<string, mixed> */
    public static function defaultRegexPhp(): array
    {
        return ['id' => '', 'regex' => '', 'description' => '', 'group_regex' => '', 'ordinal' => '', 'status' => 1];
    }

    /** @return array<string, mixed> */
    public static function defaultRegexNative(): array
    {
        return AdminBlacklistController::wave28Native(5, []) ?? self::defaultRegexPhp();
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
            'limit' => is_numeric($input['limit'] ?? null) ? $input['limit'] : 50,
        ];
    }

    /** @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public static function normalizeTestParametersNative(array $input): array
    {
        return AdminBlacklistController::wave28Native(6, ['input' => $input]) ?? self::normalizeTestParametersPhp($input);
    }

    public const string FFI_CDEF = <<<'C'
        ssize_t nntmux_w383_regex_pattern_summary(
            const char *regex_ptr,
            size_t regex_len,
            char *out_buf,
            size_t out_cap
        );
        C;

    /** Modifier bytes accepted after the closing delimiter (PHP skips spaces and newlines). */
    public const string PATTERN_MODIFIERS = "imsxuADSUXJnr \n\r";

    private const string PATTERN_SPACE = " \t\n\r\x0B\x0C";

    private const array PATTERN_BRACKETS = ['(' => ')', '[' => ']', '{' => '}', '<' => '>'];

    /**
     * Production default for the Wave 38.3 helpers below; it stays false because the paired
     * benchmark in tests/Unit/RustPredbMatchExceptionIdentityTransactionsParityTest.php is below
     * 1.5x. The existing usesRustFfi() toggle keeps controlling the form helpers.
     */
    public const bool RUST_FFI_DEFAULT = false;

    private static ?bool $regexPatternRustFfiOverride = null;

    private static FFI|false|null $regexPatternRustFfiHandle = null;

    public static function useRegexPatternRustFfi(?bool $enabled): void
    {
        self::$regexPatternRustFfiOverride = $enabled;
    }

    public static function usesRegexPatternRustFfi(): bool
    {
        return self::$regexPatternRustFfiOverride ?? self::RUST_FFI_DEFAULT;
    }

    public static function resetRegexPatternRustFfi(): void
    {
        self::$regexPatternRustFfiOverride = null;
        self::$regexPatternRustFfiHandle = null;
    }

    /**
     * Structural summary of a collection regex as PHP's PCRE wrapper splits it: leading
     * whitespace is skipped, the delimiter must not be alphanumeric, a backslash or NUL, bracket
     * delimiters nest, and backslash escapes are skipped. `named_groups` counts unescaped
     * `(?<name>`, `(?'name'` and `(?P<name>` openings in the body; `modifiers_valid` requires a
     * closed pattern whose trailing bytes are all PATTERN_MODIFIERS. This does not compile the regex.
     *
     * @return array{delimited: bool, delimiter: string, closed: bool, body_length: int, modifiers_valid: bool, named_groups: int, modifiers: string}
     */
    public static function summarizeRegexPattern(string $regex): array
    {
        return (self::usesRegexPatternRustFfi() ? self::summarizeRegexPatternNative($regex) : null) ?? self::summarizeRegexPatternPhp($regex);
    }

    /**
     * @return array{delimited: bool, delimiter: string, closed: bool, body_length: int, modifiers_valid: bool, named_groups: int, modifiers: string}|null
     */
    private static function summarizeRegexPatternNative(string $regex): ?array
    {
        $fields = self::callRegexPatternNative('nntmux_w383_regex_pattern_summary', [$regex, strlen($regex)], strlen($regex) + 64, 7);
        if ($fields === null || count($fields) !== 7 || preg_match('/\A(?:-1|[0-9]{1,3})\z/', $fields[1]) !== 1
            || (int) $fields[1] > 255 || ! ctype_digit($fields[3]) || ! ctype_digit($fields[5])) {
            return null;
        }
        $delimiter = (int) $fields[1];

        return [
            'delimited' => $fields[0] === '1',
            'delimiter' => $delimiter < 0 ? '' : chr($delimiter),
            'closed' => $fields[2] === '1',
            'body_length' => (int) $fields[3],
            'modifiers_valid' => $fields[4] === '1',
            'named_groups' => (int) $fields[5],
            'modifiers' => $fields[6],
        ];
    }

    /**
     * @return array{delimited: bool, delimiter: string, closed: bool, body_length: int, modifiers_valid: bool, named_groups: int, modifiers: string}
     */
    private static function summarizeRegexPatternPhp(string $regex): array
    {
        $length = strlen($regex);
        $start = strspn($regex, self::PATTERN_SPACE);
        $delimiter = $start < $length ? $regex[$start] : '';
        if ($delimiter === '' || (ord($delimiter) < 128 && ctype_alnum($delimiter)) || $delimiter === '\\' || $delimiter === "\0") {
            return ['delimited' => false, 'delimiter' => '', 'closed' => false, 'body_length' => 0, 'modifiers_valid' => false, 'named_groups' => 0, 'modifiers' => ''];
        }
        $closing = self::PATTERN_BRACKETS[$delimiter] ?? $delimiter;
        $depth = 1;
        $end = null;
        for ($i = $start + 1; $i < $length; $i++) {
            $byte = $regex[$i];
            if ($byte === '\\' && $i + 1 < $length) {
                $i++;
            } elseif ($byte === $closing && ($closing === $delimiter || --$depth === 0)) {
                $end = $i;
                break;
            } elseif ($byte === $delimiter) {
                $depth++;
            }
        }
        $body = substr($regex, $start + 1, $end === null ? null : $end - $start - 1);
        $modifiers = $end === null ? '' : substr($regex, $end + 1);

        return [
            'delimited' => true,
            'delimiter' => $delimiter,
            'closed' => $end !== null,
            'body_length' => strlen($body),
            'modifiers_valid' => $end !== null && strspn($modifiers, self::PATTERN_MODIFIERS) === strlen($modifiers),
            'named_groups' => self::countNamedGroupsPhp($body),
            'modifiers' => $modifiers,
        ];
    }

    private static function countNamedGroupsPhp(string $body): int
    {
        $count = 0;
        $length = strlen($body);
        for ($i = 0; $i < $length; $i++) {
            if ($body[$i] === '\\') {
                $i++;
            } elseif ($body[$i] === '(' && ($body[$i + 1] ?? '') === '?') {
                $kind = $body[$i + 2] ?? '';
                $next = $body[$i + 3] ?? '';
                if ($kind === "'" || ($kind === '<' && $next !== '' && $next !== '=' && $next !== '!') || ($kind === 'P' && $next === '<')) {
                    $count++;
                }
            }
        }

        return $count;
    }

    /**
     * @param  list<int|string>  $args  arguments preceding the caller-owned output buffer
     * @return list<string>|null
     */
    private static function callRegexPatternNative(string $symbol, array $args, int $minBytes, int $limit): ?array
    {
        $ffi = self::regexPatternRustFfi();
        if ($ffi === null) {
            return null;
        }

        try {
            $capacity = 0;
            $buffer = RustPipelineLibrary::obtainScratch($ffi, $minBytes, $capacity);
            if ($buffer === null) {
                return null;
            }
            $written = $ffi->$symbol(...[...$args, $buffer, $capacity]);
            if (is_int($written) && $written < -1) {
                $buffer = RustPipelineLibrary::obtainScratch($ffi, -$written, $capacity);
                if ($buffer === null) {
                    return null;
                }
                $written = $ffi->$symbol(...[...$args, $buffer, $capacity]);
            }
            if (! is_int($written) || $written < 0 || $written > $capacity) {
                return null;
            }

            return explode("\x1F", FFI::string($buffer, $written), $limit);
        } catch (Throwable) {
            return null;
        }
    }

    private static function regexPatternRustFfi(): ?FFI
    {
        if (self::$regexPatternRustFfiHandle === null) {
            self::$regexPatternRustFfiHandle = RustPipelineLibrary::resolveDomain('categorize_w383', self::FFI_CDEF) ?? false;
        }

        return self::$regexPatternRustFfiHandle === false ? null : self::$regexPatternRustFfiHandle;
    }
}
