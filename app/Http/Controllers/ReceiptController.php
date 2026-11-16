<?php

namespace App\Http\Controllers;

use App\Models\Booking;
use Illuminate\View\View;

/**
 * Doklad o zaplacení: stránka, kterou kadeřnice pošle zákaznici (WhatsApp, Messenger).
 * Otevře ji jen ten, kdo má celý odkaz – kód v adrese se počítá z klíče aplikace
 * a bez něj se stránka tváří jako neexistující.
 */
class ReceiptController extends Controller
{
    public function __invoke(Booking $booking, string $code): View
    {
        abort_unless(
            $booking->isPaid() && $booking->receipt_number && hash_equals($booking->receiptCode(), $code),
            404,
        );

        return view('receipt', [
            'booking' => $booking->load('salon', 'customer', 'staff', 'items'),
            'salon' => $booking->salon,
        ]);
    }
}
