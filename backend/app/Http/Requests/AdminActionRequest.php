<?php

namespace App\Http\Requests;

use App\Models\UserReport;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AdminActionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isAdmin() && $this->user()->status === 'active';
    }

    public function rules(): array
    {
        $action = $this->route()->getActionMethod();
        $statuses = match ($action) {
            'verifyEmployer' => ['verified', 'rejected'],
            'moderateJob' => ['approved', 'rejected', 'hidden'],
            'updateUser' => ['active', 'suspended'],
            'updateReport' => UserReport::STATUSES,
        };
        $requiredReason = $action === 'updateUser' || in_array($this->input('status'), ['rejected', 'hidden', 'resolved', 'dismissed'], true);

        return [
            'status' => ['required', Rule::in($statuses)],
            'expected_version' => ['required', 'integer', 'min:1'],
            'reason' => [$requiredReason ? 'required' : 'nullable', 'string', 'min:5', 'max:2000'],
            'internal_notes' => ['nullable', 'string', 'max:5000'],
        ];
    }
}
