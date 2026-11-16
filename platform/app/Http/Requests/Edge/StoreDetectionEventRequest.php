<?php

namespace App\Http\Requests\Edge;

use Illuminate\Foundation\Http\FormRequest;

class StoreDetectionEventRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Mirrors the Frigate event payload (frigate/events "end" message) sent by seguria-bridge.
     */
    public function rules(): array
    {
        return [
            'id' => ['required', 'string', 'max:100'],
            'camera' => ['required', 'string', 'max:100'],
            'label' => ['required', 'string', 'max:50'],
            'score' => ['nullable', 'numeric', 'between:0,1'],
            'zones' => ['nullable', 'array'],
            'zones.*' => ['string', 'max:100'],
            'start_time' => ['required', 'numeric'],
            'end_time' => ['nullable', 'numeric'],
            'snapshot' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
            'clip' => ['nullable', 'file', 'mimetypes:video/mp4', 'max:51200'],
            // Bridge 0.4+: evenly spaced frames of the clip, in time order.
            'frames' => ['nullable', 'array', 'max:6'],
            'frames.*' => ['file', 'mimes:jpg,jpeg', 'max:2048'],
        ];
    }
}
