<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class AdminReleaseReportBulkActionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'action' => ['required', 'string', 'in:dismiss,resolve,reviewed,delete,revert'],
            'report_ids' => ['required', 'array', 'min:1'],
            'report_ids.*' => ['integer', 'exists:release_reports,id'],
        ];
    }

    /**
     * @return list<int>
     */
    public function reportIds(): array
    {
        $ids = $this->validated('report_ids', []);

        return array_values(array_unique(array_map('intval', is_array($ids) ? $ids : [])));
    }

    public function actionName(): string
    {
        return (string) $this->validated('action');
    }
}
