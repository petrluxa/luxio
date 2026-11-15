<?php

namespace App\Models;

use App\Support\SalonSettings;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Salon extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'slug', 'phone', 'address', 'timezone', 'settings'];

    protected function casts(): array
    {
        return ['settings' => 'array'];
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    /** Pravidla salonu s doplněnými výchozími hodnotami. */
    public function rules(): SalonSettings
    {
        return new SalonSettings($this->settings);
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /** Kadeřnice, ke kterým se lze objednat. */
    public function staff(): HasMany
    {
        return $this->hasMany(User::class)->where('is_bookable', true)->orderBy('name');
    }

    public function services(): HasMany
    {
        return $this->hasMany(Service::class)->orderBy('sort')->orderBy('id');
    }

    public function customers(): HasMany
    {
        return $this->hasMany(Customer::class);
    }

    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class);
    }
}
