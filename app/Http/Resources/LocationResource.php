<?php

namespace App\Http\Resources;

use App\Models\Location;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Location
 */
class LocationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'code' => $this->code,
            'address' => $this->address,
            'municipality' => $this->municipality,
            'phone' => $this->phone,
            'is_active' => $this->is_active,
            'devices_count' => $this->whenCounted('devices'),
            'punches_count' => $this->whenCounted('punches'),
        ];
    }
}
