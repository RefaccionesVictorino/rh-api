<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class EmployeeEmergencyContact extends Model
{
    use SoftDeletes;

    public const MAX_PER_EMPLOYEE = 5;

    public const RELATIONSHIPS = [
        'Padre', 'Madre', 'Cónyuge', 'Pareja', 'Hijo(a)', 'Hermano(a)',
        'Abuelo(a)', 'Tío(a)', 'Primo(a)', 'Amigo(a)', 'Otro',
    ];

    protected $fillable = [
        'name',
        'relationship',
        'phone_country_code',
        'phone',
        'has_whatsapp',
        'email',
    ];

    protected function casts(): array
    {
        return [
            'has_whatsapp' => 'boolean',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
