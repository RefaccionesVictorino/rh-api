<?php

namespace App\Http\Requests;

use App\Models\WorkerAccount;
use App\Rules\SecureNip;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Validator;

class ChangeWorkerNipRequest extends FormRequest
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
        /** @var WorkerAccount $account */
        $account = $this->user();

        return [
            'current_nip' => ['required', 'string'],
            'new_nip' => ['required', 'string', 'different:current_nip', new SecureNip($account->employee->birthdate)],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->has('current_nip')) {
                    return;
                }

                if (! Hash::check($this->input('current_nip'), $this->user()->nip)) {
                    $validator->errors()->add('current_nip', 'El NIP actual no es correcto.');
                }
            },
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'current_nip' => 'NIP actual',
            'new_nip' => 'NIP nuevo',
        ];
    }
}
