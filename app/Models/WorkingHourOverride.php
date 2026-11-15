<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Výjimka pro konkrétní den – nahrazuje běžnou pracovní dobu
 * a platí i ve státní svátek (např. pracovní sobota, Štědrý den dopoledne).
 */
class WorkingHourOverride extends Model
{
    public $timestamps = false;

    protected $fillable = ['user_id', 'date', 'starts_at', 'ends_at'];

    protected function casts(): array
    {
        return ['date' => 'date'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
