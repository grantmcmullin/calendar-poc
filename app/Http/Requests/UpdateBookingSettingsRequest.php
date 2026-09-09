<?php

namespace App\Http\Requests;

use Illuminate\Validation\Rule;
use App\Domain\Tenants\Enums\Weekday;
use App\Domain\Tenants\Enums\MeetingLength;
use Illuminate\Foundation\Http\FormRequest;

class UpdateBookingSettingsRequest extends FormRequest
{
    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'available_days' => ['required', 'array', 'min:1'],
            'available_days.*' => [Rule::enum(Weekday::class)],
            'office_starts_at' => ['required', 'date_format:H:i'],
            'office_ends_at' => ['required', 'date_format:H:i', 'after:office_starts_at'],
            'meeting_length_minutes' => ['required', Rule::in(MeetingLength::values())],
        ];
    }
}
