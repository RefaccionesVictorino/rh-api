<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Fecha de corte del módulo
    |--------------------------------------------------------------------------
    |
    | Fecha desde la que el sistema lleva el control. Los periodos que cerraron
    | antes no se generan: el módulo arrancó sin el histórico de lo que cada
    | quien tomó, y darlos por no gozados inventaría un pasivo sin comprobar.
    |
    | La antigüedad no se toca: el tabulador sigue contando desde el ingreso, así
    | que quien lleva catorce años estrena el módulo con los días de su año 14.
    |
    | Sin valor, se generan todos los periodos desde el ingreso.
    |
    */
    'start_date' => env('VACATIONS_START_DATE'),
];
