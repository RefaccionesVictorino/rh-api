<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RolesAndPermissionsSeeder extends Seeder
{
    /**
     * Permisos base del sistema. Agrega aquí los módulos conforme crezca el proyecto.
     *
     * @var array<string, list<string>>
     */
    private array $permissions = [
        'usuarios' => ['ver', 'crear', 'editar', 'eliminar'],
        'roles' => ['ver', 'crear', 'editar', 'eliminar'],
        'empleados' => ['ver', 'crear', 'editar', 'eliminar'],
        'areas' => ['ver', 'crear', 'editar', 'eliminar'],
        'subareas' => ['ver', 'crear', 'editar', 'eliminar'],
        'turnos' => ['ver', 'crear', 'editar', 'eliminar', 'asignar'],
        'checador' => ['ver', 'administrar'],
    ];

    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $all = [];
        foreach ($this->permissions as $module => $actions) {
            foreach ($actions as $action) {
                $all[] = Permission::firstOrCreate([
                    'name' => "{$module}.{$action}",
                    'guard_name' => 'web',
                ])->name;
            }
        }

        $admin = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
        $admin->syncPermissions($all);

        $rh = Role::firstOrCreate(['name' => 'rh', 'guard_name' => 'web']);
        $rh->syncPermissions([
            'empleados.ver', 'empleados.crear', 'empleados.editar',
            'areas.ver', 'areas.crear', 'areas.editar',
            'subareas.ver', 'subareas.crear', 'subareas.editar',
            'turnos.ver', 'turnos.crear', 'turnos.editar', 'turnos.asignar',
            'checador.ver', 'checador.administrar',
        ]);

        Role::firstOrCreate(['name' => 'empleado', 'guard_name' => 'web']);

        $user = User::firstOrCreate(
            ['email' => 'admin@victorino.com'],
            ['name' => 'Administrador', 'password' => 'password']
        );
        $user->syncRoles(['admin']);
    }
}
