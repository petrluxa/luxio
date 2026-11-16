<?php

namespace App\Models;

use App\Support\SalonSettings;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Salon extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'sign_title', 'sign_subtitle', 'company', 'ico', 'slug', 'phone', 'address', 'timezone', 'settings'];

    protected function casts(): array
    {
        return ['settings' => 'array'];
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    /** Velký nápis na „ceduli“ v záhlaví rozvrhu – vlastní, nebo název salonu. */
    public function signTitle(): string
    {
        return trim((string) $this->sign_title) !== '' ? trim($this->sign_title) : $this->name;
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

    /** Tým, ke kterému se lze objednat – v pořadí sloupců rozvrhu (řadí správce). */
    public function staff(): HasMany
    {
        return $this->hasMany(User::class)->where('is_bookable', true)->orderBy('sort')->orderBy('id');
    }

    public function services(): HasMany
    {
        return $this->hasMany(Service::class)->orderBy('sort')->orderBy('id');
    }

    public function customers(): HasMany
    {
        return $this->hasMany(Customer::class);
    }

    /** Katalog přípravků salonu. */
    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class);
    }
}
