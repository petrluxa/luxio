<?php

namespace App\Models;

use App\Support\Cz;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Service extends Model
{
    use HasFactory;

    protected $fillable = [
        'salon_id', 'name', 'description', 'price_from', 'price_to',
        'work_1', 'pause', 'work_2', 'buffer_after', 'is_active', 'sort',
    ];

    protected function casts(): array
    {
        return [
            'price_from' => 'integer',
            'price_to' => 'integer',
            'work_1' => 'integer',
            'pause' => 'integer',
            'work_2' => 'integer',
            'buffer_after' => 'integer',
            'is_active' => 'boolean',
            'sort' => 'integer',
        ];
    }

    public function salon(): BelongsTo
    {
        return $this->belongsTo(Salon::class);
    }

    public function staff(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'staff_service')
            ->withPivot(['price_from', 'work_1', 'pause', 'work_2']);
    }

    /** Celková délka pro zákaznici (bez úklidu). */
    public function totalMinutes(): int
    {
        return $this->work_1 + $this->pause + $this->work_2;
    }

    public function durationLabel(): string
    {
        return Cz::duration($this->totalMinutes());
    }

    public function priceLabel(): string
    {
        return Cz::price($this->price_from, $this->price_to);
    }
}
