<?php

namespace App\Http\Controllers;

use App\Enums\BookingSource;
use App\Enums\BookingStatus;
use App\Exceptions\SlotUnavailableException;
use App\Models\Customer;
use App\Models\CustomerAccessToken;
use App\Models\Salon;
use App\Services\Booking\BookingManager;
use App\Services\Booking\BookingWizard;
use App\Services\Security\PublicFormGuard;
use App\Support\CustomerCookie;
use App\Support\Cz;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Veřejný odkaz salonu `/s/{salon}` – první rezervace nové zákaznice (SPEC 3.1).
 */
class PublicBookingController extends Controller
{
    public function __construct(
        private readonly BookingWizard $wizard,
        private readonly BookingManager $manager,
        private readonly PublicFormGuard $guard,
    ) {}

    public function create(Request $request, Salon $salon): View|RedirectResponse
    {
        // Odkaz salonu je hlavní stránka – rozvrh celého salonu, stejný pro každého. Zákaznici, kterou
        // si prohlížeč pamatuje, se jen neptá na jméno a telefon a rezervace jde rovnou do jejího účtu.
        $token = CustomerCookie::token($request);
        $access = $token ? CustomerAccessToken::findValid($token) : null;
        $known = $access && $access->customer->salon_id === $salon->id ? $access->customer : null;

        // Zablokovaná zákaznice a odkaz vázaný na jednu kadeřnici mají svá omezení – ta hlídá stránka účtu.
        if ($known && ($known->is_blocked || $access->scope_staff_id)) {
            return redirect()->route('customer.booking.create', ['token' => $token]);
        }

        $own = $known ? ['token' => $token] : null;
        $options = $this->wizard->options($salon);

        // Salon s víc lidmi začíná rozvrhem celého salonu (potřebuje šířku okna, ne úzký sloupec).
        $board = count($options['staff']) > 1;

        return view('public.create', [
            'salon' => $salon,
            'appTitle' => $salon->name,
            'formStamp' => $this->guard->stamp(),
            'wide' => $board,
            'known' => $known,
            'own' => $own,
            'wizard' => $options + [
                'slotsUrl' => $own ? route('customer.booking.slots', $own) : route('salon.slots', $salon),
                'boardUrl' => $board ? ($own ? route('customer.booking.board', $own) : route('salon.board', $salon)) : null,
                'monthUrl' => $own ? route('customer.booking.board.month', $own) : route('salon.board.month', $salon),
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

    /** Rozvrh: den celého salonu, nebo týden jedné kadeřnice (?kadernice=) – kdo má kdy volno, bez jmen zákaznic. */
    public function board(Request $request, Salon $salon): JsonResponse
    {
        return response()->json($this->wizard->board($salon, $request->query('den'), null, $request->integer('kadernice') ?: null));
    }

    /** Měsíc pro kalendář „Jiný den“: na které dny jde skočit a kdy salon (kadeřnice) pracuje. */
    public function month(Request $request, Salon $salon): JsonResponse
    {
        return response()->json($this->wizard->month($salon, $request->query('mesic'), null, $request->integer('kadernice') ?: null));
    }

    public function store(Request $request, Salon $salon): RedirectResponse
    {
        // Past na roboty: skryté pole, které člověk nevyplní.
        if ($request->filled('website')) {
            return redirect()->route('salon.sent', $salon);
        }

        $this->guard->check($request);

        $request->merge(['phone' => Cz::normalizePhone($request->input('phone'))]);

        $contact = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:100'],
            'phone' => ['required', 'string'],
            'consent' => ['accepted'],
        ], [
            'phone.required' => 'Zadejte prosím platné telefonní číslo.',
            'consent.accepted' => 'Bez souhlasu se zpracováním údajů rezervaci vytvořit nejde.',
        ]);

        $selection = $this->wizard->resolve($salon, $request->all());

        $existing = $salon->customers()->where('phone', $contact['phone'])->first();

        try {
            if ($existing) {
                $response = $this->bookForExisting($salon, $existing, $selection);
                $this->guard->hit($request);

                return $response;
            }

            [$booking, $token] = DB::transaction(function () use ($salon, $contact, $selection) {
                $customer = Customer::create([
                    'salon_id' => $salon->id,
                    'name' => trim($contact['name']),
                    'phone' => $contact['phone'],
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

        $this->guard->hit($request);

        return redirect()
            ->route('customer.onboarding', ['token' => $token, 'rezervace' => $booking->id])
            ->withCookie(CustomerCookie::make($token));
    }

    /**
     * Odhlášení na stránce salonu: prohlížeč zákaznici zapomene a stránka se ukáže jako komukoliv novému.
     * Účet ani termíny se nemění – zákaznice se k nim vrátí svým osobním odkazem.
     */
    public function forget(Salon $salon): RedirectResponse
    {
        return redirect()
            ->route('salon.show', $salon)
            ->with('status', 'Jste odhlášeni. Ke svým termínům se kdykoliv vrátíte přes svůj osobní odkaz.')
            ->withCookie(CustomerCookie::forget());
    }

    public function sent(Salon $salon): View
    {
        return view('public.sent', ['salon' => $salon, 'appTitle' => $salon->name]);
    }

    /**
     * Telefon už známe: nová zákaznice se nezakládá a prohlížeč nedostane žádný token
     * (ochrana proti převzetí cizího účtu). Rezervace jen čeká na schválení a kadeřnice
     * u ní vidí, že má zákaznici poslat její osobní odkaz.
     */
    private function bookForExisting(Salon $salon, Customer $existing, $selection): RedirectResponse
    {
        $waiting = $existing->bookings()->upcoming()->where('status', BookingStatus::Pending)->count();

        // Kdo zná cizí telefon, nesmí zákaznici zaplnit kalendář rezervacemi.
        if (! $existing->is_blocked && $waiting < 2) {
            $this->manager->create(
                customer: $existing,
                services: $selection->services,
                start: $selection->start,
                staff: $selection->staff,
                status: BookingStatus::Pending,
                source: BookingSource::SalonLink,
                customerNote: $selection->note,
            );
        }

        return redirect()->route('salon.sent', $salon);
    }
}
