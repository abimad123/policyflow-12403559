<?php

namespace App\Http\Requests;

class RetryPolicyRequestRequest extends SubmitPolicyRequestRequest
{
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'retry_key' => ['required', 'string', 'max:100'],
        ];
    }
}
