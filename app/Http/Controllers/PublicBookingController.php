<?php

namespace App\Http\Controllers;

use App\Enums\BookingStatus;
use App\Exceptions\SlotUnavailableException;
use App\Models\Customer;
use App\Models\CustomerAccessToken;
use App\Models\Salon;
use App\Services\Booking\BookingManager;
use App\Services\Booking\BookingWizard;
use App\Services\Customers\AccessLinks;
use App\Support\CustomerCookie;
use App\Support\Cz;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * Veřejný odkaz salonu `/s/{salon}` – první rezervace nové zákaznice (SPEC 3.1).
 */
class PublicBookingController extends Controller
{
    public function __construct(
        private readonly BookingWizard $wizard,
        private readonly BookingManager $manager,
        private readonly AccessLinks $links,
    ) {}

    public function create(Request $request, Salon $salon): View|RedirectResponse
    {
        // Stálá zákaznice tohoto salonu jde rovnou do svého účtu.
        $known = CustomerCookie::customer($request);

        if ($known && $known->salon_id === $salon->id) {
            return redirect()->route('customer.booking.create', ['token' => $request->cookie(CustomerCookie::NAME)]);
        }

        return view('public.create', [
            'salon' => $salon,
            'appTitle' => $salon->name,
            'wizard' => $this->wizard->options($salon) + [
                'slotsUrl' => route('salon.slots', $salon),
                'replaces' => null,
                'selected' => [
                    'staffId' => $request->old('staff') ? (int) $request->old('staff') : null,
                    'serviceIds' => array_map('intval', (array) $request->old('services', [])),
                    'date' => $request->old('date'),
                    'time' => $request->old('time'),
                ],
            ],
        ]);
    }

    public function slots(Request $request, Salon $salon): JsonResponse
    {
        return response()->json($this->wizard->slots(
            salon: $salon,
            serviceIds: array_map('intval', (array) $request->query('services', [])),
            staffId: $request->integer('staff') ?: null,
            from: $request->query('from'),
        ));
    }

    public function store(Request $request, Salon $salon): RedirectResponse
    {
        // Past na roboty: skryté pole, které člověk nevyplní.
        if ($request->filled('website')) {
            return redirect()->route('salon.sent', $salon);
        }

        $request->merge([
            'phone' => Cz::normalizePhone($request->input('phone')),
            'email' => Str::lower(trim((string) $request->input('email'))),
        ]);

        $contact = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:100'],
            'phone' => ['required', 'string'],
            'email' => ['required', 'string', 'email', 'max:190'],
            'consent' => ['accepted'],
        ], [
            'phone.required' => 'Zadejte prosím platné telefonní číslo.',
            'consent.accepted' => 'Bez souhlasu se zpracováním údajů rezervaci vytvořit nejde.',
        ]);

        $selection = $this->wizard->resolve($salon, $request->all());

        $existing = $salon->customers()
            ->where(fn ($q) => $q->where('email', $contact['email'])->orWhere('phone', $contact['phone']))
            ->first();

        try {
            if ($existing) {
                return $this->bookForExisting($salon, $existing, $selection);
            }

            [$booking, $token] = DB::transaction(function () use ($salon, $contact, $selection) {
                $customer = Customer::create([
                    'salon_id' => $salon->id,
                    'name' => trim($contact['name']),
                    'phone' => $contact['phone'],
                    'email' => $contact['email'],
                    'preferred_staff_id' => $selection->staff?->id,
                    'consent_at' => now(),
                ]);

                $booking = $this->manager->create(
                    customer: $customer,
                    services: $selection->services,
                    start: $selection->start,
                    staff: $selection->staff,
                    status: $salon->rules()->approveNewCustomers ? BookingStatus::Pending : BookingStatus::Confirmed,
                    customerNote: $selection->note,
                );

                return [$booking, CustomerAccessToken::issue($customer)];
            });
        } catch (SlotUnavailableException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        $this->links->sendWelcome($booking->customer, $token);

        return redirect()
            ->route('customer.onboarding', ['token' => $token, 'rezervace' => $booking->id])
            ->withCookie(CustomerCookie::make($token));
    }

    public function sent(Salon $salon): View
    {
        return view('public.sent', ['salon' => $salon, 'appTitle' => $salon->name]);
    }

    /**
     * E-mail nebo telefon už známe: nová zákaznice se nezakládá a prohlížeč nedostane
     * žádný token (ochrana proti převzetí cizího účtu). Rezervace jen čeká na schválení
     * a odkaz odejde na uložený e-mail.
     */
    private function bookForExisting(Salon $salon, Customer $existing, $selection): RedirectResponse
    {
        if (! $existing->is_blocked) {
            $this->manager->create(
                customer: $existing,
                services: $selection->services,
                start: $selection->start,
                staff: $selection->staff,
                status: BookingStatus::Pending,
                customerNote: $selection->note,
            );

            $this->links->sendClaim($existing, 'existing');
        }

        return redirect()->route('salon.sent', $salon);
    }
}
