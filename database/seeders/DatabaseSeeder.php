<?php

namespace Database\Seeders;

use App\Enums\BookingSource;
use App\Enums\BookingStatus;
use App\Enums\UserRole;
use App\Models\Customer;
use App\Models\CustomerAccessToken;
use App\Models\Salon;
use App\Models\Service;
use App\Models\User;
use App\Services\Availability\SlotFinder;
use App\Services\Booking\BookingManager;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Event;

/**
 * Ukázková data pro vývoj: 1 salon, 1 kadeřnice, 5 služeb, pracovní doba,
 * 20 zákaznic a rezervace za poslední měsíc i na další tři týdny.
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // Ukázkový účet má veřejně známé heslo – na ostrý server nepatří.
        // Tam se salon zakládá příkazem `php artisan luxio:install`.
        if (app()->isProduction()) {
            $this->command?->error('Ukázková data jsou jen pro vývoj. Na ostrém serveru použijte: php artisan luxio:install');

            return;
        }

        // Ukázkové rezervace nemají nikomu posílat notifikace.
        Event::fakeFor(function () {
            $salon = Salon::create([
                'name' => 'Kadeřnictví Petra',
                'slug' => 'petra',
                'phone' => '+420603123456',
                'address' => 'Pražská 12, Liberec',
                'timezone' => 'Europe/Prague',
            ]);

            $petra = User::create([
                'salon_id' => $salon->id,
                'name' => 'Petra',
                'email' => 'petra@example.com',
                'password' => 'heslo123',
                'role' => UserRole::Owner,
                'color' => '#74624F',
                'is_bookable' => true,
            ]);

            // Druhá kadeřnice: vlastní pracovní doba, nedělá melír.
            $lucie = User::create([
                'salon_id' => $salon->id,
                'name' => 'Lucie',
                'email' => 'lucie@example.com',
                'password' => 'heslo123',
                'role' => UserRole::Staff,
                'color' => '#A8875E',
                'is_bookable' => true,
            ]);

            $services = $this->services($salon, $petra);
            $lucie->services()->attach($services->reject(fn (Service $service) => $service->name === 'Melír a střih')->pluck('id')->all());

            $this->workingHours($petra, [
                1 => [['09:00', '12:00'], ['13:00', '17:00']],
                2 => [['09:00', '12:00'], ['13:00', '17:00']],
                3 => [['09:00', '12:00'], ['13:00', '17:00']],
                4 => [['12:00', '19:00']],
                5 => [['09:00', '12:00'], ['13:00', '16:00']],
            ]);
            $this->workingHours($lucie, [
                1 => [['10:00', '13:00'], ['14:00', '18:00']],
                2 => [['10:00', '13:00'], ['14:00', '18:00']],
                4 => [['08:00', '12:00'], ['13:00', '16:00']],
                5 => [['08:00', '14:00']],
                6 => [['09:00', '12:00']],
            ]);

            $customers = Customer::factory()->count(20)->create(['salon_id' => $salon->id]);

            $this->bookings($salon, $petra, $services, $customers, perDay: [2, 4]);
            $this->bookings($salon, $lucie, $lucie->services()->orderBy('sort')->get(), $customers, perDay: [1, 3]);

            $demo = $customers->first();
            $demo->update(['name' => 'Jana Nováková', 'color_recipe' => 'Wella Koleston 7/1 + 6 % oxidant, 40 min']);
            $token = CustomerAccessToken::issue($demo);

            $this->command?->newLine();
            $this->command?->info('Ukázková data jsou připravena.');
            $this->command?->line('  Administrace:  '.url('/admin').'  (petra@example.com / heslo123, druhá kadeřnice lucie@example.com / heslo123)');
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
                $picked = random_int(1, 5) === 1
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
}
