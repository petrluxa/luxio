<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Notifications\Notifiable;
use NotificationChannels\WebPush\HasPushSubscriptions;

class Customer extends Model
{
    use HasFactory, HasPushSubscriptions, Notifiable;

    protected $fillable = [
        'salon_id', 'name', 'phone', 'preferred_staff_id',
        'note', 'color_recipe', 'is_blocked', 'is_verified', 'notifications_enabled', 'consent_at',
    ];

    /** Výchozí hodnoty i pro čerstvě vytvořený záznam (stejné jako v databázi). */
    protected $attributes = [
        'is_blocked' => false,
        'is_verified' => false,
        'notifications_enabled' => true,
    ];

    protected function casts(): array
    {
        return [
            'consent_at' => 'datetime',
            'is_blocked' => 'boolean',
            'is_verified' => 'boolean',
            'notifications_enabled' => 'boolean',
        ];
    }

    public function salon(): BelongsTo
    {
        return $this->belongsTo(Salon::class);
    }

    public function preferredStaff(): BelongsTo
    {
        return $this->belongsTo(User::class, 'preferred_staff_id');
    }

    public function accessTokens(): HasMany
    {
        return $this->hasMany(CustomerAccessToken::class);
    }

    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class);
    }

    /**
     * Zákaznice, které přihlášený v administraci vidí: správce salonu všechny, kadeřnice jen své –
     * ty, které k ní chodí (mají u ní rezervaci) nebo ji mají jako svou kadeřnici.
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        $query->where($this->qualifyColumn('salon_id'), $user->salon_id);

        if ($user->isOwner()) {
            return $query;
        }

        return $query->where(fn (Builder $own) => $own
            ->where($this->qualifyColumn('preferred_staff_id'), $user->id)
            ->orWhereHas('bookings', fn (Builder $bookings) => $bookings->where('staff_id', $user->id)));
    }

    /**
     * Dostane upozornění na telefon? Jinak se o změně termínu sama nedozví
     * (aplikace neposílá e-maily ani SMS) a musí jí dát vědět kadeřnice.
     */
    public function reachableByPush(): bool
    {
        return $this->notifications_enabled && $this->pushSubscriptions()->exists();
    }

    /** Křestní jméno pro oslovení v notifikacích. */
    public function firstName(): string
    {
        return explode(' ', trim($this->name))[0];
    }

    /**
     * Příjmení pro blok v rozvrhu („Klára Konečná“ → „Konečná“). Dovětek za příjmením s tečkou
     * („Novák ml.“) k němu patří; jméno o jednom slově se vrací celé.
     */
    public function surname(): string
    {
        $parts = preg_split('/\s+/u', trim($this->name), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        for ($i = count($parts) - 1; $i > 0; $i--) {
            if (! str_ends_with($parts[$i], '.')) {
                return implode(' ', array_slice($parts, $i));
            }
        }

        return $parts === [] ? '' : implode(' ', $parts);
    }

    /** Nejbližší budoucí termín, který ještě platí. */
    public function nextBooking(): ?Booking
    {
        return $this->bookings()->upcoming()->orderBy('starts_at')->first();
    }
}
