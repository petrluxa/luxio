<?php

namespace App\Services\Booking;

use App\Enums\BookingSource;
use App\Enums\BookingStatus;
use App\Events\BookingApproved;
use App\Events\BookingCancelled;
use App\Events\BookingCreated;
use App\Events\BookingMoved;
use App\Events\BookingRejected;
use App\Exceptions\BookingActionException;
use App\Exceptions\SlotUnavailableException;
use App\Models\Booking;
use App\Models\BookingItem;
use App\Models\Customer;
use App\Models\Service;
use App\Models\User;
use App\Services\Availability\EffectiveService;
use App\Services\Availability\ServicePlan;
use App\Services\Availability\SlotFinder;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Všechny změny rezervací na jednom místě: vytvoření, zrušení, přesun, schválení.
 * Každý zápis do kalendáře znovu ověří dostupnost v transakci se zámkem na řádcích
 * kadeřnic, aby při souběhu nevznikla dvojí rezervace (SPEC 4.4).
 */
final class BookingManager
{
    public function __construct(private readonly SlotFinder $slots) {}

    /**
     * Vytvoří rezervaci. `$staff = null` znamená „Kdokoliv“ – přiřadí se kadeřnice
     * s nejméně rezervacemi daný den.
     *
     * @param  iterable<Service>  $services  v pořadí, v jakém půjdou za sebou
     * @param  Booking|null  $replaces  původní termín při přeplánování zákaznicí (zruší se v témže kroku)
     * @param  bool  $allowOverlap  kadeřnice si zákaznici zapisuje sama i na obsazený čas nebo mimo pracovní
     *                              dobu (dělá dvě najednou); vyžaduje konkrétní kadeřnici
     *
     * @throws SlotUnavailableException
     */
    public function create(
        Customer $customer,
        iterable $services,
        CarbonImmutable $start,
        ?User $staff = null,
        BookingStatus $status = BookingStatus::Confirmed,
        BookingSource $source = BookingSource::Online,
        ?string $customerNote = null,
        ?string $staffNote = null,
        bool $ignoreLimits = false,
        ?Booking $replaces = null,
        bool $allowOverlap = false,
    ): Booking {
        $services = collect($services)->values();
        $salon = $customer->salon;

        if ($replaces && ! $replaces->status->isActive()) {
            throw new BookingActionException('Tento termín už nejde přesunout.');
        }

        $booking = DB::transaction(function () use (
            $customer, $salon, $services, $start, $staff, $status, $source, $customerNote, $staffNote, $ignoreLimits, $replaces, $allowOverlap
        ) {
            $this->lockStaff($this->slots->eligibleStaff($salon, $services->pluck('id'), $staff)->modelKeys());

            $assigned = match (true) {
                $staff && $allowOverlap => $staff,
                (bool) $staff => $this->slots->isFree($salon, $staff, $services, $start, $ignoreLimits, $replaces?->id) ? $staff : null,
                default => $this->slots->pickStaff($salon, $services, $start, $ignoreLimits, $replaces?->id),
            };

            if (! $assigned) {
                throw new SlotUnavailableException;
            }

            if ($replaces) {
                $this->markCancelled($replaces, BookingStatus::CancelledByCustomer, 'Přesunuto na jiný termín');
            }

            $plan = ServicePlan::for($services, $assigned->loadMissing('services'));

            $booking = Booking::create([
                'salon_id' => $salon->id,
                'staff_id' => $assigned->id,
                'customer_id' => $customer->id,
                'starts_at' => $start,
                'ends_at' => $plan->endsAt($start),
                'status' => $status,
                'total_price' => $plan->totalPrice(),
                'customer_note' => $customerNote,
                'staff_note' => $staffNote,
                'source' => $source,
            ]);

            foreach ($plan->services as $sequence => $service) {
                $booking->items()->create([
                    'service_id' => $service->serviceId,
                    'name' => $service->name,
                    'price' => $service->priceFrom,
                    'price_to' => $service->priceTo,
                    'work_1' => $service->work1,
                    'pause' => $service->pause,
                    'work_2' => $service->work2,
                    'buffer_after' => $service->bufferAfter,
                    'sequence' => $sequence,
                ]);
            }

            $this->writeSegments($booking, $plan);

            return $booking;
        });

        if ($replaces) {
            BookingMoved::dispatch($booking, $replaces->starts_at, true);
        } else {
            BookingCreated::dispatch($booking);
        }

        return $booking;
    }

    /**
     * Zrušení zákaznicí – jen do lhůty z nastavení salonu.
     */
    public function cancelByCustomer(Booking $booking): void
    {
        if (! $booking->canBeCancelledByCustomer()) {
            throw new BookingActionException('Tento termín už nejde zrušit online. Zavolejte prosím do salonu.');
        }

        DB::transaction(fn () => $this->markCancelled($booking, BookingStatus::CancelledByCustomer));

        BookingCancelled::dispatch($booking, true);
    }

    /**
     * Zrušení kadeřnicí (s důvodem, který uvidí zákaznice).
     */
    public function cancelByStaff(Booking $booking, ?string $reason = null): void
    {
        if (! $booking->status->isActive()) {
            throw new BookingActionException('Tuto rezervaci už nelze zrušit.');
        }

        DB::transaction(fn () => $this->markCancelled($booking, BookingStatus::CancelledByStaff, $reason));

        BookingCancelled::dispatch($booking, false);
    }

    public function approve(Booking $booking): void
    {
        if ($booking->status !== BookingStatus::Pending) {
            throw new BookingActionException('Tato rezervace už nečeká na schválení.');
        }

        $booking->update(['status' => BookingStatus::Confirmed]);

        // Schválením první rezervace se zákaznice stává ověřenou – příště už nečeká.
        $booking->customer()->update(['is_verified' => true]);

        BookingApproved::dispatch($booking);
    }

    public function reject(Booking $booking, ?string $reason = null): void
    {
        if ($booking->status !== BookingStatus::Pending) {
            throw new BookingActionException('Tato rezervace už nečeká na schválení.');
        }

        DB::transaction(fn () => $this->markCancelled($booking, BookingStatus::CancelledByStaff, $reason));

        BookingRejected::dispatch($booking);
    }

    /**
     * Přesun termínu kadeřnicí – tatáž rezervace, nový čas (případně jiná kadeřnice).
     * Délky se berou ze snapshotu služeb, ne z aktuálního ceníku.
     *
     * @param  bool  $allowOverlap  přesunout i na obsazený čas nebo mimo pracovní dobu
     *
     * @throws SlotUnavailableException
     */
    public function move(Booking $booking, CarbonImmutable $start, ?User $staff = null, bool $allowOverlap = false): void
    {
        if (! $booking->status->isActive()) {
            throw new BookingActionException('Tuto rezervaci už nelze přesunout.');
        }

        $staff ??= $booking->staff;
        $previousStart = $booking->starts_at;
        $plan = $booking->plan();

        DB::transaction(function () use ($booking, $start, $staff, $plan, $allowOverlap) {
            $this->lockStaff(array_unique([$staff->id, $booking->staff_id]));

            if (! $allowOverlap && ! $this->slots->isFree($booking->salon, $staff, $plan, $start, true, $booking->id)) {
                throw new SlotUnavailableException('V tomto čase už má kadeřnice jinou rezervaci nebo nepracuje.');
            }

            $booking->update([
                'staff_id' => $staff->id,
                'starts_at' => $start,
                'ends_at' => $plan->endsAt($start),
                'reminder_sent_at' => $start->equalTo($booking->starts_at) ? $booking->reminder_sent_at : null,
                'second_reminder_sent_at' => $start->equalTo($booking->starts_at) ? $booking->second_reminder_sent_at : null,
            ]);

            $booking->setRelation('staff', $staff);
            $this->writeSegments($booking, $plan);
        });

        // Zákaznici se oznamuje změna času; samotná výměna kadeřnice ve stejný čas ne.
        if (! $previousStart->equalTo($start)) {
            BookingMoved::dispatch($booking, $previousStart, false);
        }
    }

    /**
     * Přidá k rezervaci další úkon – zákaznice si při návštěvě řekla o něco navíc. Délka a cena jsou
     * z ceníku kadeřnice, rezervace se o úkon prodlouží. Projde i přes další zákaznici nebo konec
     * pracovní doby (kadeřnice to má před sebou v rozvrhu); návratová hodnota na to upozorní.
     *
     * @return bool zasahuje teď rezervace do jiné nebo mimo pracovní dobu?
     */
    public function addService(Booking $booking, Service $service): bool
    {
        $this->assertItemsEditable($booking);

        $staff = $booking->staff->loadMissing('services');

        if ($service->salon_id !== $booking->salon_id || ! $service->is_active || ! $staff->services->contains('id', $service->id)) {
            throw new BookingActionException('Tento úkon '.$staff->name.' v ceníku nemá.');
        }

        return DB::transaction(function () use ($booking, $service, $staff) {
            $this->lockStaff([$booking->staff_id]);

            $effective = EffectiveService::for($service, $staff);

            $booking->items()->create([
                'service_id' => $effective->serviceId,
                'name' => $effective->name,
                'price' => $effective->priceFrom,
                'price_to' => $effective->priceTo,
                'work_1' => $effective->work1,
                'pause' => $effective->pause,
                'work_2' => $effective->work2,
                'buffer_after' => $effective->bufferAfter,
                'sequence' => (int) $booking->items()->max('sequence') + 1,
            ]);

            return $this->replan($booking);
        });
    }

    /** Odebere úkon z rezervace (omyl, zákaznice si to rozmyslela). Poslední úkon odebrat nejde. */
    public function removeItem(Booking $booking, BookingItem $item): void
    {
        $this->assertItemsEditable($booking);

        if ($item->booking_id !== $booking->id) {
            throw new BookingActionException('Tento úkon k rezervaci nepatří.');
        }

        if ($booking->items()->count() <= 1) {
            throw new BookingActionException('Rezervace musí mít aspoň jeden úkon. Chcete-li ji celou zrušit, použijte Zrušit rezervaci.');
        }

        DB::transaction(function () use ($booking, $item) {
            $this->lockStaff([$booking->staff_id]);
            $item->delete();
            $this->replan($booking);
        });
    }

    private function assertItemsEditable(Booking $booking): void
    {
        if ($booking->status->isCancelled() || $booking->status === BookingStatus::NoShow) {
            throw new BookingActionException('U zrušené rezervace už úkony měnit nejde.');
        }

        if ($booking->isPaid()) {
            throw new BookingActionException('Návštěva je už zaplacená. Zrušte nejdřív zaplacení, potom úkony upravte.');
        }
    }

    /**
     * Přepočítá konec, cenu a úseky rezervace podle jejích úkonů (začátek zůstává).
     *
     * @return bool zasahuje teď rezervace do jiné nebo mimo pracovní dobu?
     */
    private function replan(Booking $booking): bool
    {
        $booking->load('items');
        $plan = $booking->plan();

        $booking->update([
            'ends_at' => $plan->endsAt($booking->starts_at),
            'total_price' => $plan->totalPrice(),
        ]);

        $this->writeSegments($booking, $plan);

        return ! $this->slots->isFree($booking->salon, $booking->staff, $plan, $booking->starts_at, true, $booking->id);
    }

    /**
     * Je daný začátek u kadeřnice volný (pro upozornění „děláte dvě najednou“)?
     *
     * @param  iterable<Service>|ServicePlan  $services
     */
    public function isFree(User $staff, iterable|ServicePlan $services, CarbonImmutable $start, ?int $ignoreBookingId = null): bool
    {
        return $this->slots->isFree($staff->salon, $staff, $services, $start, true, $ignoreBookingId);
    }

    public function markNoShow(Booking $booking): void
    {
        $this->finish($booking, BookingStatus::NoShow);
    }

    public function markCompleted(Booking $booking): void
    {
        $this->finish($booking, BookingStatus::Completed);
    }

    private function finish(Booking $booking, BookingStatus $status): void
    {
        if ($booking->status->isCancelled()) {
            throw new BookingActionException('Zrušenou rezervaci nelze označit.');
        }

        $booking->update(['status' => $status]);
    }

    /** Zruší rezervaci a uvolní její místo v kalendáři. */
    private function markCancelled(Booking $booking, BookingStatus $status, ?string $reason = null): void
    {
        $booking->update([
            'status' => $status,
            'cancelled_at' => now(),
            'cancel_reason' => $reason ? mb_substr($reason, 0, 255) : null,
        ]);

        $booking->segments()->delete();
    }

    /** Přepíše úseky rezervace podle plánu od jejího aktuálního začátku. */
    private function writeSegments(Booking $booking, ServicePlan $plan): void
    {
        $booking->segments()->delete();

        foreach ($plan->segmentsAt($booking->starts_at) as $segment) {
            $booking->segments()->create([
                'staff_id' => $booking->staff_id,
                'starts_at' => $segment['range']->start,
                'ends_at' => $segment['range']->end,
                'type' => $segment['type'],
            ]);
        }
    }

    /**
     * Zámek na řádcích kadeřnic: souběžné zápisy do kalendáře téže kadeřnice jdou
     * postupně. Zamyká se vždy ve stejném pořadí (podle id), aby nevznikl deadlock.
     *
     * @param  array<int>  $staffIds
     */
    private function lockStaff(array $staffIds): void
    {
        if ($staffIds === []) {
            return;
        }

        sort($staffIds);

        User::whereKey($staffIds)->orderBy('id')->lockForUpdate()->get(['id']);
    }
}
