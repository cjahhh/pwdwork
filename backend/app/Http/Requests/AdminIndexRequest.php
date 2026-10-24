<?php

namespace App\Http\Requests;

use App\Models\Employer;
use App\Models\JobListing;
use App\Models\UserReport;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AdminIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isAdmin() && $this->user()->status === 'active';
    }

    public function rules(): array
    {
        $rules = ['query' => ['nullable', 'string', 'max:255'], 'page' => ['sometimes', 'integer', 'min:1', 'max:1000000'], 'per_page' => ['sometimes', 'integer', 'between:1,50']];

        return [...$rules, ...match ($this->route()->getActionMethod()) {
            'employers' => ['status' => ['sometimes', Rule::in(Employer::VERIFICATION_STATUSES)], 'employer_id' => ['sometimes', 'integer', 'min:1']],
            'jobs' => ['status' => ['sometimes', Rule::in(JobListing::MODERATION_STATUSES)], 'employer_id' => ['sometimes', 'integer', 'min:1'], 'job_id' => ['sometimes', 'integer', 'min:1']],
            'users' => ['role' => ['sometimes', Rule::in(['applicant', 'employer', 'admin'])], 'status' => ['sometimes', Rule::in(['active', 'suspended', 'inactive', 'pending'])], 'user_id' => ['sometimes', 'integer', 'min:1']],
            'reports' => ['status' => ['sometimes', Rule::in(UserReport::STATUSES)], 'category' => ['sometimes', Rule::in(UserReport::CATEGORIES)]],
            'audit' => ['action' => ['sometimes', 'string', 'max:80'], 'target_type' => ['sometimes', Rule::in(['employer', 'job', 'user', 'report'])], 'target_id' => ['sometimes', 'integer', 'min:1'], 'actor_id' => ['sometimes', 'integer', 'min:1']],
            default => [],
        }];
    }
}
