<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Enums\BlacklistConstants;
use App\Http\Controllers\BasePageController;
use App\Models\Category;
use App\Services\BlacklistService;
use App\Support\RustPipelineLibrary;
use FFI;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Throwable;

class AdminBlacklistController extends BasePageController
{
    /**
     * @throws \Exception
     */
    public function index(): mixed
    {
        $this->setAdminPrefs();
        $svc = new BlacklistService;

        $meta_title = $title = 'Binary Black/White List';

        $binlist = $svc->getBlacklist(false);

        $this->viewData = array_merge($this->viewData, [
            'binlist' => $binlist,
            'title' => $title,
            'meta_title' => $meta_title,
        ]);

        return view('admin.blacklist.index', $this->viewData);
    }

    /**
     * @return RedirectResponse|View|void
     *
     * @throws \Exception
     */
    public function edit(Request $request)
    {
        $this->setAdminPrefs();
        $svc = new BlacklistService;
        $state = self::resolveEditState($this->formAction($request, ['view', 'addtest']), $request->all());
        $error = $state['error'];
        $regex = $state['regex'];
        $title = $state['title'];
        $meta_title = $state['meta_title'];

        if ($state['save'] !== null) {
            if ($state['save'] === 'add') {
                $svc->addBlacklist($request->all());
            } else {
                $svc->updateBlacklist($request->all());
            }

            return redirect()->to('admin/binaryblacklist-list');
        }
        if ($state['load']) {
            $regex = $svc->getBlacklistByID((int) $request->input('id'));
        }

        $this->viewData = array_merge($this->viewData, [
            'error' => $error,
            'regex' => (object) $regex,
            'title' => $title,
            'meta_title' => $meta_title,
        ], self::optionMetadata());

        return view('admin.blacklist.edit', $this->viewData);
    }

    public const string FFI_CDEF = 'int32_t nntmux_w28_admin_regex_group_controllers(uint32_t operation, const char *input, size_t length, char *output, size_t capacity);';

    // Keep PHP until each paired common/p99 screen clears 1.5x (see parity test).
    private static ?bool $useRustFfi = null;

    private static FFI|false|null $wave28Ffi = null;

    public static function setUseRustFfi(?bool $useRustFfi): void
    {
        self::$useRustFfi = $useRustFfi;
    }

    public static function usesRustFfi(): bool
    {
        return self::$useRustFfi ?? false;
    }

    /**
     * Shared, dependency-free byte-framed bridge for this controller slice.
     * Unsupported PHP values or any native/decoding error decline to the caller.
     *
     * @param  array<array-key, mixed>  $input
     * @return array<string, mixed>|null
     */
    public static function wave28Native(int $operation, array $input): ?array
    {
        try {
            $wire = self::encodeWave28Node($input);
            self::$wave28Ffi ??= RustPipelineLibrary::resolveDomain('wave28-admin-regex-group-controllers', self::FFI_CDEF, ($path = getenv('NNTMUX_PIPELINE_FFI_LIB')) === false || $path === '' ? null : $path) ?? false;
            if (self::$wave28Ffi === false) {
                return null;
            }
            $ffi = self::$wave28Ffi;
            $capacity = 0;
            $buffer = RustPipelineLibrary::obtainScratch($ffi, 4096, $capacity);
            if ($buffer === null) {
                return null;
            }
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_w28_admin_regex_group_controllers($operation, $wire, strlen($wire), $buffer, $capacity);
            if (is_int($written) && $written < -1) {
                $required = -$written;
                if ($required <= $capacity || $required > 67_108_864) {
                    return null;
                }
                $buffer = RustPipelineLibrary::obtainScratch($ffi, $required, $capacity);
                if ($buffer === null) {
                    return null;
                }
                // @phpstan-ignore method.notFound
                $written = $ffi->nntmux_w28_admin_regex_group_controllers($operation, $wire, strlen($wire), $buffer, $capacity);
            }
            if (! is_int($written) || $written <= 0 || $written > $capacity || $written > 67_108_864) {
                return null;
            }
            $output = FFI::string($buffer, $written);
            $offset = 0;
            $result = self::decodeWave28Node($output, $offset);
            if (! is_array($result) || $offset !== strlen($output)) {
                return null;
            }
            foreach (array_keys($result) as $key) {
                if (! is_string($key)) {
                    return null;
                }
            }

            return $result;
        } catch (Throwable) {
            return null;
        }
    }

    private static function encodeWave28Node(mixed $value, int $depth = 0): string
    {
        if ($depth > 64) {
            throw new \LengthException('Wave 28 nesting limit');
        }
        $node = match (true) {
            $value === null => 'n',
            is_bool($value) => $value ? 'b1' : 'b0',
            is_int($value) => 'i'.$value,
            is_float($value) => 'f'.json_encode($value, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION),
            is_string($value) => 's'.$value,
            is_array($value) => 'a'.count($value),
            default => throw new \UnexpectedValueException('Unsupported Wave 28 value'),
        };
        if (strlen($node) > 67_108_860) {
            throw new \LengthException('Wave 28 payload limit');
        }
        $wire = pack('V', strlen($node)).$node;
        if (is_array($value)) {
            foreach ($value as $key => $item) {
                $wire .= self::encodeWave28Node($key, $depth + 1).self::encodeWave28Node($item, $depth + 1);
                if (strlen($wire) > 67_108_864) {
                    throw new \LengthException('Wave 28 payload limit');
                }
            }
        }

        return $wire;
    }

    private static function decodeWave28Node(string $wire, int &$offset, int $depth = 0): mixed
    {
        if ($depth > 64 || strlen($wire) - $offset < 4) {
            throw new \UnexpectedValueException('Invalid Wave 28 frame');
        }
        $header = unpack('Vlength', $wire, $offset);
        if ($header === false || ! is_int($header['length']) || $header['length'] < 1 || $header['length'] > strlen($wire) - $offset - 4) {
            throw new \UnexpectedValueException('Invalid Wave 28 frame length');
        }
        $node = substr($wire, $offset + 4, $header['length']);
        $offset += 4 + $header['length'];
        $payload = substr($node, 1);
        switch ($node[0]) {
            case 'n':
                if ($node === 'n') {
                    return null;
                }
                break;
            case 'b':
                if ($node === 'b0' || $node === 'b1') {
                    return $node === 'b1';
                }
                break;
            case 's':
                return $payload;
            case 'i':
                $integer = filter_var($payload, FILTER_VALIDATE_INT);
                if ($integer !== false && (string) $integer === $payload) {
                    return $integer;
                }
                break;
            case 'f':
                if (is_numeric($payload) && is_finite((float) $payload)) {
                    return (float) $payload;
                }
                break;
            case 'a':
                $count = filter_var($payload, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
                if ($count === false || (string) $count !== $payload || $count > intdiv(strlen($wire) - $offset, 10)) {
                    break;
                }
                $result = [];
                for ($i = 0; $i < $count; $i++) {
                    $key = self::decodeWave28Node($wire, $offset, $depth + 1);
                    if ((! is_int($key) && ! is_string($key)) || array_key_exists($key, $result)) {
                        throw new \UnexpectedValueException('Invalid Wave 28 key');
                    }
                    $result[$key] = self::decodeWave28Node($wire, $offset, $depth + 1);
                }

                return $result;
        }

        throw new \UnexpectedValueException('Invalid Wave 28 node');
    }

    /** @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public static function resolveEditState(string $action, array $input): array
    {
        return self::usesRustFfi() ? self::resolveEditStateNative($action, $input) : self::resolveEditStatePhp($action, $input);
    }

    /** @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public static function resolveEditStatePhp(string $action, array $input): array
    {
        $state = ['error' => '', 'regex' => ['id' => '', 'groupname' => '', 'regex' => '', 'description' => '', 'msgcol' => 1, 'status' => 1, 'optype' => 1], 'title' => 'Binary Black/White list', 'meta_title' => 'Binary Black/White list', 'save' => null, 'load' => false];
        if ($action === 'submit') {
            if (($input['groupname'] ?? null) === '') {
                $state['error'] = 'Group must be a valid usenet group';
            } elseif (($input['regex'] ?? null) === '') {
                $state['error'] = 'Regex cannot be empty';
            } else {
                $state['save'] = empty($input['id']) ? 'add' : 'update';
            }
        } elseif ($action === 'addtest') {
            if (array_key_exists('groupname', $input) && array_key_exists('regex', $input)) {
                // Keep the original union: initialized values win over test input.
                $state['regex'] += ['groupname' => $input['groupname'], 'regex' => $input['regex'], 'ordinal' => 1, 'status' => 1];
            }
        } else {
            $state['load'] = array_key_exists('id', $input);
            $state['title'] = $state['load'] ? 'Binary Black/Whitelist Edit' : 'Binary Black/Whitelist Add';
        }

        return $state;
    }

    /** @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public static function resolveEditStateNative(string $action, array $input): array
    {
        return self::wave28Native(0, ['action' => $action, 'input' => $input]) ?? self::resolveEditStatePhp($action, $input);
    }

    /** @return array<string, mixed> */
    public static function optionMetadata(): array
    {
        return self::usesRustFfi() ? self::optionMetadataNative() : self::optionMetadataPhp();
    }

    /** @return array<string, mixed> */
    public static function optionMetadataPhp(): array
    {
        return ['status_ids' => [Category::STATUS_ACTIVE, Category::STATUS_INACTIVE], 'status_names' => ['Yes', 'No'], 'optype_ids' => [1, 2], 'optype_names' => ['Black', 'White'], 'msgcol_ids' => [BlacklistConstants::BLACKLIST_FIELD_SUBJECT, BlacklistConstants::BLACKLIST_FIELD_FROM, BlacklistConstants::BLACKLIST_FIELD_MESSAGEID], 'msgcol_names' => ['Subject', 'Poster', 'MessageId']];
    }

    /** @return array<string, mixed> */
    public static function optionMetadataNative(): array
    {
        return self::wave28Native(1, []) ?? self::optionMetadataPhp();
    }
}
