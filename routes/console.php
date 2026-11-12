<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// A las 05:00 ya salieron los turnos nocturnos y el equipo entregó sus
// checadas; lo que llegue después lo recalcula su propio disparador.
Schedule::command('attendance:close')
    ->dailyAt('05:00')
    ->timezone(config('time_clock.timezone'))
    ->withoutOverlapping();

Schedule::command('attendance:notify-missing')
    ->everyThirtyMinutes()
    ->withoutOverlapping();
