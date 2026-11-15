<?php

namespace App\Models;

use App\Enums\SegmentType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Úsek rezervace v kalendáři kadeřnice. `work` a `buffer` blokují, `pause` ne.
 */
class BookingSegment extends Model
{
    public $timestamps = false;

    protected $fillable = ['booking_id', 'staff_id', 'starts_at', 'ends_at', 'type'];

    protected function casts(): array
    {
        return [
            'starts_at' => 'immutable_datetime',
            'ends_at' => 'immutable_datetime',
            'type' => SegmentType::class,
        ];
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }
}
