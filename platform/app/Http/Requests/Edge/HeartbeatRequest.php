<?php

namespace App\Http\Requests\Edge;

use Illuminate\Foundation\Http\FormRequest;

class HeartbeatRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'version' => ['nullable', 'string', 'max:50'],
            'zones_version' => ['nullable', 'string', 'max:40'],
            'cameras' => ['nullable', 'array'],
            'cameras.*.name' => ['required', 'string', 'max:100'],
            'cameras.*.online' => ['required', 'boolean'],
        ];
    }
}
