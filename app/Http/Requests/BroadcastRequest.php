<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class BroadcastRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'subject'  => ['required', 'string', 'max:255'],
            'body'     => ['required', 'string'],
            'audience' => ['required_without:user_ids', 'nullable', 'string', 'in:employees,employers,all'],
            'user_ids' => ['nullable', 'array'],
            'user_ids.*' => ['string'],
        ];
    }
}
