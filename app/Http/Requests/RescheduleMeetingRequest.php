<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RescheduleMeetingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'proposed_date' => ['required', 'date', 'after:today'],
            'proposed_start_time' => ['required', 'string'],
            'proposed_duration_minutes' => ['required', 'integer', 'min:15', 'max:480'],
        ];
    }
}
