<?php

namespace App\Enums;

enum BookingSource: string
{
    /** Zákaznice sama – přes svůj osobní odkaz, nebo nová přes veřejný odkaz salonu. */
    case Online = 'online';

    /** Kadeřnice ručně v administraci. */
    case Manual = 'manual';

    /**
     * Veřejný odkaz salonu s telefonem, který už v zákaznicích je. Prohlížeč nedostal
     * žádný osobní odkaz – ten zákaznici pošle kadeřnice, až si ověří, že je to ona.
     */
    case SalonLink = 'salon_link';

    public function label(): string
    {
        return match ($this) {
            self::Online => 'online',
            self::Manual => 'ručně v administraci',
            self::SalonLink => 'přes veřejný odkaz salonu',
        };
    }
}
