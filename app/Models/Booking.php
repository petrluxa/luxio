<?php

namespace App\Models;

use App\Enums\BookingSource;
use App\Enums\BookingStatus;
use App\Enums\PaymentMethod;
use App\Services\Availability\EffectiveService;
use App\Services\Availability\ServicePlan;
use App\Support\Cz;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Booking extends Model
{
    protected $fillable = [
        'salon_id', 'staff_id', 'customer_id', 'starts_at', 'ends_at', 'status', 'total_price',
        'customer_note', 'staff_note', 'source', 'reminder_sent_at', 'second_reminder_sent_at',
        'cancelled_at', 'cancel_reason', 'paid_amount', 'payment_method', 'paid_at', 'receipt_number',
    ];

    protected function casts(): array
    {
        return [
            'starts_at' => 'immutable_datetime',
            'ends_at' => 'immutable_datetime',
            'status' => BookingStatus::class,
            'source' => BookingSource::class,
            'total_price' => 'integer',
            'paid_amount' => 'integer',
            'payment_method' => PaymentMethod::class,
            'paid_at' => 'immutable_datetime',
            'reminder_sent_at' => 'datetime',
            'second_reminder_sent_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function salon(): BelongsTo
    {
        return $this->belongsTo(Salon::class);
    }

    public function staff(): BelongsTo
    {
        return $this->belongsTo(User::class, 'staff_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(BookingItem::class)->orderBy('sequence');
    }

    public function segments(): HasMany
    {
        return $this->hasMany(BookingSegment::class)->orderBy('starts_at');
    }

    /** Přípravky, které kadeřnice při návštěvě použila. */
    public function products(): HasMany
    {
        return $this->hasMany(BookingProduct::class)->orderBy('sequence')->orderBy('id');
    }

    public function isPaid(): bool
    {
        return $this->paid_amount !== null && $this->paid_at !== null;
    }

    /**
     * Kód v adrese dokladu o zaplacení. Počítá se z klíče aplikace, nikde se neukládá
     * a nedá se uhodnout – kdo nemá odkaz, doklad neotevře.
     */
    public function receiptCode(): string
    {
        return substr(hash_hmac('sha256', 'doklad|'.$this->id.'|'.$this->receipt_number, (string) config('app.key')), 0, 32);
    }

    /** Adresa dokladu ke sdílení (WhatsApp, Messenger), nebo null, dokud není zaplaceno. */
    public function receiptUrl(): ?string
    {
        return $this->isPaid() && $this->receipt_number
            ? route('receipt', ['booking' => $this->id, 'code' => $this->receiptCode()])
            : null;
    }

    /** Budoucí termíny, které ještě platí (čekající i potvrzené). */
    public function scopeUpcoming(Builder $query): Builder
    {
        return $query->whereIn('status', BookingStatus::active())->where('starts_at', '>=', now());
    }

    /** Rezervace, které drží místo v kalendáři. */
    public function scopeOccupying(Builder $query): Builder
    {
        return $query->whereIn('status', BookingStatus::occupying());
    }

    /** „Dámský střih + Foukaná“ */
    public function servicesLabel(): string
    {
        return $this->items->pluck('name')->join(' + ');
    }

    /** Cena pro zobrazení – „od“, pokud některá služba má rozpětí. */
    public function priceLabel(): string
    {
        $hasRange = $this->items->contains(fn (BookingItem $i) => $i->price_to !== null && $i->price_to > $i->price);

        return ($hasRange ? 'od ' : '').Cz::money($this->total_price);
    }

    /** Časový plán podle snapshotu služeb (ne podle aktuálního ceníku). */
    public function plan(): ServicePlan
    {
        return ServicePlan::fromEffective($this->items->map(fn (BookingItem $item) => new EffectiveService(
            serviceId: $item->service_id,
            name: $item->name,
            priceFrom: $item->price,
            priceTo: $item->price_to,
            work1: $item->work_1,
            pause: $item->pause,
            work2: $item->work_2,
            bufferAfter: $item->buffer_after,
        ))->all());
    }

    public function durationMinutes(): int
    {
        return (int) $this->starts_at->diffInMinutes($this->ends_at);
    }

    /** Zákaznice smí zrušit jen živou rezervaci a nejpozději X hodin předem. */
    public function canBeCancelledByCustomer(): bool
    {
        return $this->status->isActive()
            && now()->lte($this->starts_at->subHours($this->salon->rules()->cancelHours));
    }

    public function isPast(): bool
    {
        return $this->ends_at->isPast();
    }
}
