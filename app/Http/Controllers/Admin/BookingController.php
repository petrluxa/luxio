<?php

namespace App\Http\Controllers\Admin;

use App\Enums\BookingSource;
use App\Enums\BookingStatus;
use App\Exceptions\BookingActionException;
use App\Exceptions\SlotUnavailableException;
use App\Models\Booking;
use App\Models\Customer;
use App\Services\Booking\BookingManager;
use App\Services\Booking\BookingWizard;
use App\Support\Cz;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Rezervace v administraci: ruční vytvoření (zákaznice volá), detail, poznámka,
 * přesun, zrušení s důvodem, schválení / zamítnutí a „nepřišla“.
 */
class BookingController extends AdminController
{
    public function __construct(
        private readonly BookingWizard $wizard,
        private readonly BookingManager $manager,
    ) {}

    /** Ke schválení: čekající rezervace. */
    public function pending(): View
    {
        $bookings = Booking::query()
            ->where('salon_id', $this->salon()->id)
            ->where('status', BookingStatus::Pending)
            ->when(! $this->user()->isOwner(), fn ($q) => $q->where('staff_id', $this->user()->id))
            ->with('customer', 'items', 'staff')
            ->orderBy('starts_at')
            ->get();

        return view('admin.pending', ['tab' => 'pending', 'bookings' => $bookings]);
    }

    public function show(Booking $booking): View
    {
        $booking = $this->ownBooking($booking)->load('customer', 'items', 'staff', 'segments', 'salon');

        return view('admin.bookings.show', ['tab' => 'calendar', 'booking' => $booking]);
    }

    /** Nová rezervace ručně – z kalendáře přijde předvyplněné datum, čas a kadeřnice. */
    public function create(Request $request): View
    {
        $salon = $this->salon();
        $customer = $request->filled('zakaznice')
            ? $salon->customers()->find($request->integer('zakaznice'))
            : null;

        $options = $this->wizard->options($salon);
        $staffIds = array_column($options['staff'], 'id');
        $staffId = collect([(int) $request->old('staff'), $request->integer('kadernice'), $this->user()->id])
            ->first(fn ($id) => in_array($id, $staffIds, true));

        $date = $request->old('date', $request->query('datum'));
        $time = $request->old('time', $request->query('cas'));

        return view('admin.bookings.create', [
            'tab' => 'calendar',
            'preselected' => $customer,
            'wizard' => $options + [
                'slotsUrl' => route('admin.bookings.slots'),
                'replaces' => null,
                'selected' => [
                    'staffId' => $staffId,
                    'serviceIds' => array_map('intval', (array) $request->old('services', [])),
                    'date' => is_string($date) ? $date : null,
                    'time' => is_string($time) ? ltrim($time, '0') ?: null : null,
                ],
            ],
        ]);
    }

    public function slots(Request $request): JsonResponse
    {
        $moving = $request->filled('presun')
            ? $this->ownBooking(Booking::with('items')->findOrFail($request->integer('presun')))
            : null;

        return response()->json($this->wizard->slots(
            salon: $this->salon(),
            serviceIds: array_map('intval', (array) $request->query('services', [])),
            staffId: $request->integer('staff') ?: null,
            from: $request->query('from'),
            ignoreLimits: true,
            ignoreBookingId: $moving?->id,
            fixedPlan: $moving?->plan(),
        ));
    }

    public function store(Request $request): RedirectResponse
    {
        $salon = $this->salon();
        $selection = $this->wizard->resolve($salon, $request->all());

        $request->merge(['customer_phone' => Cz::normalizePhone($request->input('customer_phone'))]);

        $data = $request->validate([
            'customer_id' => ['nullable', 'integer'],
            'customer_name' => ['required_without:customer_id', 'nullable', 'string', 'min:2', 'max:100'],
            'customer_phone' => ['nullable', 'string'],
            'customer_email' => ['nullable', 'string', 'email', 'max:190'],
        ], ['customer_name.required_without' => 'Vyberte zákaznici, nebo zadejte jméno nové.']);

        try {
            $booking = DB::transaction(function () use ($salon, $data, $selection) {
                $customer = ! empty($data['customer_id'])
                    ? $salon->customers()->findOrFail($data['customer_id'])
                    : Customer::create([
                        'salon_id' => $salon->id,
                        'name' => trim($data['customer_name']),
                        'phone' => $data['customer_phone'] ?? null,
                        'email' => isset($data['customer_email']) ? mb_strtolower($data['customer_email']) : null,
                        'preferred_staff_id' => $selection->staff?->id,
                        'is_verified' => true, // přidala ji kadeřnice
                    ]);

                return $this->manager->create(
                    customer: $customer,
                    services: $selection->services,
                    start: $selection->start,
                    staff: $selection->staff,
                    status: BookingStatus::Confirmed,
                    source: BookingSource::Manual,
                    staffNote: $selection->note,
                    ignoreLimits: true,
                );
            });
        } catch (SlotUnavailableException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('admin.bookings.show', $booking)->with('status', 'Rezervace je vytvořená.');
    }

    public function note(Request $request, Booking $booking): RedirectResponse
    {
        $data = $request->validate(['staff_note' => ['nullable', 'string', 'max:2000']]);

        $this->ownBooking($booking)->update(['staff_note' => $data['staff_note'] ?? null]);

        return back()->with('status', 'Poznámka je uložená.');
    }

    public function approve(Booking $booking): RedirectResponse
    {
        return $this->act($booking, fn (Booking $b) => $this->manager->approve($b), 'Rezervace je potvrzená.');
    }

    public function reject(Request $request, Booking $booking): RedirectResponse
    {
        $reason = $this->reason($request);

        return $this->act($booking, fn (Booking $b) => $this->manager->reject($b, $reason), 'Rezervace je zamítnutá.');
    }

    public function cancel(Request $request, Booking $booking): RedirectResponse
    {
        $reason = $this->reason($request);

        return $this->act($booking, fn (Booking $b) => $this->manager->cancelByStaff($b, $reason), 'Rezervace je zrušená.');
    }

    public function noShow(Booking $booking): RedirectResponse
    {
        return $this->act($booking, fn (Booking $b) => $this->manager->markNoShow($b), 'Označeno: nepřišla.');
    }

    public function complete(Booking $booking): RedirectResponse
    {
        return $this->act($booking, fn (Booking $b) => $this->manager->markCompleted($b), 'Označeno: proběhlo.');
    }

    /** Přesun termínu: výběr nového dne a času (případně jiné kadeřnice). */
    public function moveForm(Booking $booking): View|RedirectResponse
    {
        $booking = $this->ownBooking($booking)->load('customer', 'items', 'staff');

        if (! $booking->status->isActive()) {
            return redirect()->route('admin.bookings.show', $booking)->with('error', 'Tuto rezervaci už nelze přesunout.');
        }

        $options = $this->wizard->options($this->salon());

        return view('admin.bookings.move', [
            'tab' => 'calendar',
            'booking' => $booking,
            'wizard' => [
                'staff' => $options['staff'],
                'services' => [],
                'fixed' => true,
                'slotsUrl' => route('admin.bookings.slots', ['presun' => $booking->id]),
                'replaces' => null,
                'selected' => [
                    'staffId' => $booking->staff_id,
                    'serviceIds' => [],
                    'date' => $booking->starts_at->toDateString(),
                    'time' => null,
                ],
            ],
        ]);
    }

    public function move(Request $request, Booking $booking): RedirectResponse
    {
        $booking = $this->ownBooking($booking);

        $data = $request->validate([
            'staff' => ['nullable', 'integer'],
            'date' => ['required', 'date_format:Y-m-d'],
            'time' => ['required', 'regex:/^([01]?\d|2[0-3]):[0-5]\d$/'],
        ], ['date.required' => 'Vyberte prosím den.', 'time.required' => 'Vyberte prosím čas.']);

        $staff = ! empty($data['staff'])
            ? ($this->salon()->staff()->whereKey($data['staff'])->first()
                ?? throw ValidationException::withMessages(['staff' => 'Vybraná kadeřnice už nepřijímá rezervace.']))
            : null;

        try {
            $this->manager->move($booking, CarbonImmutable::parse($data['date'].' '.$data['time']), $staff);
        } catch (SlotUnavailableException|BookingActionException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('admin.bookings.show', $booking)->with('status', 'Termín je přesunutý.');
    }

    private function act(Booking $booking, Closure $action, string $message): RedirectResponse
    {
        try {
            $action($this->ownBooking($booking));
        } catch (BookingActionException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('status', $message);
    }

    private function reason(Request $request): ?string
    {
        $reason = trim((string) ($request->validate(['reason' => ['nullable', 'string', 'max:255']])['reason'] ?? ''));

        return $reason !== '' ? $reason : null;
    }
}
