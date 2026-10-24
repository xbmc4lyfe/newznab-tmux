<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\BasePageController;
use App\Models\Category;
use App\Services\RegexService;
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
}
