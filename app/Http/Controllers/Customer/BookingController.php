<?php

namespace App\Http\Controllers\Customer;

use App\Enums\BookingStatus;
use App\Exceptions\BookingActionException;
use App\Exceptions\SlotUnavailableException;
use App\Models\Booking;
use App\Services\Booking\BookingManager;
use App\Services\Booking\BookingWizard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class BookingController extends CustomerController
{
    public function __construct(
        private readonly BookingWizard $wizard,
        private readonly BookingManager $manager,
    ) {}

    /** Moje termíny – budoucí a historie. */
    public function index(Request $request): View
    {
        $customer = $this->customer($request);

        $upcoming = $customer->bookings()->upcoming()->with('items', 'staff')->orderBy('starts_at')->get();

        $history = $customer->bookings()->with('items', 'staff')
            ->whereNotIn('id', $upcoming->modelKeys())
            ->orderByDesc('starts_at')
            ->limit(50)
            ->get();

        return view('customer.bookings.index', compact('upcoming', 'history'));
    }

    public function show(Request $request, Booking $booking): View
    {
        $booking = $this->own($request, $booking)->load('items', 'staff', 'salon');

        return view('customer.bookings.show', ['booking' => $booking]);
    }

    /** Nová rezervace (nebo přesun existující přes ?presun=ID). */
    public function create(Request $request): View|RedirectResponse
    {
        $customer = $this->customer($request);
        $token = $this->accessToken($request);
        $replaces = $this->replaceable($request, $request->query('presun'));

        if ($request->filled('presun') && ! $replaces) {
            return redirect()->route('customer.bookings')->with('error', 'Tento termín už nejde přesunout online.');
        }

        $options = $this->wizard->options($customer->salon, $token->scope_staff_id);

        // Předvýběr: přesouvaný termín → jinak oblíbená / naposledy navštívená kadeřnice.
        $last = $replaces ?? $customer->bookings()->latest('starts_at')->first();
        $staffIds = array_column($options['staff'], 'id');
        $preferred = collect([$replaces?->staff_id, $customer->preferred_staff_id, $last?->staff_id])
            ->first(fn ($id) => $id && in_array($id, $staffIds, true));

        $replaces?->load('items');

        return view('customer.bookings.create', [
            'replaces' => $replaces,
            'next' => $customer->nextBooking(),
            'wizard' => $options + [
                'slotsUrl' => route('customer.booking.slots'),
                'replaces' => $replaces?->id,
                'selected' => [
                    'staffId' => $request->old('staff') !== null ? ((int) $request->old('staff') ?: null) : $preferred,
                    'serviceIds' => array_map('intval', (array) $request->old(
                        'services',
                        $replaces ? $replaces->items->pluck('service_id')->filter()->values()->all() : [],
                    )),
                    'date' => $request->old('date'),
                    'time' => $request->old('time'),
                ],
            ],
        ]);
    }

    public function slots(Request $request): JsonResponse
    {
        $customer = $this->customer($request);
        $replaces = $this->replaceable($request, $request->query('replaces'));

        return response()->json($this->wizard->slots(
            salon: $customer->salon,
            serviceIds: array_map('intval', (array) $request->query('services', [])),
            staffId: $this->accessToken($request)->scope_staff_id ?? ($request->integer('staff') ?: null),
            from: $request->query('from'),
            ignoreBookingId: $replaces?->id,
        ));
    }

    public function store(Request $request): RedirectResponse
    {
        $customer = $this->customer($request);
        $salon = $customer->salon;

        if ($customer->is_blocked) {
            return back()->with('error', 'Online rezervace teď pro vás není dostupná. Zavolejte prosím do salonu.');
        }

        $selection = $this->wizard->resolve($salon, $request->all(), $this->accessToken($request)->scope_staff_id);
        $replaces = $this->replaceable($request, $request->input('replaces'));

        if ($request->filled('replaces') && ! $replaces) {
            return redirect()->route('customer.bookings')->with('error', 'Tento termín už nejde přesunout online.');
        }

        // Nové zákaznice čekají na schválení; ověřené mají termín potvrzený hned.
        $needsApproval = $salon->rules()->approveNewCustomers && ! $customer->is_verified;

        try {
            $booking = $this->manager->create(
                customer: $customer,
                services: $selection->services,
                start: $selection->start,
                staff: $selection->staff,
                status: $needsApproval ? BookingStatus::Pending : BookingStatus::Confirmed,
                customerNote: $selection->note,
                replaces: $replaces,
            );
        } catch (SlotUnavailableException|BookingActionException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('customer.booking.show', $booking)->with('status', match (true) {
            $booking->status === BookingStatus::Pending => 'Rezervace je odeslaná a čeká na potvrzení kadeřnicí.',
            $replaces !== null => 'Termín je přesunutý.',
            default => 'Termín je rezervovaný.',
        });
    }

    public function cancel(Request $request, Booking $booking): RedirectResponse
    {
        $booking = $this->own($request, $booking);

        try {
            $this->manager->cancelByCustomer($booking);
        } catch (BookingActionException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('customer.bookings')->with('status', 'Termín je zrušený.');
    }

    /** Vlastní termín, který zákaznice ještě smí přesunout (stejná lhůta jako pro zrušení). */
    private function replaceable(Request $request, mixed $id): ?Booking
    {
        if (! is_numeric($id)) {
            return null;
        }

        $booking = $this->customer($request)->bookings()->whereKey((int) $id)->first();

        return $booking?->canBeCancelledByCustomer() ? $booking : null;
    }
}
