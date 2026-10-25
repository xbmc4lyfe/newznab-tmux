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

class AdminCategoryRegexesController extends BasePageController
{
    /**
     * @throws \Exception
     */
    public function index(Request $request): mixed
    {
        $this->setAdminPrefs();
        $regexes = new RegexService('category_regexes');

        $meta_title = $title = 'Category Regex List';

        $group = $this->scalarInput($request, 'group');
        $regex = $regexes->getRegex($group);

        $this->viewData = array_merge($this->viewData, [
            'group' => $group,
            'regex' => $regex,
            'title' => $title,
            'meta_title' => $meta_title,
        ]);

        return view('admin.regexes.category-list', $this->viewData);
    }

    /**
     * @return RedirectResponse|View
     *
     * @throws \Exception
     */
    public function edit(Request $request)
    {
        $this->setAdminPrefs();
        $regexes = new RegexService('category_regexes');

        // Set the current action.
        $action = $this->formAction($request);

        $regex = [
            'id' => '',
            'group_regex' => '',
            'regex' => '',
            'description' => '',
            'ordinal' => '',
            'categories_id' => '',
            'status' => 1,
        ];

        $meta_title = $title = 'Category Regex';

        $state = self::validateForm($action, $request->all());
        $error = $state['error'];

        switch ($action) {
            case 'submit':
                if ($error !== '') {
                    break;
                }

                if (empty($request->input('id'))) {
                    $regexes->addRegex($request->all());
                } else {
                    $regexes->updateRegex($request->all());
                }

                return redirect()->to('admin/category_regexes-list');

            case 'view':
            default:
                if ($request->has('id')) {
                    $meta_title = $title = 'Category Regex Edit';
                    $id = $state['id'];
                    if ($id === false) {
                        abort(404);
                    }

                    $regex = $regexes->getRegexByID($id);
                } else {
                    $meta_title = $title = 'Category Regex Add';
                }
                break;
        }

        $categories_db = Category::query()
            ->select(['c.id', 'c.title', 'cp.title as parent_title'])
            ->from('categories as c')
            ->join('root_categories as cp', 'c.root_categories_id', '=', 'cp.id')
            ->whereNotNull('c.root_categories_id')
            ->orderBy('c.id')
            ->get();

        $labels = self::categoryLabels($categories_db->map(static fn (Category $category): array => ['id' => $category->id, 'parent_title' => $category->getAttribute('parent_title'), 'title' => $category->title])->all());
        $category_ids = $labels['category_ids'];
        $category_names = $labels['category_names'];

        $this->viewData = array_merge($this->viewData, [
            'error' => $error,
            'regex' => (object) $regex,
            'status_ids' => [Category::STATUS_ACTIVE, Category::STATUS_INACTIVE],
            'status_names' => ['Yes', 'No'],
            'category_ids' => $category_ids,
            'category_names' => $category_names,
            'title' => $title,
            'meta_title' => $meta_title,
        ]);

        return view('admin.regexes.category-edit', $this->viewData);
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
        return AdminBlacklistController::wave28Native(2, ['action' => $action, 'input' => $input]) ?? self::validateFormPhp($action, $input);
    }

    /** @param list<array<string, mixed>> $categories
     * @return array{category_ids: list<mixed>, category_names: list<string>}
     */
    public static function categoryLabels(array $categories): array
    {
        return self::usesRustFfi() ? self::categoryLabelsNative($categories) : self::categoryLabelsPhp($categories);
    }

    /** @param list<array<string, mixed>> $categories
     * @return array{category_ids: list<mixed>, category_names: list<string>}
     */
    public static function categoryLabelsPhp(array $categories): array
    {
        $ids = [];
        $names = [];
        foreach ($categories as $category) {
            $ids[] = $category['id'];
            $names[] = $category['parent_title'].' '.$category['title'].': '.$category['id'];
        }

        return ['category_ids' => $ids, 'category_names' => $names];
    }

    /** @param list<array<string, mixed>> $categories
     * @return array{category_ids: list<mixed>, category_names: list<string>}
     */
    public static function categoryLabelsNative(array $categories): array
    {
        return AdminBlacklistController::wave28Native(3, ['categories' => $categories]) ?? self::categoryLabelsPhp($categories);
    }

    public const string FFI_CDEF = <<<'C'
        ssize_t nntmux_w383_regex_list_filter(
            const char *group_ptr,
            size_t group_len,
            char *out_buf,
            size_t out_cap
        );
        C;

    /**
     * Production default for the Wave 38.3 helpers below; it stays false because the paired
     * benchmark in tests/Unit/RustPredbMatchExceptionIdentityTransactionsParityTest.php is below
     * 1.5x. The existing usesRustFfi() toggle keeps controlling the form helpers.
     */
    public const bool RUST_FFI_DEFAULT = false;

    private static ?bool $regexListRustFfiOverride = null;

    private static FFI|false|null $regexListRustFfiHandle = null;

    public static function useRegexListRustFfi(?bool $enabled): void
    {
        self::$regexListRustFfiOverride = $enabled;
    }

    public static function usesRegexListRustFfi(): bool
    {
        return self::$regexListRustFfiOverride ?? self::RUST_FFI_DEFAULT;
    }

    public static function resetRegexListRustFfi(): void
    {
        self::$regexListRustFfiOverride = null;
        self::$regexListRustFfiHandle = null;
    }

    /**
     * Describes the RegexService::getRegex() group filter: a non-empty group becomes the
     * `%group%` LIKE pattern used today; `escaped_pattern` is the same pattern with `\`, `%` and
     * `_` backslash-escaped for a literal match, and `has_wildcards` flags `%` or `_` in the group.
     *
     * @return array{filtered: bool, has_wildcards: bool, length: int, pattern: string, escaped_pattern: string}
     */
    public static function planRegexListFilter(string $group): array
    {
        return (self::usesRegexListRustFfi() ? self::planRegexListFilterNative($group) : null) ?? self::planRegexListFilterPhp($group);
    }

    /**
     * @return array{filtered: bool, has_wildcards: bool, length: int, pattern: string, escaped_pattern: string}|null
     */
    private static function planRegexListFilterNative(string $group): ?array
    {
        $fields = self::callRegexListNative('nntmux_w383_regex_list_filter', [$group, strlen($group)], strlen($group) * 3 + 40, 5);
        if ($fields === null || count($fields) !== 5 || ! ctype_digit($fields[2]) || ! ctype_digit($fields[3])) {
            return null;
        }
        $patternLength = (int) $fields[3];
        $rest = $fields[4];
        if (strlen($rest) <= $patternLength || $rest[$patternLength] !== "\x1F") {
            return null;
        }

        return [
            'filtered' => $fields[0] === '1',
            'has_wildcards' => $fields[1] === '1',
            'length' => (int) $fields[2],
            'pattern' => substr($rest, 0, $patternLength),
            'escaped_pattern' => substr($rest, $patternLength + 1),
        ];
    }

    /**
     * @return array{filtered: bool, has_wildcards: bool, length: int, pattern: string, escaped_pattern: string}
     */
    private static function planRegexListFilterPhp(string $group): array
    {
        $filtered = $group !== '';

        return [
            'filtered' => $filtered,
            'has_wildcards' => strpbrk($group, '%_') !== false,
            'length' => strlen($group),
            'pattern' => $filtered ? '%'.$group.'%' : '',
            'escaped_pattern' => $filtered ? '%'.strtr($group, ['\\' => '\\\\', '%' => '\\%', '_' => '\\_']).'%' : '',
        ];
    }

    /**
     * @param  list<int|string>  $args  arguments preceding the caller-owned output buffer
     * @return list<string>|null
     */
    private static function callRegexListNative(string $symbol, array $args, int $minBytes, int $limit): ?array
    {
        $ffi = self::regexListRustFfi();
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

    private static function regexListRustFfi(): ?FFI
    {
        if (self::$regexListRustFfiHandle === null) {
            self::$regexListRustFfiHandle = RustPipelineLibrary::resolveDomain('categorize_w383', self::FFI_CDEF) ?? false;
        }

        return self::$regexListRustFfiHandle === false ? null : self::$regexListRustFfiHandle;
    }
}
