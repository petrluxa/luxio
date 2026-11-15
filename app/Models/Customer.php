<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Notifications\Notifiable;

class Customer extends Model
{
    use HasFactory, Notifiable;

    protected $fillable = [
        'salon_id', 'name', 'phone', 'email', 'email_verified_at', 'preferred_staff_id',
        'note', 'color_recipe', 'is_blocked', 'is_verified', 'notifications_enabled', 'consent_at',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
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

    /** Křestní jméno pro oslovení v notifikacích. */
    public function firstName(): string
    {
        return explode(' ', trim($this->name))[0];
    }

    /** Nejbližší budoucí termín, který ještě platí. */
    public function nextBooking(): ?Booking
    {
        return $this->bookings()->upcoming()->orderBy('starts_at')->first();
    }
}
