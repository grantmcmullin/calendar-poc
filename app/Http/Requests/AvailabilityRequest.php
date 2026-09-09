<?php

namespace App\Http\Requests;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;

class AvailabilityRequest extends FormRequest
{
    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
        ];
    }

    /** @return array<int, callable> */
    public function after(): array
    {
        return [function ($validator) {
            if ($this->filled(['from', 'to'])
                && CarbonImmutable::parse($this->input('from'))->diffInDays(CarbonImmutable::parse($this->input('to'))) > 6) {
                $validator->errors()->add('to', 'The range may not exceed 7 days.');
            }
        }];
    }
}
