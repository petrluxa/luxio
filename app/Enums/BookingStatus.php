<?php

namespace App\Enums;

enum BookingStatus: string
{
    case Pending = 'pending';
    case Confirmed = 'confirmed';
    case CancelledByCustomer = 'cancelled_by_customer';
    case CancelledByStaff = 'cancelled_by_staff';
    case Completed = 'completed';
    case NoShow = 'no_show';

    /** Stavy, ve kterých rezervace drží místo v kalendáři. */
    public static function occupying(): array
    {
        return [self::Pending, self::Confirmed, self::Completed, self::NoShow];
    }

    /** Stavy, které jsou ještě „živé“ (dají se zrušit, přesunout, schválit). */
    public static function active(): array
    {
        return [self::Pending, self::Confirmed];
    }

    public function isCancelled(): bool
    {
        return $this === self::CancelledByCustomer || $this === self::CancelledByStaff;
    }

    public function isActive(): bool
    {
        return in_array($this, self::active(), true);
    }

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Čeká na schválení',
            self::Confirmed => 'Potvrzeno',
            self::CancelledByCustomer => 'Zrušeno zákaznicí',
            self::CancelledByStaff => 'Zrušeno salonem',
            self::Completed => 'Proběhlo',
            self::NoShow => 'Nepřišla',
        };
    }
}
