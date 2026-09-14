<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class SubDepartment extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'department_id',
        'parent_id',
        'name',
        'code',
        'description',
        'manager_id',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function manager(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'manager_id');
    }

    /** Sub área de la que cuelga; null cuando cuelga directamente del área. */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(SubDepartment::class, 'parent_id');
    }

    /** Sub áreas que cuelgan de esta, un solo nivel. */
    public function children(): HasMany
    {
        return $this->hasMany(SubDepartment::class, 'parent_id');
    }

    /**
     * Empleados asignados a esta sub área en concreto. Una sub área con hijas
     * puede tener los suyos propios: el jefe de Mostrador está en Mostrador
     * aunque debajo cuelguen sus turnos.
     */
    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class);
    }

    /**
     * Sub áreas que cuelgan directamente del área, sin padre. Son las raíces
     * del árbol que el organigrama pinta bajo cada área.
     */
    public function scopeRoots(Builder $query): Builder
    {
        return $query->whereNull('parent_id');
    }

    /**
     * Ids de esta sub área y de toda su descendencia.
     *
     * Se recorre nivel por nivel en vez de con una recursiva de SQL, que MySQL
     * 5.7 no soporta. El tope de vueltas corta cualquier ciclo que se hubiera
     * colado en la base pese a las validaciones.
     */
    public static function descendantIds(int $rootId, int $maxDepth = 10): array
    {
        $ids = [$rootId];
        $frontera = [$rootId];

        for ($depth = 0; $depth < $maxDepth && $frontera !== []; $depth++) {
            $frontera = static::query()
                ->whereIn('parent_id', $frontera)
                ->pluck('id')
                ->all();

            $ids = array_merge($ids, $frontera);
        }

        return array_values(array_unique($ids));
    }

    /**
     * Ids que no pueden ser padre de esta sub área: ella misma y su
     * descendencia. Mover una rama dentro de sí misma la desprendería del árbol
     * y dejaría un ciclo.
     */
    public function forbiddenParentIds(): array
    {
        return static::descendantIds($this->id);
    }

    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        if ($term === null || $term === '') {
            return $query;
        }

        $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $term).'%';

        return $query->where(function (Builder $query) use ($like): void {
            $query
                ->where('name', 'like', $like)
                ->orWhere('code', 'like', $like)
                ->orWhere('description', 'like', $like)
                // Buscar "Nóminas" y que aparezcan también las sub áreas del
                // área llamada así.
                ->orWhereHas('department', fn (Builder $q) => $q->where('name', 'like', $like));
        });
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Filtra por área; ignora el filtro cuando no se envía.
     */
    public function scopeOfDepartment(Builder $query, ?int $departmentId): Builder
    {
        return $departmentId === null
            ? $query
            : $query->where('department_id', $departmentId);
    }
}
