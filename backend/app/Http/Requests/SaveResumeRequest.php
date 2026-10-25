<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveResumeRequest extends FormRequest
{
    public const SECTIONS = ['education' => 'Education', 'work' => 'Work experience', 'volunteer' => 'Volunteer experience', 'skills' => 'Skills', 'certifications' => 'Certifications and training', 'projects' => 'Projects', 'languages' => 'Languages', 'references' => 'References'];

    public function authorize(): bool
    {
        return $this->user()?->isApplicant() ?? false;
    }

    public function rules(): array
    {
        $rules = [
            'title' => ['required', 'string', 'max:120'],
            'template' => ['required', Rule::in(['classic', 'modern'])],
            'version' => [$this->isMethod('put') ? 'required' : 'sometimes', 'integer', 'min:1'],
            'content' => ['required', 'array:personal,summary,'.implode(',', array_keys(self::SECTIONS))],
            'content.personal' => ['required', 'array:name,email,phone,location,website'],
            'content.personal.name' => ['required', 'string', 'max:160'],
            'content.personal.email' => ['nullable', 'email', 'max:254'],
            'content.personal.phone' => ['nullable', 'string', 'max:60'],
            'content.personal.location' => ['nullable', 'string', 'max:255'],
            'content.personal.website' => ['nullable', 'url:http,https', 'max:300'],
            'content.summary' => ['nullable', 'string', 'max:4000'],
        ];
        foreach (self::SECTIONS as $section => $label) {
            $rules['content.'.$section] = ['sometimes', 'array', 'list', 'max:20'];
            $rules['content.'.$section.'.*'] = ['array:heading,organization,dates,details'];
            foreach (['heading' => 200, 'organization' => 200, 'dates' => 100, 'details' => 4000] as $field => $limit) {
                $rules['content.'.$section.'.*.'.$field] = ['nullable', 'string', 'max:'.$limit];
            }
        }

        return $rules;
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            if (strlen(json_encode($this->input('content'))) > 120000) {
                $validator->errors()->add('content', 'Resume content must be less than 120 KB.');
            }
        });
    }
}
