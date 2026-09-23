<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ExecuteTimeClockCommandRequest extends FormRequest
{
    /**
     * Acciones expuestas por nombre: nunca se permite mandar una cadena
     * arbitraria hacia el terminal desde el frontend.
     */
    public const ACTIONS = [
        'sync_time', 'sync_users', 'reboot', 'unlock_door', 'query_users',
        'query_punches', 'clear_log', 'message',
    ];

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
            'action' => ['required', 'string', Rule::in(self::ACTIONS)],
            'seconds' => ['nullable', 'integer', 'min:1', 'max:60'],
            'text' => ['required_if:action,message', 'nullable', 'string', 'max:100'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'action' => 'acción',
            'seconds' => 'segundos',
            'text' => 'texto',
            'from' => 'desde',
            'to' => 'hasta',
        ];
    }
}
