<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Přípravek použitý při návštěvě (opis názvu + odstín nebo množství).
 */
class BookingProduct extends Model
{
    public $timestamps = false;

    protected $fillable = ['booking_id', 'product_id', 'name', 'note', 'sequence'];

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** „L'Oréal Professionnel Majirel – 7.1, 30 g“ */
    public function label(): string
    {
        return $this->name.($this->note ? ' – '.$this->note : '');
    }
}
