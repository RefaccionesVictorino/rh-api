<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class VacationRequestDay extends Model
{
    use SoftDeletes;

    protected $fillable = ['vacation_request_id', 'vacation_period_id', 'date', 'days'];

    protected function casts(): array
    {
        return [
            'date' => 'date:Y-m-d',
            'days' => 'float',
        ];
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(VacationRequest::class, 'vacation_request_id');
    }

    public function period(): BelongsTo
    {
        return $this->belongsTo(VacationPeriod::class, 'vacation_period_id');
    }
}
