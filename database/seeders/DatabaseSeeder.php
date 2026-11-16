<?php

namespace Database\Seeders;

use App\Enums\BookingSource;
use App\Enums\BookingStatus;
use App\Enums\PaymentMethod;
use App\Enums\ProductCategory;
use App\Enums\UserRole;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\CustomerAccessToken;
use App\Models\Product;
use App\Models\Salon;
use App\Models\Service;
use App\Models\User;
use App\Services\Availability\SlotFinder;
use App\Services\Booking\BookingManager;
use App\Services\Booking\Checkout;
use App\Services\Products\ProductCatalog;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Event;

/**
 * Ukázková data pro vývoj: 1 salon, správce, 3 kadeřnice a modelace nehtů, služby, pracovní doba,
 * katalog přípravků, 20 zákaznic a rezervace za poslední měsíc i na další tři týdny
 * (proběhlé návštěvy mají zapsané přípravky a doklad).
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // Ukázkové účty mají veřejně známé heslo – na ostrý server nepatří.
        // Tam se salon zakládá příkazem `php artisan luxio:install`.
        if (app()->isProduction()) {
            $this->command?->error('Ukázková data jsou jen pro vývoj. Na ostrém serveru použijte: php artisan luxio:install');

            return;
        }

        // Ukázkové rezervace nemají nikomu posílat notifikace.
        Event::fakeFor(function () {
            $salon = Salon::create([
                'name' => 'Kadeřnictví Luxio',
                'company' => 'Ukázkový salon s.r.o.',
                'ico' => '12345678',
                'slug' => 'luxio',
                'phone' => '+420603123456',
                'address' => 'Pražská 12, Liberec',
                'timezone' => 'Europe/Prague',
            ]);

            // Správce salonu (recepce): vidí rozvrh všech, sám zákaznice nemá.
            User::create([
                'salon_id' => $salon->id,
                'name' => 'Petr',
                'login' => 'admin',
                'password' => 'heslo123',
                'role' => UserRole::Owner,
                'color' => '#74624F',
                'is_bookable' => false,
            ]);

            $sabina = User::create([
                'salon_id' => $salon->id,
                'name' => 'Sabina',
                'login' => 'sabina',
                'password' => 'heslo123',
                'role' => UserRole::Staff,
                'color' => '#74624F',
                'is_bookable' => true,
            ]);

            // Druhá kadeřnice: vlastní pracovní doba (i sobota), nedělá melír.
            $sarka = User::create([
                'salon_id' => $salon->id,
                'name' => 'Šárka',
                'login' => 'sarka',
                'password' => 'heslo123',
                'role' => UserRole::Staff,
                'color' => '#A8875E',
                'is_bookable' => true,
            ]);

            // Třetí kadeřnice a modelace nehtů (vlastní sloupec v rozvrhu, vlastní služby).
            $tereza = User::create([
                'salon_id' => $salon->id,
                'name' => 'Tereza',
                'login' => 'tereza',
                'password' => 'heslo123',
                'role' => UserRole::Staff,
                'color' => '#5E7A6B',
                'is_bookable' => true,
            ]);

            $nails = User::create([
                'salon_id' => $salon->id,
                'name' => 'Modelace nehtů',
                'login' => 'nehty',
                'password' => 'heslo123',
                'role' => UserRole::Staff,
                'color' => '#8C5A5A',
                'is_bookable' => true,
            ]);

            $services = $this->services($salon, $sabina);
            $sarka->services()->attach($services->reject(fn (Service $service) => $service->name === 'Melír a střih')->pluck('id')->all());
            // Každá kadeřnice má svůj ceník: Tereza je u střihu dražší a rychlejší.
            $tereza->services()->attach($services->mapWithKeys(fn (Service $service) => [
                $service->id => $service->name === 'Dámský střih' ? ['price_from' => 650, 'work_1' => 40] : ['price_from' => null, 'work_1' => null],
            ])->all());
            $nailServices = $this->nailServices($salon, $nails, $services->count());

            $this->workingHours($sabina, [
                1 => [['09:00', '12:00'], ['13:00', '17:00']],
                2 => [['09:00', '12:00'], ['13:00', '17:00']],
                3 => [['09:00', '12:00'], ['13:00', '17:00']],
                4 => [['12:00', '19:00']],
                5 => [['09:00', '12:00'], ['13:00', '16:00']],
            ]);
            $this->workingHours($sarka, [
                1 => [['10:00', '13:00'], ['14:00', '18:00']],
                2 => [['10:00', '13:00'], ['14:00', '18:00']],
                4 => [['08:00', '12:00'], ['13:00', '16:00']],
                5 => [['08:00', '14:00']],
                6 => [['09:00', '12:00']],
            ]);
            $this->workingHours($tereza, [
                1 => [['08:00', '14:00']],
                3 => [['12:00', '19:00']],
                4 => [['09:00', '12:00'], ['13:00', '17:00']],
                5 => [['09:00', '12:00'], ['13:00', '17:00']],
            ]);
            $this->workingHours($nails, [
                1 => [['09:00', '12:00'], ['13:00', '17:00']],
                2 => [['09:00', '12:00'], ['13:00', '17:00']],
                3 => [['09:00', '12:00'], ['13:00', '17:00']],
                4 => [['13:00', '19:00']],
            ]);

            app(ProductCatalog::class)->import($salon);

            $customers = Customer::factory()->count(20)->create(['salon_id' => $salon->id]);

            $this->bookings($salon, $sabina, $services, $customers, perDay: [2, 4]);
            $this->bookings($salon, $sarka, $sarka->services()->orderBy('sort')->get(), $customers, perDay: [1, 3]);
            $this->bookings($salon, $tereza, $services, $customers, perDay: [1, 3]);
            $this->bookings($salon, $nails, $nailServices, $customers, perDay: [1, 2]);
            $this->doubleBooking($sabina, $services, $customers);
            $this->payments($salon);

            $demo = $customers->first();
            $demo->update(['name' => 'Jana Nováková', 'color_recipe' => 'Majirel 7.1 + 6 % oxidant, 40 min']);
            $token = CustomerAccessToken::issue($demo);

            $this->command?->newLine();
            $this->command?->info('Ukázková data jsou připravena.');
            $this->command?->line('  Administrace:  '.url('/admin').'  (správce admin, kadeřnice sabina, sarka, tereza a nehty – heslo všude heslo123)');
            $this->command?->line('  Zákaznice:     '.url('/k/'.$token).'  (Jana Nováková)');
            $this->command?->line('  Veřejný odkaz: '.url('/s/'.$salon->slug));
        });
    }

    /** @return Collection<int, Service> */
    private function services(Salon $salon, User $staff)
    {
        $rows = [
            ['Dámský střih', 'Mytí, střih a foukaná do tvaru.', 550, null, 45, 0, 0, 5],
            ['Barvení a střih', 'Barva na odrosty nebo celé délky, střih a styling.', 1400, 2200, 30, 40, 60, 10],
            ['Foukaná', 'Mytí a foukaná.', 350, null, 30, 0, 0, 5],
            ['Melír a střih', 'Melír do fólií, tónování, střih.', 1800, 2600, 60, 30, 60, 10],
            ['Pánský střih', 'Střih strojkem nebo nůžkami.', 350, null, 30, 0, 0, 5],
        ];

        return collect($rows)->map(function (array $row, int $i) use ($salon, $staff) {
            [$name, $description, $from, $to, $work1, $pause, $work2, $buffer] = $row;

            $service = Service::create([
                'salon_id' => $salon->id,
                'name' => $name,
                'description' => $description,
                'price_from' => $from,
                'price_to' => $to,
                'work_1' => $work1,
                'pause' => $pause,
                'work_2' => $work2,
                'buffer_after' => $buffer,
                'sort' => $i + 1,
            ]);

            $staff->services()->attach($service->id);

            return $service;
        });
    }

    /**
     * Služby, které dělá jen modelace nehtů.
     *
     * @return Collection<int, Service>
     */
    private function nailServices(Salon $salon, User $staff, int $after)
    {
        $rows = [
            ['Modelace nehtů – nová', 'Gelová modelace včetně úpravy nehtového lůžka.', 900, 105],
            ['Doplnění modelace', null, 650, 80],
            ['Gel lak', 'Manikúra a gel lak.', 450, 55],
            ['Manikúra', null, 350, 40],
        ];

        return collect($rows)->map(function (array $row, int $i) use ($salon, $staff, $after) {
            [$name, $description, $price, $minutes] = $row;

            $service = Service::create([
                'salon_id' => $salon->id,
                'name' => $name,
                'description' => $description,
                'price_from' => $price,
                'work_1' => $minutes,
                'buffer_after' => 5,
                'sort' => $after + $i + 1,
            ]);

            $staff->services()->attach($service->id);

            return $service;
        });
    }

    /** @param array<int, list<array{string, string}>> $week den v týdnu (1 = pondělí) → intervaly */
    private function workingHours(User $staff, array $week): void
    {
        foreach ($week as $weekday => $intervals) {
            foreach ($intervals as [$from, $to]) {
                $staff->workingHours()->create(['weekday' => $weekday, 'starts_at' => $from, 'ends_at' => $to]);
            }
        }
    }

    /** @param array{int, int} $perDay nejméně a nejvíc rezervací za den */
    private function bookings(Salon $salon, User $staff, $services, $customers, array $perDay): void
    {
        $finder = app(SlotFinder::class);
        $manager = app(BookingManager::class);
        $today = CarbonImmutable::today();

        for ($offset = -30; $offset <= 21; $offset++) {
            $day = $today->addDays($offset);

            foreach (range(1, random_int(...$perDay)) as $ignored) {
                $picked = random_int(1, 5) === 1 && $services->contains('name', 'Foukaná')
                    ? [$services->firstWhere('name', 'Dámský střih'), $services->firstWhere('name', 'Foukaná')]
                    : [$services->random()];

                $slots = $finder->forDay($salon, $picked, $day, $staff, ignoreLimits: true);

                if ($slots->isEmpty()) {
                    continue;
                }

                $start = $slots->random()->start;
                $isPast = $start->isPast();
                $pending = ! $isPast && random_int(1, 8) === 1;

                $booking = $manager->create(
                    customer: $customers->random(),
                    services: $picked,
                    start: $start,
                    staff: $staff,
                    status: $pending ? BookingStatus::Pending : BookingStatus::Confirmed,
                    source: random_int(1, 4) === 1 ? BookingSource::Manual : BookingSource::Online,
                    ignoreLimits: true,
                );

                if ($isPast) {
                    $booking->update(['status' => random_int(1, 15) === 1 ? BookingStatus::NoShow : BookingStatus::Completed]);
                }
            }
        }
    }

    /**
     * Ukázka „dvě zákaznice najednou“: k nejbližší rezervaci kadeřnice přibude druhá ve stejný čas,
     * jak si ji kadeřnice sama zapíše, když má plno.
     */
    private function doubleBooking(User $staff, $services, $customers): void
    {
        $first = Booking::query()
            ->where('staff_id', $staff->id)
            ->whereIn('status', BookingStatus::active())
            ->where('starts_at', '>=', CarbonImmutable::today())
            ->orderBy('starts_at')
            ->first();

        if (! $first) {
            return;
        }

        app(BookingManager::class)->create(
            customer: $customers->where('id', '!=', $first->customer_id)->random(),
            services: [$services->firstWhere('name', 'Pánský střih')],
            start: $first->starts_at,
            staff: $staff,
            source: BookingSource::Manual,
            staffNote: 'Vzala jsem ho mezi – střih strojkem.',
            ignoreLimits: true,
            allowOverlap: true,
        );
    }

    /** Proběhlé návštěvy: většina má zapsané použité přípravky, zaplaceno a doklad. */
    private function payments(Salon $salon): void
    {
        $checkout = app(Checkout::class);
        $products = $salon->products()->get()->groupBy(fn (Product $p) => $p->category->value);
        $pick = fn (ProductCategory $category, string $brandPart = '') => $products->get($category->value, collect())
            ->filter(fn (Product $p) => $brandPart === '' || str_contains($p->brand.' '.$p->name, $brandPart))
            ->whenEmpty(fn () => $products->get($category->value, collect()))
            ->random();

        if ($products->isEmpty()) {
            return;
        }

        $visits = Booking::query()
            ->where('salon_id', $salon->id)
            ->where('status', BookingStatus::Completed)
            ->with('items')
            ->orderBy('starts_at')
            ->get();

        foreach ($visits as $booking) {
            if (random_int(1, 6) === 1) {
                continue; // nevyúčtovaná návštěva
            }

            $colouring = $booking->items->contains(fn ($item) => $item->pause > 0);
            $rows = [];

            // Nehty: vlastní zápis mimo katalog vlasových přípravků.
            if ($booking->items->contains(fn ($item) => str_contains($item->name, 'nehtů') || in_array($item->name, ['Gel lak', 'Manikúra'], true))) {
                $checkout->save($booking, [['name' => 'Gel lak', 'note' => 'odstín '.random_int(1, 40)]], $booking->total_price, PaymentMethod::Cash);
                $booking->update(['paid_at' => $booking->ends_at]);

                continue;
            }

            if ($colouring) {
                $shade = random_int(5, 9).'.'.random_int(0, 4);
                $rows[] = ['id' => $pick(ProductCategory::Colors, 'Majirel')->id, 'note' => $shade.', '.(random_int(3, 6) * 10).' g'];
                $rows[] = ['id' => $pick(ProductCategory::Colors, 'Oxydant')->id, 'note' => (random_int(4, 9) * 10).' ml'];
            }

            $rows[] = ['id' => $pick(ProductCategory::Shampoos)->id, 'note' => ''];

            if (random_int(1, 2) === 1) {
                $rows[] = ['id' => $pick(ProductCategory::Care)->id, 'note' => ''];
            }

            if (random_int(1, 3) === 1) {
                $rows[] = ['id' => $pick(ProductCategory::Styling)->id, 'note' => ''];
            }

            $hasRange = $booking->items->contains(fn ($item) => $item->price_to !== null);
            $amount = $booking->total_price + ($hasRange ? random_int(0, 6) * 100 : 0);

            $checkout->save($booking, $rows, $amount, random_int(1, 3) === 1 ? PaymentMethod::Card : PaymentMethod::Cash);
            $booking->update(['paid_at' => $booking->ends_at]);
        }
    }
}
