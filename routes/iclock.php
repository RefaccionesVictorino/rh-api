<?php

use App\Http\Controllers\TimeClock\IclockController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Rutas del terminal ZKTeco (protocolo PUSH / ADMS)
|--------------------------------------------------------------------------
|
| Las consume el checador, no un navegador ni el frontend. Van sin prefijo
| /api y sin middleware de sesión porque el firmware no maneja cookies,
| tokens ni CSRF: solo sabe pedir estas cuatro URLs en texto plano.
|
| La ruta debe ser exactamente /iclock/... — está fija en el firmware.
|
*/

Route::prefix('iclock')->group(function () {
    // El saludo llega por GET y los lotes de datos por POST, a la misma URL.
    Route::match(['get', 'post'], 'cdata', [IclockController::class, 'cdata']);

    Route::get('getrequest', [IclockController::class, 'getrequest']);
    Route::post('devicecmd', [IclockController::class, 'devicecmd']);
    Route::post('fdata', [IclockController::class, 'fdata']);

    // Algunos firmwares usan /iclock/ping para comprobar el servidor.
    Route::match(['get', 'post'], 'ping', fn () => response('OK', 200, ['Content-Type' => 'text/plain']));
});
