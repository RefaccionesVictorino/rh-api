<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Employee extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'name',
        'last_name',
        'second_last_name',
        'gender',
        'rfc',
        'curp',
        'nss',
        'birth_country',
        'marital_status',
        'birthdate',
        'work_phone',
        'personal_phone',
        'personal_email',
        'address',
        'municipality',
        'postal_code',
        'hire_date',
    ];

    protected function casts(): array
    {
        return [
            // birthdate es string en la migración; se deja sin cast a propósito.
            'birthdate' => 'string',
            'hire_date' => 'date',
        ];
    }

    /**
     * Nombre completo; el apellido materno es opcional.
     */
    protected function fullName(): Attribute
    {
        return Attribute::get(fn (): string => trim(implode(' ', array_filter([
            $this->name,
            $this->last_name,
            $this->second_last_name,
        ]))));
    }

    /**
     * Búsqueda por nombre (en cualquiera de sus tres columnas), identificadores
     * fiscales, correo o municipio.
     */
    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        if ($term === null || $term === '') {
            return $query;
        }

        $like = '%' . str_replace(['%', '_'], ['\%', '\_'], $term) . '%';

        return $query->where(function (Builder $query) use ($like): void {
            $query
                ->where('name', 'like', $like)
                ->orWhere('last_name', 'like', $like)
                ->orWhere('second_last_name', 'like', $like)
                ->orWhere('rfc', 'like', $like)
                ->orWhere('curp', 'like', $like)
                ->orWhere('nss', 'like', $like)
                ->orWhere('personal_email', 'like', $like)
                ->orWhere('municipality', 'like', $like)
                // Permite buscar "Juan Pérez" aunque nombre y apellido estén en
                // columnas distintas.
                ->orWhereRaw(
                    "CONCAT_WS(' ', name, last_name, second_last_name) like ?",
                    [$like],
                );
        });
    }
}
