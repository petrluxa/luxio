<?php

namespace App\Services\Booking;

use App\Enums\BookingStatus;
use App\Enums\PaymentMethod;
use App\Exceptions\BookingActionException;
use App\Models\Booking;
use App\Models\Product;
use App\Models\Salon;
use Illuminate\Support\Facades\DB;

/**
 * Vyúčtování návštěvy: co kadeřnice použila, kolik zákaznice zaplatila a číslo dokladu.
 *
 * Přípravky jdou zapsat i bez částky (jen záznam pro příště). S částkou vzniká doklad
 * s číslem `ROK-pořadí` – číslo rezervaci zůstává, i když se částka později opraví.
 */
final class Checkout
{
    /**
     * @param  list<array{id?: int|string|null, name?: ?string, note?: ?string}>  $products  z katalogu (id), nebo vlastní název
     * @param  int|null  $amount  zaplacená částka v Kč; null = uložit jen přípravky
     *
     * @throws BookingActionException
     */
    public function save(Booking $booking, array $products, ?int $amount, ?PaymentMethod $method = null): void
    {
        if ($booking->status->isCancelled() || $booking->status === BookingStatus::NoShow) {
            throw new BookingActionException('Zrušenou návštěvu ani tu, na kterou zákaznice nepřišla, nelze vyúčtovat.');
        }

        DB::transaction(function () use ($booking, $products, $amount, $method) {
            $this->writeProducts($booking, $products);

            if ($amount === null) {
                return;
            }

            $paidAt = $booking->paid_at ?? now();

            $booking->update([
                'paid_amount' => $amount,
                'payment_method' => $method ?? PaymentMethod::Cash,
                'paid_at' => $paidAt,
                'receipt_number' => $booking->receipt_number ?? $this->nextNumber($booking->salon_id, $paidAt->year),
                // Zaplacená návštěva, která už začala, je hotová.
                'status' => $booking->status->isActive() && $booking->starts_at->isPast() ? BookingStatus::Completed : $booking->status,
            ]);

            // Kdo přišel a zaplatil, je ověřená zákaznice – příště nečeká na schválení.
            $booking->customer()->update(['is_verified' => true]);
        });
    }

    /** Zruší zaplacení (omyl). Číslo dokladu rezervaci zůstává, aby se v řadě nepoužilo dvakrát. */
    public function revoke(Booking $booking): void
    {
        $booking->update(['paid_amount' => null, 'payment_method' => null, 'paid_at' => null]);
    }

    /**
     * @param  list<array{id?: int|string|null, name?: ?string, note?: ?string}>  $rows
     */
    private function writeProducts(Booking $booking, array $rows): void
    {
        $catalog = Product::query()
            ->where('salon_id', $booking->salon_id)
            ->whereKey(collect($rows)->pluck('id')->filter()->map(fn ($id) => (int) $id)->all())
            ->get()
            ->keyBy('id');

        $booking->products()->delete();

        $sequence = 0;

        foreach ($rows as $row) {
            $product = $catalog->get((int) ($row['id'] ?? 0));
            $name = $product ? $product->fullName() : trim((string) ($row['name'] ?? ''));

            if ($name === '') {
                continue;
            }

            $note = trim((string) ($row['note'] ?? ''));

            $booking->products()->create([
                'product_id' => $product?->id,
                'name' => mb_substr($name, 0, 255),
                'note' => $note !== '' ? mb_substr($note, 0, 160) : null,
                'sequence' => $sequence++,
            ]);
        }
    }

    /** Další číslo dokladu v roce: 2026-0001, 2026-0002… Řada je pro každý salon zvlášť. */
    private function nextNumber(int $salonId, int $year): string
    {
        // Zámek na řádku salonu: dvě kadeřnice účtující zároveň nedostanou stejné číslo.
        Salon::whereKey($salonId)->lockForUpdate()->first(['id']);

        $last = Booking::query()
            ->where('salon_id', $salonId)
            ->where('receipt_number', 'like', $year.'-%')
            ->orderByRaw('LENGTH(receipt_number) DESC')
            ->orderByDesc('receipt_number')
            ->value('receipt_number');

        return sprintf('%d-%04d', $year, $last ? ((int) substr($last, 5)) + 1 : 1);
    }
}
