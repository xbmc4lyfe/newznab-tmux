<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class AdminGroupListRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $groupname = $this->input('groupname');

        $this->merge([
            'groupname' => is_string($groupname) ? trim($groupname) : $groupname,
        ]);
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'groupname' => ['nullable', 'string', 'max:255'],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }

    public function groupName(): string
    {
        $value = $this->validated('groupname', '');

        return is_string($value) ? $value : '';
    }
}
