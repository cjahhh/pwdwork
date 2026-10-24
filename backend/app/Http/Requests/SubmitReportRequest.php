<?php

namespace App\Http\Requests;

use App\Models\UserReport;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SubmitReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return in_array($this->user()?->role, ['applicant', 'employer'], true) && $this->user()->status === 'active';
    }

    public function rules(): array
    {
        return [
            'target_type' => ['required', Rule::in(['job', 'employer', 'account', 'platform'])],
            'target_id' => ['required_unless:target_type,platform', 'prohibited_if:target_type,platform', 'integer', 'min:1'],
            'category' => ['required', Rule::in(UserReport::CATEGORIES)],
            'description' => ['required', 'string', 'min:10', 'max:5000'],
        ];
    }
}
