<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class AdminReleaseReportListRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'status' => ['nullable', 'string', 'in:pending,reviewed,resolved,dismissed,all'],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }

    public function status(): string
    {
        $status = $this->validated('status', 'pending');

        return is_string($status) && $status !== '' ? $status : 'pending';
    }
}
