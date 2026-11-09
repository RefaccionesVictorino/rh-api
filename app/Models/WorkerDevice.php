<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Laravel\Sanctum\PersonalAccessToken;

/** Teléfono con una sesión abierta en la app; uno por token. */
class WorkerDevice extends Model
{
    public const PLATFORMS = ['ios', 'android', 'web'];

    protected $fillable = [
        'worker_account_id',
        'personal_access_token_id',
        'installation_id',
        'platform',
        'device_name',
        'os_version',
        'app_version',
        'expo_push_token',
    ];

    public function workerAccount(): BelongsTo
    {
        return $this->belongsTo(WorkerAccount::class);
    }

    public function accessToken(): BelongsTo
    {
        return $this->belongsTo(PersonalAccessToken::class, 'personal_access_token_id');
    }
}
