<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CreateMeetingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'invitee_id' => ['required', 'string'],
            'title' => ['required', 'string', 'min:1', 'max:255'],
            'meeting_type' => ['required', 'in:in_person,phone_call,video_call'],
            'proposed_date' => ['required', 'date', 'after_or_equal:today'],
            'proposed_start_time' => ['required', 'string'],
            'proposed_duration_minutes' => ['required', 'integer', 'min:15', 'max:480'],
            'location_or_link' => ['nullable', 'string', 'max:500'],
        ];
    }
}
