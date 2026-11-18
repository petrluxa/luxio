<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Salon;
use App\Models\Service;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;

/**
 * Základ administrace. Všechno se vždy omezuje na salon přihlášené kadeřnice –
 * záznam z jiného salonu se tváří jako neexistující.
 */
abstract class AdminController extends Controller
{
    protected function user(): User
    {
        return auth()->user();
    }

    protected function salon(): Salon
    {
        return $this->user()->salon;
    }

    /** Nastavení salonu a správa kadeřnic patří jen správci salonu. */
    protected function ownerOnly(): void
    {
        abort_unless($this->user()->isOwner(), 403);
    }

    /**
     * Koho přihlášený v administraci vidí: správce salonu (majitelka, recepce) všechny
     * se sloupcem v rozvrhu, kadeřnice jen sebe – kolegyně, jejich rozvrh ani zákaznice nevidí.
     *
     * @return Collection<int, User>
     */
    protected function staffList(): Collection
    {
        $staff = $this->salon()->staff()->get();
        $me = $this->user();

        return $me->isOwner() ? $staff : $staff->where('id', $me->id)->values();
    }

    /**
     * Člen týmu se sloupcem v rozvrhu podle čísla – jen z těch, které přihlášený vidí
     * (kam smí rezervaci přesunout nebo přetáhnout).
     */
    protected function visibleStaff(int $id): ?User
    {
        $me = $this->user();

        if (! $me->isOwner() && $id !== $me->id) {
            return null;
        }

        return $this->salon()->staff()->whereKey($id)->first();
    }

    /** Na koho je omezený průvodce rezervací: kadeřnice zapisuje jen k sobě, správce ke komukoliv. */
    protected function staffScope(): ?int
    {
        return $this->user()->isOwner() ? null : $this->user()->id;
    }

    /**
     * Kadeřnice, jejíž kalendář se zobrazuje: z adresy (?kadernice=), jinak
     * přihlášená, jinak první v salonu.
     */
    protected function currentStaff(Request $request): User
    {
        $staff = $this->staffList();

        return $staff->firstWhere('id', $request->integer('kadernice'))
            ?? $staff->firstWhere('id', $this->user()->id)
            ?? $staff->first()
            ?? $this->user();
    }

    /** Rezervace salonu; kadeřnici se cizí rezervace (u kolegyně) tváří jako neexistující. */
    protected function ownBooking(Booking $booking): Booking
    {
        $me = $this->user();

        abort_unless($booking->salon_id === $me->salon_id && ($me->isOwner() || $booking->staff_id === $me->id), 404);

        return $booking;
    }

    /** Zákaznice salonu; kadeřnice vidí jen své (viz Customer::scopeVisibleTo). */
    protected function ownCustomer(Customer $customer): Customer
    {
        abort_unless(Customer::query()->visibleTo($this->user())->whereKey($customer->id)->exists(), 404);

        return $customer;
    }

    protected function ownService(Service $service): Service
    {
        abort_unless($service->salon_id === $this->user()->salon_id, 404);

        return $service;
    }

    protected function ownProduct(Product $product): Product
    {
        abort_unless($product->salon_id === $this->user()->salon_id, 404);

        return $product;
    }

    protected function ownStaff(User $staff): User
    {
        abort_unless($staff->salon_id === $this->user()->salon_id, 404);

        return $staff;
    }

    /**
     * Čí pracovní dobu a volno smí přihlášený upravovat: správce komukoliv
     * v salonu, kadeřnice jen sobě.
     */
    protected function editableStaff(Request $request): User
    {
        $me = $this->user();
        $id = $request->integer('kadernice');

        if (! $me->isOwner() || $id === $me->id) {
            return $me;
        }

        if ($id) {
            return $this->ownStaff(User::findOrFail($id));
        }

        // Správce bez vlastních zákaznic nemá co nastavovat sobě – začne první kadeřnicí.
        return $me->is_bookable ? $me : ($this->staffList()->first() ?? $me);
    }
}
