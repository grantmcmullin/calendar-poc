<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreBookingRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'start_time' => ['required', 'date'],
            'invitee.first_name' => ['required', 'string', 'max:100'],
            'invitee.last_name' => ['required', 'string', 'max:100'],
            'invitee.email' => ['required', 'email'],
            'invitee.phone' => ['required', 'string', 'max:30'],
            'invitee.timezone' => ['required', 'timezone'],
            'tracking' => ['sometimes', 'array'],
        ];
    }
}
