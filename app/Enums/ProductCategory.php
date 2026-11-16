<?php

namespace App\Enums;

enum ProductCategory: string
{
    case Colors = 'barvy';
    case Shampoos = 'sampony';
    case Care = 'pece';
    case Styling = 'styling';
    case Ampoules = 'ampule';
    case Other = 'ostatni';

    public function label(): string
    {
        return match ($this) {
            self::Colors => 'Barvy',
            self::Shampoos => 'Šampony',
            self::Care => 'Péče',
            self::Styling => 'Styling',
            self::Ampoules => 'Ampule',
            self::Other => 'Ostatní',
        };
    }
}
