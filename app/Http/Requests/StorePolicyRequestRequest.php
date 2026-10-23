<?php

namespace App\Http\Requests;

use App\Support\DemoMode;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePolicyRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'client_request_id' => ['required', 'string', 'max:100', ...(DemoMode::public() ? ['regex:/^DEMO-[0-9a-fA-F-]{36}$/D'] : [])],
            'agent_id' => ['required', 'string', 'max:100', ...(DemoMode::public() ? [Rule::in(['DEMO-AGENT'])] : [])],
            'customer_name' => ['required', 'string', 'max:200', ...(DemoMode::public() ? [Rule::in(['Demo Traveler'])] : [])],
            'date_of_birth' => ['required', 'date_format:Y-m-d', 'before:trip_start'],
            'destination' => ['required', Rule::in(['DOMESTIC', 'INTERNATIONAL'])],
            'trip_start' => ['required', 'date_format:Y-m-d'],
            'trip_end' => ['required', 'date_format:Y-m-d', 'after_or_equal:trip_start'],
            'plan_code' => ['required', Rule::in(['BASIC', 'PLUS', 'PREMIUM'])],
            'sum_insured' => ['required', 'integer', Rule::in([100000, 300000, 500000])],
        ];
    }
}
