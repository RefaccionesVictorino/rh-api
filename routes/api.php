<?php

use App\Http\Controllers\AbsenceController;
use App\Http\Controllers\AttendanceIncidentController;
use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\Auth\PasswordResetController;
use App\Http\Controllers\DepartmentController;
use App\Http\Controllers\EmployeeAppAccessController;
use App\Http\Controllers\EmployeeAttendanceController;
use App\Http\Controllers\EmployeeController;
use App\Http\Controllers\EmployeePhotoController;
use App\Http\Controllers\EmployeeShiftController;
use App\Http\Controllers\HolidayController;
use App\Http\Controllers\HolidayRuleController;
use App\Http\Controllers\LocationController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\ScheduleOverrideController;
use App\Http\Controllers\SelfServiceVacationController;
use App\Http\Controllers\ShiftController;
use App\Http\Controllers\SubDepartmentController;
use App\Http\Controllers\TimeClock\ManualPunchController;
use App\Http\Controllers\TimeClock\TimeClockController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\VacationBalanceController;
use App\Http\Controllers\VacationRequestController;
use App\Http\Controllers\VacationRequestSheetController;
use App\Http\Controllers\Worker\WorkerAttendanceController;
use App\Http\Controllers\Worker\WorkerAuthController;
use App\Http\Controllers\Worker\WorkerDeviceController;
use App\Http\Controllers\Worker\WorkerNotificationController;
use App\Http\Controllers\Worker\WorkerVacationController;
use Illuminate\Support\Facades\Route;

Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:login');
Route::post('/forgot-password', [PasswordResetController::class, 'sendLink'])->middleware('throttle:password-reset');
Route::post('/reset-password', [PasswordResetController::class, 'reset'])->middleware('throttle:password-reset');

// Portal del trabajador: sin sesión, se identifica en cada petición con su
// número de checador y fecha de nacimiento (ver IdentifyEmployeeRequest).
Route::prefix('self-service/vacations')->middleware('throttle:self-service')->group(function () {
    Route::post('/lookup', [SelfServiceVacationController::class, 'show']);
    Route::post('/preview', [SelfServiceVacationController::class, 'preview']);
    Route::post('/requests', [SelfServiceVacationController::class, 'store']);
});

// App móvil del trabajador: RFC + NIP a cambio de un token de Sanctum. Sus
// sesiones son de WorkerAccount y no entran a las rutas del panel.
Route::prefix('worker')->group(function () {
    Route::post('/login', [WorkerAuthController::class, 'login'])->middleware('throttle:worker-login');

    Route::middleware(['auth:sanctum', 'worker'])->group(function () {
        Route::get('/me', [WorkerAuthController::class, 'me']);
        Route::post('/logout', [WorkerAuthController::class, 'logout']);
        Route::put('/nip', [WorkerAuthController::class, 'updateNip']);
        Route::put('/device', [WorkerDeviceController::class, 'update']);

        Route::get('/notifications', [WorkerNotificationController::class, 'index']);
        Route::post('/notifications/read-all', [WorkerNotificationController::class, 'markAllAsRead']);
        Route::post('/notifications/{notification}/read', [WorkerNotificationController::class, 'markAsRead']);
        Route::delete('/notifications', [WorkerNotificationController::class, 'destroyAll']);
        Route::delete('/notifications/{notification}', [WorkerNotificationController::class, 'destroy']);

        Route::get('/attendance', [WorkerAttendanceController::class, 'calendar']);
        Route::get('/vacations', [WorkerVacationController::class, 'show']);
        Route::post('/vacations/preview', [WorkerVacationController::class, 'preview']);
        Route::post('/vacations/requests', [WorkerVacationController::class, 'store']);
    });
});

Route::middleware(['auth:sanctum', 'system.access'])->group(function () {
    Route::get('/me', [AuthController::class, 'me']);
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::post('/logout-all', [AuthController::class, 'logoutAll']);

    // Sin permiso: cada usuario administra solo su propia bandeja.
    Route::get('/notifications', [NotificationController::class, 'index']);
    Route::post('/notifications/read-all', [NotificationController::class, 'markAllAsRead']);
    Route::post('/notifications/{notification}/read', [NotificationController::class, 'markAsRead']);

    // Antes del apiResource, por la misma razón que /departments/chart.
    Route::get('/users/linkable-employees', [UserController::class, 'linkableEmployees'])
        ->middleware('permission:usuarios.crear|usuarios.editar');

    Route::apiResource('users', UserController::class)
        ->middlewareFor(['index', 'show'], 'permission:usuarios.ver')
        ->middlewareFor('store', 'permission:usuarios.crear')
        ->middlewareFor('update', 'permission:usuarios.editar')
        ->middlewareFor('destroy', 'permission:usuarios.eliminar');

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
    // Igual que el alta: la baja también retira al trabajador del checador.
    Route::delete('/employees/{employee}', [EmployeeController::class, 'destroy'])
        ->middleware(['permission:empleados.eliminar', 'permission:checador.administrar']);
    Route::post('/employees/{terminated_employee}/restore', [EmployeeController::class, 'restore'])
        ->middleware(['permission:empleados.reactivar', 'permission:checador.administrar']);

    // POST y no PUT: el archivo viaja como multipart y PHP solo puebla $_FILES
    // en peticiones POST.
    Route::post('/employees/{employee}/photo', [EmployeePhotoController::class, 'update'])
        ->middleware('permission:empleados.editar');
    Route::delete('/employees/{employee}/photo', [EmployeePhotoController::class, 'destroy'])
        ->middleware('permission:empleados.editar');

    // Acceso del trabajador a la app móvil: NIP temporal y sesiones.
    Route::get('/employees/{employee}/app-access', [EmployeeAppAccessController::class, 'show'])
        ->middleware('permission:acceso_app.ver');
    Route::post('/employees/{employee}/app-access/nip', [EmployeeAppAccessController::class, 'issueNip'])
        ->middleware('permission:acceso_app.administrar');
    Route::delete('/employees/{employee}/app-access/sessions', [EmployeeAppAccessController::class, 'revokeSessions'])
        ->middleware('permission:acceso_app.administrar');

    // Calendario de un empleado: días cerrados desde attendance_days, hoy al vuelo.
    Route::get('/employees/{employee}/attendance', [EmployeeAttendanceController::class, 'calendar'])
        ->middleware('permission:empleados.ver');

    // Incidencias de toda la empresa (retardos, faltas, salidas sin checar,
    // comida excedida) leídas del resumen diario guardado.
    Route::get('/attendance/incidents', [AttendanceIncidentController::class, 'index'])
        ->middleware('permission:asistencia.ver');
    Route::post('/attendance/days/{attendance_day}/justify', [AttendanceIncidentController::class, 'justify'])
        ->middleware('permission:asistencia.justificar');
    Route::delete('/attendance/days/{attendance_day}/justify', [AttendanceIncidentController::class, 'unjustify'])
        ->middleware('permission:asistencia.justificar');

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

    // Catálogo de festivos que se repiten cada año; sus fechas se calculan al
    // consultar el calendario, no se capturan.
    Route::apiResource('holiday-rules', HolidayRuleController::class)
        ->parameters(['holiday-rules' => 'holiday_rule'])
        ->middlewareFor(['index', 'show'], 'permission:festivos.ver')
        ->middlewareFor('store', 'permission:festivos.crear')
        ->middlewareFor('update', 'permission:festivos.editar')
        ->middlewareFor('destroy', 'permission:festivos.eliminar');

    Route::apiResource('holidays', HolidayController::class)
        ->middlewareFor(['index', 'show'], 'permission:festivos.ver')
        ->middlewareFor('store', 'permission:festivos.crear')
        ->middlewareFor('update', 'permission:festivos.editar')
        ->middlewareFor('destroy', 'permission:festivos.eliminar');

    // Excepción de horario de un empleado en un día concreto; manda sobre el
    // festivo y sobre el turno.
    Route::get('/employees/{employee}/schedule-overrides', [ScheduleOverrideController::class, 'index'])
        ->middleware('permission:turnos.ver');
    Route::post('/employees/{employee}/schedule-overrides', [ScheduleOverrideController::class, 'store'])
        ->middleware('permission:turnos.asignar');
    Route::delete('/schedule-overrides/{schedule_override}', [ScheduleOverrideController::class, 'destroy'])
        ->middleware('permission:turnos.asignar');

    // Tabulador de la ley: antes del resto para que "entitlements" no entre
    // por una ruta con parámetro.
    Route::get('/vacations/entitlements', [VacationBalanceController::class, 'entitlements'])
        ->middleware('permission:vacaciones.ver');
    Route::put('/vacations/entitlements', [VacationBalanceController::class, 'updateEntitlements'])
        ->middleware('permission:vacaciones.configurar');

    Route::get('/vacations/requests', [VacationRequestController::class, 'index'])
        ->middleware('permission:vacaciones.ver');

    Route::get('/employees/{employee}/vacation-balance', [VacationBalanceController::class, 'show'])
        ->middleware('permission:vacaciones.ver');
    Route::get('/employees/{employee}/vacation-requests', [VacationRequestController::class, 'forEmployee'])
        ->middleware('permission:vacaciones.ver');
    Route::post('/employees/{employee}/vacation-requests/preview', [VacationRequestController::class, 'preview'])
        ->middleware('permission:vacaciones.ver');
    Route::post('/employees/{employee}/vacation-requests/branch-overlaps', [VacationRequestController::class, 'branchOverlaps'])
        ->middleware('permission:vacaciones.ver');
    Route::post('/employees/{employee}/vacation-requests', [VacationRequestController::class, 'store'])
        ->middleware('permission:vacaciones.solicitar');

    Route::post('/vacation-requests/{vacation_request}/approve', [VacationRequestController::class, 'approve'])
        ->middleware('permission:vacaciones.autorizar');
    Route::post('/vacation-requests/{vacation_request}/reject', [VacationRequestController::class, 'reject'])
        ->middleware('permission:vacaciones.autorizar');
    Route::post('/vacation-requests/{vacation_request}/cancel', [VacationRequestController::class, 'cancel'])
        ->middleware('permission:vacaciones.solicitar');

    // Ficha de la solicitud (para firmarse a mano) y concentrado anual del
    // periodo; ambos también en PDF.
    Route::get('/vacation-requests/{vacation_request}/sheet', [VacationRequestSheetController::class, 'show'])
        ->middleware('permission:vacaciones.ver');
    Route::get('/vacation-requests/{vacation_request}/sheet/pdf', [VacationRequestSheetController::class, 'pdf'])
        ->middleware('permission:vacaciones.ver');
    Route::get('/vacation-periods/{vacation_period}/summary/pdf', [VacationRequestSheetController::class, 'periodSummaryPdf'])
        ->middleware('permission:vacaciones.ver');

    Route::put('/vacation-periods/{vacation_period}/adjust', [VacationBalanceController::class, 'adjust'])
        ->middleware('permission:vacaciones.configurar');
    Route::put('/vacation-periods/{vacation_period}/premium', [VacationBalanceController::class, 'payPremium'])
        ->middleware('permission:vacaciones.pagar_prima');

    // Incapacidades y permisos. Alta y edición van por POST: el certificado
    // viaja como multipart y PHP solo puebla $_FILES en peticiones POST.
    Route::get('/absences', [AbsenceController::class, 'index'])
        ->middleware('permission:ausencias.ver');
    Route::get('/employees/{employee}/absences', [AbsenceController::class, 'forEmployee'])
        ->middleware('permission:ausencias.ver');
    Route::post('/employees/{employee}/absences/conflicts', [AbsenceController::class, 'conflicts'])
        ->middleware('permission:ausencias.ver');
    Route::post('/employees/{employee}/absences', [AbsenceController::class, 'store'])
        ->middleware('permission:ausencias.registrar');
    Route::post('/absences/{absence}', [AbsenceController::class, 'update'])
        ->middleware('permission:ausencias.registrar');
    Route::delete('/absences/{absence}', [AbsenceController::class, 'destroy'])
        ->middleware('permission:ausencias.registrar');

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

        // No pasa por el equipo: se guarda al momento y responde 201.
        Route::post('/punches', [ManualPunchController::class, 'store'])
            ->middleware('permission:checador.extraoficial');
    });
});
