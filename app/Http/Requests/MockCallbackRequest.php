<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class MockCallbackRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'event_id' => ['required', 'string', 'max:100'],
            'provider_reference' => ['required', 'string', 'max:100'],
            'event_type' => ['required', Rule::in(['ISSUED'])],
            'policy_number' => ['required', 'string', 'max:100'],
        ];
    }
}
