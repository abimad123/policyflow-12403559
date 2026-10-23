<?php

namespace App\Http\Requests;

use App\Services\MockInsurer;
use App\Support\DemoMode;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SubmitPolicyRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'scenario' => [
                'sometimes',
                'string',
                Rule::in([
                    MockInsurer::SUCCESS,
                    MockInsurer::TEMPORARY_FAILURE,
                    MockInsurer::TIMEOUT_AFTER_ISSUE,
                    MockInsurer::DELAYED_CALLBACK,
                    MockInsurer::DUPLICATE_CALLBACK,
                ]),
                Rule::prohibitedIf(! DemoMode::available()),
            ],
        ];
    }
}
