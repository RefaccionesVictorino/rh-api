<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateWorkerDeviceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // Formato que entrega expo-notifications; null cuando el
            // trabajador retira el permiso de notificaciones.
            'expo_push_token' => ['present', 'nullable', 'string', 'max:255', 'regex:/^Expo(nent)?PushToken\[.+\]$/'],
            'app_version' => ['nullable', 'string', 'max:30'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'expo_push_token.regex' => 'El token de notificaciones no tiene el formato de Expo.',
        ];
    }
}
