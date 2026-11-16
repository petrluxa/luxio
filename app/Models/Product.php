<?php

namespace App\Models;

use App\Enums\ProductCategory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Přípravek z katalogu salonu (barva, šampon, péče, styling, ampule).
 */
class Product extends Model
{
    protected $fillable = ['salon_id', 'brand', 'line', 'name', 'kind', 'category', 'size', 'is_active'];

    protected function casts(): array
    {
        return [
            'category' => ProductCategory::class,
            'is_active' => 'boolean',
        ];
    }

    public function salon(): BelongsTo
    {
        return $this->belongsTo(Salon::class);
    }

    /** „L'Oréal Professionnel Majirel“ – jak se přípravek zapíše k návštěvě. */
    public function fullName(): string
    {
        return trim($this->brand.' '.$this->name);
    }

    /** „Majirel · permanentní barva · 50 ml“ */
    public function meta(): string
    {
        return collect([$this->line !== $this->name ? $this->line : null, $this->kind, $this->size])->filter()->join(' · ');
    }
}
