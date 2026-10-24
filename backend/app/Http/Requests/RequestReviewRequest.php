<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RequestReviewRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isEmployer() && $this->user()->status === 'active';
    }

    public function rules(): array
    {
        return ['expected_version' => ['required', 'integer', 'min:1']];
    }
}
