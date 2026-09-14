<?php

use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\DepartmentController;
use App\Http\Controllers\EmployeeController;
use App\Http\Controllers\SubDepartmentController;
use Illuminate\Support\Facades\Route;

Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:login');

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/me', [AuthController::class, 'me']);
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::post('/logout-all', [AuthController::class, 'logoutAll']);

    Route::get('/employees', [EmployeeController::class, 'index'])
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
});
