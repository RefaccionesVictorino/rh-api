<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateEmployeePhotoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * El tope de 4 MB no es arbitrario: el microservicio sube el archivo a
     * través de API Gateway, que corta el cuerpo de la petición alrededor de
     * 4.5 MB. Rechazarlo aquí da un mensaje claro en vez de un 502 del gateway.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'photo' => ['required', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:4096'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'photo.required' => 'Elige una imagen.',
            'photo.image' => 'El archivo debe ser una imagen.',
            'photo.mimes' => 'La foto debe estar en formato JPG, PNG o WebP.',
            'photo.max' => 'La foto no debe pesar más de 4 MB.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'photo' => 'foto',
        ];
    }
}
