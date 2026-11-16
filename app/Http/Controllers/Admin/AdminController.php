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
     * Kadeřnice salonu, ke kterým se lze objednat (pro přepínač v kalendáři).
     *
     * @return Collection<int, User>
     */
    protected function staffList(): Collection
    {
        return $this->salon()->staff()->get();
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

    protected function ownBooking(Booking $booking): Booking
    {
        abort_unless($booking->salon_id === $this->user()->salon_id, 404);

        return $booking;
    }

    protected function ownCustomer(Customer $customer): Customer
    {
        abort_unless($customer->salon_id === $this->user()->salon_id, 404);

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
