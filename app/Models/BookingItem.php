<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Snapshot služby v rezervaci (název, délky, cena) – změna ceníku nemění historii.
 */
class BookingItem extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'booking_id', 'service_id', 'name', 'price', 'price_to',
        'work_1', 'pause', 'work_2', 'buffer_after', 'sequence',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'integer',
            'price_to' => 'integer',
            'work_1' => 'integer',
            'pause' => 'integer',
            'work_2' => 'integer',
            'buffer_after' => 'integer',
            'sequence' => 'integer',
        ];
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }
}
