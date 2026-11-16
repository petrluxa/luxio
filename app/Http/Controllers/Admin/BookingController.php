<?php

namespace App\Http\Controllers\Admin;

use App\Enums\BookingSource;
use App\Enums\BookingStatus;
use App\Enums\PaymentMethod;
use App\Exceptions\BookingActionException;
use App\Exceptions\SlotUnavailableException;
use App\Models\Booking;
use App\Models\Customer;
use App\Services\Booking\BookingManager;
use App\Services\Booking\BookingWizard;
use App\Services\Booking\Checkout;
use App\Support\Cz;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Rezervace v administraci: ruční vytvoření (zákaznice volá), detail, poznámka,
 * přesun, předání jiné kadeřnici, zrušení s důvodem, schválení / zamítnutí, „nepřišla“
 * a vyúčtování (použité přípravky, částka, doklad).
 *
 * Kadeřnice i správce smějí zapsat zákaznici i na obsazený čas nebo mimo pracovní dobu
 * (kadeřnice dělá dvě najednou) – zákaznicím se takový čas nenabízí.
 */
class BookingController extends AdminController
{
    public function __construct(
        private readonly BookingWizard $wizard,
        private readonly BookingManager $manager,
        private readonly Checkout $checkout,
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
        $booking = $this->ownBooking($booking)->load('customer', 'items', 'staff', 'segments', 'salon', 'products');

        return view('admin.bookings.show', [
            'tab' => 'calendar',
            'booking' => $booking,
            // Komu jde rezervaci předat (ostatní kadeřnice v rozvrhu).
            'others' => $this->staffList()->reject(fn ($member) => $member->id === $booking->staff_id)->values(),
        ]);
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
                'anyTime' => true,
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
        ], ['customer_name.required_without' => 'Vyberte zákaznici, nebo zadejte jméno nové.']);

        // „Jiný čas“ v průvodci: zapsat i na obsazený čas nebo mimo pracovní dobu.
        $force = $request->boolean('force') && $selection->staff !== null;
        $overlaps = $force && ! $this->manager->isFree($selection->staff, $selection->services, $selection->start);

        try {
            $booking = DB::transaction(function () use ($salon, $data, $selection, $force) {
                $customer = ! empty($data['customer_id'])
                    ? $salon->customers()->findOrFail($data['customer_id'])
                    : Customer::create([
                        'salon_id' => $salon->id,
                        'name' => trim($data['customer_name']),
                        'phone' => $data['customer_phone'] ?? null,
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
                    allowOverlap: $force,
                );
            });
        } catch (SlotUnavailableException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('admin.bookings.show', $booking)
            ->with('status', 'Rezervace je vytvořená.'.($overlaps ? ' Pozor: je mimo volné termíny – kadeřnice má v tu dobu jinou zákaznici nebo nepracuje.' : ''));
    }

    public function note(Request $request, Booking $booking): RedirectResponse
    {
        $data = $request->validate(['staff_note' => ['nullable', 'string', 'max:2000']]);

        $this->ownBooking($booking)->update(['staff_note' => $data['staff_note'] ?? null]);

        return back()->with('status', 'Poznámka je uložená.');
    }

    public function approve(Booking $booking): RedirectResponse
    {
        return $this->act($booking, fn (Booking $b) => $this->manager->approve($b), 'Rezervace je potvrzená.', tellCustomer: true);
    }

    public function reject(Request $request, Booking $booking): RedirectResponse
    {
        $reason = $this->reason($request);

        return $this->act($booking, fn (Booking $b) => $this->manager->reject($b, $reason), 'Rezervace je zamítnutá.', tellCustomer: true);
    }

    public function cancel(Request $request, Booking $booking): RedirectResponse
    {
        $reason = $this->reason($request);

        return $this->act($booking, fn (Booking $b) => $this->manager->cancelByStaff($b, $reason), 'Rezervace je zrušená.', tellCustomer: true);
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
                'staffHeading' => $options['staffHeading'],
                'services' => [],
                'fixed' => true,
                'slotsUrl' => route('admin.bookings.slots', ['presun' => $booking->id]),
                'replaces' => null,
                'anyTime' => true,
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
            $this->manager->move($booking, CarbonImmutable::parse($data['date'].' '.$data['time']), $staff, allowOverlap: $request->boolean('force'));
        } catch (SlotUnavailableException|BookingActionException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('admin.bookings.show', $booking)->with('status', 'Termín je přesunutý.'.$this->customerHint($booking));
    }

    /**
     * Přetažení rezervace v rozvrhu: nový čas, případně jiná kadeřnice (nebo jiný den v týdnu).
     * Kdo táhne, vidí, kam rezervaci pouští – proto projde i obsazený čas nebo čas mimo pracovní
     * dobu a odpověď na to jen upozorní. Výsledek se po načtení rozvrhu ukáže nahoře.
     */
    public function drop(Request $request, Booking $booking): JsonResponse
    {
        $booking = $this->ownBooking($booking)->load('items', 'customer');

        $data = $request->validate([
            'staff' => ['required', 'integer'],
            'date' => ['required', 'date_format:Y-m-d'],
            'time' => ['required', 'regex:/^([01]?\d|2[0-3]):[0-5]\d$/'],
        ]);

        $staff = $this->salon()->staff()->whereKey($data['staff'])->first();

        if (! $staff) {
            return response()->json(['message' => 'Tato kadeřnice už není v rozvrhu.'], 422);
        }

        $start = CarbonImmutable::parse($data['date'].' '.$data['time']);
        $busy = ! $this->manager->isFree($staff, $booking->plan(), $start, $booking->id);
        $sameTime = $start->equalTo($booking->starts_at);

        try {
            $this->manager->move($booking, $start, $staff, allowOverlap: true);
        } catch (BookingActionException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $message = $booking->customer->name.': '.Cz::dateTime($start).', '.$staff->name.'.'
            .($busy ? ' Pozor: v tu dobu už má jinou zákaznici nebo nepracuje.' : '')
            .($sameTime ? ' Zákaznici o změně kadeřnice dejte vědět.' : $this->customerHint($booking));

        $request->session()->flash('status', $message);

        return response()->json(['ok' => true, 'message' => $message, 'overlap' => $busy]);
    }

    /** Předání rezervace jiné kadeřnici ve stejný čas (nemoc, záskok) – i když má plno. */
    public function reassign(Request $request, Booking $booking): RedirectResponse
    {
        $booking = $this->ownBooking($booking)->load('items');

        $staff = $this->salon()->staff()->whereKey($request->integer('kadernice'))->first()
            ?? throw ValidationException::withMessages(['kadernice' => 'Vybraná kadeřnice už nepřijímá rezervace.']);

        if ($staff->id === $booking->staff_id) {
            return back();
        }

        $busy = ! $this->manager->isFree($staff, $booking->plan(), $booking->starts_at, $booking->id);

        try {
            $this->manager->move($booking, $booking->starts_at, $staff, allowOverlap: true);
        } catch (BookingActionException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('status', 'Rezervaci teď má '.$staff->name.'.'
            .($busy ? ' Pozor: v tu dobu už má jinou zákaznici nebo nepracuje.' : '')
            .' Zákaznici o změně kadeřnice dejte vědět.');
    }

    /** Vyúčtování: použité přípravky, částka a způsob platby. */
    public function checkoutForm(Booking $booking): View|RedirectResponse
    {
        $booking = $this->ownBooking($booking)->load('customer', 'items', 'staff', 'products');

        if ($booking->status->isCancelled() || $booking->status === BookingStatus::NoShow) {
            return redirect()->route('admin.bookings.show', $booking)->with('error', 'Tuto návštěvu nelze vyúčtovat.');
        }

        // Co měla zákaznice minule – nejčastěji se použije totéž.
        $last = Booking::query()
            ->where('customer_id', $booking->customer_id)
            ->whereKeyNot($booking->id)
            ->where('starts_at', '<', $booking->starts_at)
            ->whereHas('products')
            ->with('products')
            ->orderByDesc('starts_at')
            ->first();

        return view('admin.bookings.checkout', ['tab' => 'calendar', 'booking' => $booking, 'last' => $last]);
    }

    public function checkout(Request $request, Booking $booking): RedirectResponse
    {
        $booking = $this->ownBooking($booking);

        $data = $request->validate([
            'products' => ['nullable', 'array', 'max:40'],
            'products.*.id' => ['nullable', 'integer'],
            'products.*.name' => ['nullable', 'string', 'max:255'],
            'products.*.note' => ['nullable', 'string', 'max:160'],
            'amount' => ['nullable', 'integer', 'min:0', 'max:1000000'],
            'method' => ['nullable', Rule::enum(PaymentMethod::class)],
        ], ['amount.integer' => 'Částku zadejte v celých korunách.']);

        // „Jen přípravky“ uloží záznam bez platby, i když je částka předvyplněná.
        $amount = $request->boolean('only_products') || ! isset($data['amount']) ? null : (int) $data['amount'];

        try {
            $this->checkout->save(
                $booking,
                array_values($data['products'] ?? []),
                $amount,
                isset($data['method']) ? PaymentMethod::from($data['method']) : null,
            );
        } catch (BookingActionException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('admin.bookings.show', $booking)->with('status', $amount === null
            ? 'Použité přípravky jsou uložené.'
            : 'Zaplaceno '.Cz::money($amount).'. Doklad č. '.$booking->receipt_number.' je připravený k odeslání.');
    }

    /** Zrušení zaplacení (omyl) – přípravky i číslo dokladu zůstávají. */
    public function uncheckout(Booking $booking): RedirectResponse
    {
        $this->checkout->revoke($this->ownBooking($booking));

        return back()->with('status', 'Zaplacení je zrušené.');
    }

    private function act(Booking $booking, Closure $action, string $message, bool $tellCustomer = false): RedirectResponse
    {
        try {
            $action($this->ownBooking($booking));
        } catch (BookingActionException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('status', $message.($tellCustomer ? $this->customerHint($booking) : ''));
    }

    /**
     * Dozví se zákaznice o změně sama? Upozornění chodí jen na telefon (žádné e-maily ani SMS),
     * takže bez zapnutých upozornění jí musí dát vědět kadeřnice.
     */
    private function customerHint(Booking $booking): string
    {
        return $booking->customer->reachableByPush()
            ? ' Zákaznici jde upozornění na telefon.'
            : ' Zákaznice nemá zapnutá upozornění – dejte jí prosím vědět sama.';
    }

    private function reason(Request $request): ?string
    {
        $reason = trim((string) ($request->validate(['reason' => ['nullable', 'string', 'max:255']])['reason'] ?? ''));

        return $reason !== '' ? $reason : null;
    }
}
