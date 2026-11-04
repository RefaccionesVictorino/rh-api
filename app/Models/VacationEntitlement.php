<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Tabulador de días de vacaciones por antigüedad, artículo 76 de la Ley
 * Federal del Trabajo.
 *
 * Cada renglón cubre un rango de años de servicio. El último tiene `to_year`
 * nulo para que la antigüedad no se salga de la tabla.
 */
class VacationEntitlement extends Model
{
    protected $fillable = ['from_year', 'to_year', 'days'];

    protected function casts(): array
    {
        return [
            'from_year' => 'integer',
            'to_year' => 'integer',
            'days' => 'integer',
        ];
    }

    public static function daysForYear(int $yearNumber): int
    {
        $row = static::where('from_year', '<=', $yearNumber)
            ->where(fn ($query) => $query->whereNull('to_year')->orWhere('to_year', '>=', $yearNumber))
            ->orderByDesc('from_year')
            ->first();

        return $row?->days ?? 0;
    }

    public function covers(int $yearNumber): bool
    {
        return $yearNumber >= $this->from_year
            && ($this->to_year === null || $yearNumber <= $this->to_year);
    }
}
