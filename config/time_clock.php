<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Llave de acceso de los terminales
    |--------------------------------------------------------------------------
    |
    | Los endpoints /iclock son públicos por diseño: el firmware del checador no
    | sabe enviar tokens ni cookies. Si se define esta llave, el equipo debe
    | incluirla como parámetro `key` para ser atendido. Requiere firmware que
    | permita una URL de servidor con query string; si el tuyo no lo permite,
    | déjala vacía y restringe por IP o VPN en el servidor web.
    |
    */
    'access_key' => env('TIME_CLOCK_KEY'),

    /*
    |--------------------------------------------------------------------------
    | Registro automático de terminales
    |--------------------------------------------------------------------------
    |
    | Si está activo, un serial desconocido se da de alta solo. Cómodo en la
    | instalación inicial; conviene apagarlo en producción para que nadie más
    | pueda registrar equipos contra tu servidor.
    |
    */
    'auto_register' => env('TIME_CLOCK_AUTO_REGISTER', true),

    /*
    |--------------------------------------------------------------------------
    | Parámetros de sondeo entregados al equipo en el saludo inicial
    |--------------------------------------------------------------------------
    |
    | delay            segundos entre sondeos con red normal
    | error_delay      segundos de espera para reintentar tras un fallo de red
    | trans_interval   minutos entre envíos por lote
    | trans_times      horas del día en que fuerza un envío
    | realtime         1 = manda cada checada al instante (recomendado)
    | timezone_offset  desfase horario del equipo, en horas
    |
    */
    'delay' => env('TIME_CLOCK_DELAY', 30),
    'error_delay' => env('TIME_CLOCK_ERROR_DELAY', 60),
    'trans_interval' => env('TIME_CLOCK_TRANS_INTERVAL', 1),
    'trans_times' => env('TIME_CLOCK_TRANS_TIMES', '00:00;14:00'),
    'realtime' => env('TIME_CLOCK_REALTIME', 1),
    'timezone_offset' => env('TIME_CLOCK_TIMEZONE_OFFSET', -6),

    /*
    |--------------------------------------------------------------------------
    | Zona horaria de las checadas
    |--------------------------------------------------------------------------
    |
    | El equipo manda la hora en su horario local, sin indicar zona. Aquí se
    | declara cuál es. La checada se guarda como hora de pared, sin convertir
    | a UTC: en nómina cuenta la hora del reloj, y convertir desplazaría las
    | checadas de la tarde al día siguiente.
    |
    */
    'timezone' => env('TIME_CLOCK_TIMEZONE', 'America/Mexico_City'),

    /*
    |--------------------------------------------------------------------------
    | Bitácora del protocolo
    |--------------------------------------------------------------------------
    |
    | Deja rastro de cada petición cruda del equipo. Muy útil al instalar;
    | conviene apagarlo después porque genera bastante volumen.
    |
    */
    'log' => env('TIME_CLOCK_LOG', true),
    'log_channel' => env('TIME_CLOCK_LOG_CHANNEL', 'stack'),
];
