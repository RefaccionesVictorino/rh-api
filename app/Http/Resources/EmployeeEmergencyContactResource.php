<?php

namespace App\Http\Resources;

use App\Models\EmployeeEmergencyContact;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin EmployeeEmergencyContact
 */
class EmployeeEmergencyContactResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'relationship' => $this->relationship,
            'phone_country_code' => $this->phone_country_code,
            'phone' => $this->phone,
            'has_whatsapp' => $this->has_whatsapp,
            'email' => $this->email,
        ];
    }
}
