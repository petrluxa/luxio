<?php

namespace App\Enums;

/**
 * Události, o kterých se posílá upozornění (SPEC 6).
 */
enum BookingNotice: string
{
    // Zákaznici
    case Confirmed = 'confirmed';
    case Rejected = 'rejected';
    case Reminder = 'reminder';
    case CancelledByStaff = 'cancelled_by_staff';
    case MovedByStaff = 'moved_by_staff';

    // Kadeřnici
    case NewBooking = 'new_booking';
    case Pending = 'pending';
    case CancelledByCustomer = 'cancelled_by_customer';
    case MovedByCustomer = 'moved_by_customer';

    public function forCustomer(): bool
    {
        return in_array($this, [self::Confirmed, self::Rejected, self::Reminder, self::CancelledByStaff, self::MovedByStaff], true);
    }
}
