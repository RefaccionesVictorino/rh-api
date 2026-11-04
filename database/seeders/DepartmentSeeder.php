<?php

namespace Database\Seeders;

use App\Models\Department;
use App\Models\SubDepartment;
use Illuminate\Database\Seeder;

/**
 * Catálogo base de áreas y sub áreas.
 *
 * A diferencia del seeder de empleados, este sí corre en cualquier ambiente: son
 * datos de catálogo, no de prueba. Es idempotente, así que puede volver a
 * ejecutarse para incorporar áreas nuevas sin duplicar las existentes.
 */
class DepartmentSeeder extends Seeder
{
    /**
     * Área => clave, color y sub áreas.
     *
     * @var array<string, array{code: string, color: string, sub: array<string, string>}>
     */
    private array $catalogo = [
        'Dirección General' => [
            'code' => 'DG',
            'color' => '#6d4fd0',
            'sub' => [
                'Dirección' => 'DG-DIR',
                'Asistencia de Dirección' => 'DG-ASI',
            ],
        ],
        'Recursos Humanos' => [
            'code' => 'RH',
            'color' => '#b5638f',
            'sub' => [
                'Reclutamiento y Selección' => 'RH-REC',
                'Nóminas' => 'RH-NOM',
                'Capacitación' => 'RH-CAP',
                'Seguridad e Higiene' => 'RH-SEG',
            ],
        ],
        'Contabilidad y Finanzas' => [
            'code' => 'CF',
            'color' => '#1f7fad',
            'sub' => [
                'Contabilidad General' => 'CF-CON',
                'Cuentas por Cobrar' => 'CF-CXC',
                'Cuentas por Pagar' => 'CF-CXP',
                'Facturación' => 'CF-FAC',
            ],
        ],
        'Ventas' => [
            'code' => 'VEN',
            'color' => '#1f7a3d',
            'sub' => [
                'Mostrador' => 'VEN-MOS',
                'Ventas Foráneas' => 'VEN-FOR',
                'Mayoreo' => 'VEN-MAY',
                'Atención a Clientes' => 'VEN-ATC',
            ],
        ],
        'Almacén' => [
            'code' => 'ALM',
            'color' => '#b86a06',
            'sub' => [
                'Recepción de Mercancía' => 'ALM-REC',
                'Surtido' => 'ALM-SUR',
                'Inventarios' => 'ALM-INV',
                'Embarques' => 'ALM-EMB',
            ],
        ],
        'Compras' => [
            'code' => 'COM',
            'color' => '#0f766e',
            'sub' => [
                'Proveedores Nacionales' => 'COM-NAC',
                'Importaciones' => 'COM-IMP',
            ],
        ],
        'Sistemas' => [
            'code' => 'SIS',
            'color' => '#3b5bdb',
            'sub' => [
                'Desarrollo' => 'SIS-DEV',
                'Soporte Técnico' => 'SIS-SOP',
                'Infraestructura' => 'SIS-INF',
            ],
        ],
        'Logística' => [
            'code' => 'LOG',
            'color' => '#b8332e',
            'sub' => [
                'Reparto Local' => 'LOG-LOC',
                'Rutas Foráneas' => 'LOG-FOR',
                'Mantenimiento de Flotilla' => 'LOG-MAN',
            ],
        ],
    ];

    /**
     * Sub áreas anidadas: "sub área padre" => sus hijas.
     *
     * Ilustran que la jerarquía no se queda en dos niveles. Se siembran aparte
     * del catálogo plano porque dependen de que sus padres ya existan.
     *
     * @var array<string, array<string, string>>
     */
    private array $anidadas = [
        'Mostrador' => [
            'Turno Matutino' => 'VEN-MOS-MAT',
            'Turno Vespertino' => 'VEN-MOS-VES',
        ],
        'Turno Matutino' => [
            'Caja Matutino' => 'VEN-MOS-MAT-CAJ',
        ],
        'Rutas Foráneas' => [
            'Ruta Norte' => 'LOG-FOR-NTE',
            'Ruta Sur' => 'LOG-FOR-SUR',
        ],
        'Soporte Técnico' => [
            'Mesa de Ayuda' => 'SIS-SOP-MDA',
        ],
    ];

    public function run(): void
    {
        foreach ($this->catalogo as $nombre => $datos) {
            $area = Department::firstOrCreate(
                ['name' => $nombre],
                ['code' => $datos['code'], 'color' => $datos['color'], 'is_active' => true],
            );

            // Áreas sembradas antes de que existiera la columna: se les da su
            // color sin tocar las que RH ya haya recoloreado a mano.
            if ($area->color === null) {
                $area->update(['color' => $datos['color']]);
            }

            foreach ($datos['sub'] as $subNombre => $subClave) {
                SubDepartment::firstOrCreate(
                    ['department_id' => $area->id, 'name' => $subNombre],
                    ['code' => $subClave, 'is_active' => true],
                );
            }
        }

        $this->sembrarAnidadas();

        $this->command->info(sprintf(
            '%d áreas y %d sub áreas disponibles.',
            Department::count(),
            SubDepartment::count(),
        ));
    }

    /**
     * Cuelga las sub áreas anidadas de su padre. El orden del arreglo importa:
     * "Turno Matutino" debe existir antes de sembrar su "Caja Matutino".
     */
    private function sembrarAnidadas(): void
    {
        foreach ($this->anidadas as $padreNombre => $hijas) {
            $padre = SubDepartment::where('name', $padreNombre)->first();

            if ($padre === null) {
                continue;
            }

            foreach ($hijas as $nombre => $clave) {
                SubDepartment::firstOrCreate(
                    ['name' => $nombre, 'parent_id' => $padre->id],
                    [
                        // Hereda el área del padre: toda la rama pertenece a la
                        // misma área, y de ahí toma su color.
                        'department_id' => $padre->department_id,
                        'code' => $clave,
                        'is_active' => true,
                    ],
                );
            }
        }
    }
}
