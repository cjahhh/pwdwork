<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ShareResumeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isApplicant() ?? false;
    }

    public function rules(): array
    {
        return ['resume_id' => ['required', 'integer', 'min:1'], 'consent' => ['required', 'accepted']];
    }
}
