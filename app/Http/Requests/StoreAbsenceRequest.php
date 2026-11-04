<?php

namespace App\Http\Requests;

use App\Models\Absence;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Alta y corrección de una ausencia. Llega como multipart porque puede traer
 * el certificado escaneado; en la edición, `remove_document` lo quita.
 */
class StoreAbsenceRequest extends FormRequest
{
    /** Una incapacidad por riesgo de trabajo puede durar hasta 52 semanas. */
    public const MAX_DAYS = 366;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * El tope del documento es el mismo que el de la foto: API Gateway corta
     * el cuerpo de la petición alrededor de 4.5 MB.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'type' => ['required', Rule::in(array_keys(Absence::TYPES))],
            'starts_on' => ['required', 'date_format:Y-m-d'],
            'ends_on' => ['required', 'date_format:Y-m-d', 'after_or_equal:starts_on'],
            'folio' => ['nullable', 'string', 'max:40'],
            'issued_on' => ['nullable', 'date_format:Y-m-d'],
            'notes' => ['nullable', 'string', 'max:255'],
            'document' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png,webp', 'max:4096'],
            'remove_document' => ['nullable', 'boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            [$from, $to] = $this->range();

            if ($from->diffInDays($to) + 1 > self::MAX_DAYS) {
                $validator->errors()->add('ends_on', 'El periodo no puede exceder '.self::MAX_DAYS.' días.');

                return;
            }

            $overlapping = Absence::query()
                ->where('employee_id', $this->employeeId())
                ->when($this->route('absence'), fn ($q, Absence $current) => $q->whereKeyNot($current->id))
                ->overlapping($from, $to)
                ->orderBy('starts_on')
                ->first();

            if ($overlapping !== null) {
                $validator->errors()->add('starts_on', sprintf(
                    'Ya hay una %s del %s al %s que se traslapa.',
                    mb_strtolower($overlapping->type_label),
                    $overlapping->starts_on->toDateString(),
                    $overlapping->ends_on->toDateString(),
                ));
            }
        });
    }

    /** En el alta el empleado viene en la ruta; en la edición, del registro. */
    private function employeeId(): int
    {
        /** @var Absence|null $absence */
        $absence = $this->route('absence');

        return $absence?->employee_id ?? $this->route('employee')->id;
    }

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public function range(): array
    {
        return [
            CarbonImmutable::parse($this->validated('starts_on'))->startOfDay(),
            CarbonImmutable::parse($this->validated('ends_on'))->startOfDay(),
        ];
    }

    /**
     * Campos que se guardan en la columna; el documento se resuelve aparte.
     *
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        $data = $this->safe()->only(['type', 'starts_on', 'ends_on', 'folio', 'issued_on', 'notes']);

        return $data + ['folio' => null, 'issued_on' => null, 'notes' => null];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'type' => 'tipo',
            'starts_on' => 'fecha de inicio',
            'ends_on' => 'fecha de fin',
            'folio' => 'folio',
            'issued_on' => 'fecha de expedición',
            'notes' => 'notas',
            'document' => 'documento',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'document.mimes' => 'El documento debe ser PDF, JPG, PNG o WebP.',
            'document.max' => 'El documento no debe pesar más de 4 MB.',
        ];
    }
}
