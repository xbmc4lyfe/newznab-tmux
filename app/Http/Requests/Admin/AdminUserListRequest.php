<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;

class AdminUserListRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user instanceof User && $user->hasRole('Admin');
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'username' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'string', 'max:255'],
            'host' => ['nullable', 'string', 'max:255'],
            'role' => ['nullable', 'integer', 'exists:roles,id'],
            'verified' => ['nullable', 'in:0,1'],
            'created_from' => ['nullable', 'date_format:Y-m-d'],
            'created_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:created_from'],
            'ob' => ['nullable', 'string', 'in:'.implode(',', getUserBrowseOrdering())],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }

    /**
     * @return array{username: string, email: string, host: string, role: string, verified: string, created_from: string, created_to: string}
     */
    public function filters(): array
    {
        return [
            'username' => $this->scalarInput('username'),
            'email' => $this->scalarInput('email'),
            'host' => $this->scalarInput('host'),
            'role' => $this->scalarInput('role'),
            'verified' => $this->scalarInput('verified'),
            'created_from' => $this->scalarInput('created_from'),
            'created_to' => $this->scalarInput('created_to'),
        ];
    }

    public function orderBy(): string
    {
        $orderBy = $this->scalarInput('ob');

        return \in_array($orderBy, getUserBrowseOrdering(), true) ? $orderBy : '';
    }

    private function scalarInput(string $key): string
    {
        $value = $this->input($key, '');

        return is_scalar($value) ? trim((string) $value) : '';
    }
}
