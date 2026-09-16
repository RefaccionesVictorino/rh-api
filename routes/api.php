<?php

use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\DepartmentController;
use App\Http\Controllers\EmployeeAttendanceController;
use App\Http\Controllers\EmployeeController;
use App\Http\Controllers\EmployeePhotoController;
use App\Http\Controllers\EmployeeShiftController;
use App\Http\Controllers\LocationController;
use App\Http\Controllers\ShiftController;
use App\Http\Controllers\SubDepartmentController;
use App\Http\Controllers\TimeClock\TimeClockController;
use Illuminate\Support\Facades\Route;

Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:login');

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/me', [AuthController::class, 'me']);
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::post('/logout-all', [AuthController::class, 'logoutAll']);

    Route::get('/employees', [EmployeeController::class, 'index'])
        ->middleware('permission:empleados.ver');
    // El alta crea también el usuario del checador y encola su envío a las
    // terminales, de ahí que pida ambos permisos.
    Route::post('/employees', [EmployeeController::class, 'store'])
        ->middleware(['permission:empleados.crear', 'permission:checador.administrar']);
    Route::get('/employees/{employee}', [EmployeeController::class, 'show'])
        ->middleware('permission:empleados.ver');
    Route::put('/employees/{employee}', [EmployeeController::class, 'update'])
        ->middleware('permission:empleados.editar');

    // POST y no PUT: el archivo viaja como multipart y PHP solo puebla $_FILES
    // en peticiones POST.
    Route::post('/employees/{employee}/photo', [EmployeePhotoController::class, 'update'])
        ->middleware('permission:empleados.editar');
    Route::delete('/employees/{employee}/photo', [EmployeePhotoController::class, 'destroy'])
        ->middleware('permission:empleados.editar');

    // Asistencia calculada al vuelo desde las checadas y el turno vigente.
    Route::get('/employees/{employee}/attendance', [EmployeeAttendanceController::class, 'calendar'])
        ->middleware('permission:empleados.ver');

    // Antes del apiResource: si no, /departments/chart entra por show() con
    // "chart" como id y falla el route model binding.
    Route::get('/departments/chart', [DepartmentController::class, 'chart'])
        ->middleware('permission:areas.ver');

    Route::apiResource('departments', DepartmentController::class)->middlewareFor([
        'index', 'show',
    ], 'permission:areas.ver')->middlewareFor('store', 'permission:areas.crear')
        ->middlewareFor('update', 'permission:areas.editar')
        ->middlewareFor('destroy', 'permission:areas.eliminar');

    Route::get('/sub-departments/{sub_department}/children', [SubDepartmentController::class, 'children'])
        ->middleware('permission:subareas.ver');

    Route::get('/sub-departments/{sub_department}/members', [SubDepartmentController::class, 'members'])
        ->middleware(['permission:subareas.ver', 'permission:empleados.ver']);

    Route::apiResource('sub-departments', SubDepartmentController::class)
        ->parameters(['sub-departments' => 'sub_department'])
        ->middlewareFor(['index', 'show'], 'permission:subareas.ver')
        ->middlewareFor('store', 'permission:subareas.crear')
        ->middlewareFor('update', 'permission:subareas.editar')
        ->middlewareFor('destroy', 'permission:subareas.eliminar');

    Route::apiResource('locations', LocationController::class)
        ->middlewareFor(['index', 'show'], 'permission:sucursales.ver')
        ->middlewareFor('store', 'permission:sucursales.crear')
        ->middlewareFor('update', 'permission:sucursales.editar')
        ->middlewareFor('destroy', 'permission:sucursales.eliminar');

    Route::apiResource('shifts', ShiftController::class)
        ->middlewareFor(['index', 'show'], 'permission:turnos.ver')
        ->middlewareFor('store', 'permission:turnos.crear')
        ->middlewareFor('update', 'permission:turnos.editar')
        ->middlewareFor('destroy', 'permission:turnos.eliminar');

    // Asignación de turnos con vigencia. Se consulta desde el empleado (su
    // historial) o desde el turno (quiénes están en él hoy).
    Route::get('/employees/{employee}/shift-assignments', [EmployeeShiftController::class, 'index'])
        ->middleware('permission:turnos.ver');
    Route::post('/employees/{employee}/shift-assignments', [EmployeeShiftController::class, 'store'])
        ->middleware('permission:turnos.asignar');
    Route::get('/shifts/{shift}/assignments', [EmployeeShiftController::class, 'forShift'])
        ->middleware('permission:turnos.ver');
    Route::post('/shifts/{shift}/assignments', [EmployeeShiftController::class, 'bulkStore'])
        ->middleware('permission:turnos.asignar');
    Route::patch('/shift-assignments/{shift_assignment}', [EmployeeShiftController::class, 'update'])
        ->middleware('permission:turnos.asignar');
    Route::delete('/shift-assignments/{shift_assignment}', [EmployeeShiftController::class, 'destroy'])
        ->middleware('permission:turnos.asignar');

    // Administración del checador. Las escrituras devuelven 202: el terminal
    // aplica los cambios cuando sondea, no al instante.
    Route::prefix('time-clock')->group(function () {
        Route::middleware('permission:checador.ver')->group(function () {
            Route::get('/devices', [TimeClockController::class, 'devices']);
            Route::get('/devices/{device}/commands', [TimeClockController::class, 'commands']);
            Route::get('/punches', [TimeClockController::class, 'punches']);
            Route::get('/users', [TimeClockController::class, 'users']);
        });

        Route::middleware('permission:checador.administrar')->group(function () {
            // Responde 200 y no 202: es el único de este grupo que no encola.
            Route::put('/devices/{device}', [TimeClockController::class, 'updateDevice']);
            Route::post('/devices/{device}/execute', [TimeClockController::class, 'execute']);
            Route::post('/users', [TimeClockController::class, 'storeUser']);
            Route::delete('/users/{user}', [TimeClockController::class, 'destroyUser']);
        });
    });
});
