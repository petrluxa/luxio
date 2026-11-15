<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Běžná pracovní doba: kadeřnice × den v týdnu × interval.
 * Víc intervalů v jednom dni = polední pauza.
 */
class WorkingHour extends Model
{
    public $timestamps = false;

    protected $fillable = ['user_id', 'weekday', 'starts_at', 'ends_at'];

    protected function casts(): array
    {
        return ['weekday' => 'integer'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
