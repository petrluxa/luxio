<?php

namespace App\Models;

use App\Enums\UserRole;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use NotificationChannels\WebPush\HasPushSubscriptions;

/**
 * Správce salonu nebo kadeřnice. Přihlašuje se jménem (`login`) a heslem;
 * kadeřnice bez přihlašovacích údajů je jen v rozvrhu a sama se nepřihlásí.
 */
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasPushSubscriptions, Notifiable;

    protected $fillable = ['salon_id', 'name', 'login', 'password', 'role', 'color', 'is_bookable'];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'role' => UserRole::class,
            'is_bookable' => 'boolean',
        ];
    }

    public function isOwner(): bool
    {
        return $this->role === UserRole::Owner;
    }

    /** Má vlastní přihlášení do administrace? */
    public function canLogIn(): bool
    {
        return $this->login !== null && $this->password !== null;
    }

    public function salon(): BelongsTo
    {
        return $this->belongsTo(Salon::class);
    }

    /** Služby, které kadeřnice dělá; pivot nese její vlastní cenu a délky. */
    public function services(): BelongsToMany
    {
        return $this->belongsToMany(Service::class, 'staff_service')
            ->withPivot(['price_from', 'work_1', 'pause', 'work_2']);
    }

    public function workingHours(): HasMany
    {
        return $this->hasMany(WorkingHour::class)->orderBy('weekday')->orderBy('starts_at');
    }

    public function workingHourOverrides(): HasMany
    {
        return $this->hasMany(WorkingHourOverride::class)->orderBy('date')->orderBy('starts_at');
    }

    public function timeOffs(): HasMany
    {
        return $this->hasMany(TimeOff::class)->orderBy('starts_at');
    }

    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class, 'staff_id');
    }
}
