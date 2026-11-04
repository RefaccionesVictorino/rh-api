<?php

namespace App\Models;

use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Notifications\DatabaseNotification;

/**
 * Registro del canal `database`. Extiende el del framework solo para la baja
 * lógica; User::notifications() lo usa en lugar del original.
 */
class Notification extends DatabaseNotification
{
    use SoftDeletes;
}
