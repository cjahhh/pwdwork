<?php

namespace App\Http\Requests;

use App\Models\AccommodationRequest;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AccommodationWorkflowRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->status === 'active' && in_array($this->user()->role, ['applicant', 'employer'], true);
    }

    public function rules(): array
    {
        $create = $this->route()->getActionMethod() === 'store';
        $edit = $this->input('action') === 'edit';
        $rules = ['action_key' => ['required', 'uuid']];
        if ($create) {
            $rules['application_id'] = ['required', 'integer', 'min:1'];
        } else {
            $rules['expected_version'] = ['required', 'integer', 'min:1'];
            $rules['action'] = ['required', Rule::in(['acknowledge', 'propose', 'agree', 'unable', 'accept', 'decline', 'withdraw', 'edit', 'message'])];
            $rules['text'] = [in_array($this->input('action'), ['propose', 'agree', 'unable', 'message'], true) ? 'required' : 'nullable', 'string', 'min:5', 'max:2000'];
        }
        if ($create || $edit) {
            $rules += [
                'share_request' => ['required', 'accepted'],
                'categories' => ['required', 'array', 'min:1', 'max:12'],
                'categories.*' => ['required', 'string', 'distinct', Rule::in(AccommodationRequest::categories())],
                'stage' => ['required', Rule::in(AccommodationRequest::STAGES)],
                'details' => ['nullable', 'string', 'max:2000'],
            ];
        }

        return $rules;
    }
}
