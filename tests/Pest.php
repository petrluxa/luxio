<?php

use App\Models\Customer;
use App\Models\CustomerAccessToken;
use App\Models\Salon;
use App\Models\Service;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Tests\TestCase;

pest()->extend(TestCase::class)->use(RefreshDatabase::class)->in('Feature');
pest()->extend(TestCase::class)->in('Unit');

/*
|--------------------------------------------------------------------------
| Pomocné funkce pro testy
|--------------------------------------------------------------------------
| Testy běží v „zamrzlém“ čase: pondělí 9. 11. 2026 v 8:00. Středa 11. 11.
| je běžný pracovní den, úterý 17. 11. je státní svátek.
*/

const TEST_NOW = '2026-11-09 08:00:00';
const TEST_DAY = '2026-11-11';

function at(string $time, string $date = TEST_DAY): CarbonImmutable
{
    return CarbonImmutable::parse("$date $time");
}

/**
 * Salon s jednou kadeřnicí, která pracuje po–pá 9–12 a 13–17.
 *
 * @return array{0: Salon, 1: User}
 */
function salonWithStaff(array $settings = []): array
{
    $salon = Salon::factory()->create(['settings' => $settings ?: null]);

    return [$salon, staffMember($salon)];
}

/**
 * @param  array<int, list<array{0: string, 1: string}>>|null  $hours  [den v týdnu => [[od, do], …]]
 */
function staffMember(Salon $salon, ?array $hours = null, array $attributes = []): User
{
    $staff = User::factory()->create(['salon_id' => $salon->id] + $attributes);

    $hours ??= array_fill_keys([1, 2, 3, 4, 5], [['09:00', '12:00'], ['13:00', '17:00']]);

    foreach ($hours as $weekday => $intervals) {
        foreach ($intervals as [$from, $to]) {
            $staff->workingHours()->create(['weekday' => $weekday, 'starts_at' => $from, 'ends_at' => $to]);
        }
    }

    return $staff;
}

/** Služba v ceníku salonu, kterou dělají všechny uvedené kadeřnice. */
function service(Salon $salon, array $attributes = [], User ...$staff): Service
{
    $service = Service::factory()->create(['salon_id' => $salon->id] + $attributes);

    foreach ($staff ?: $salon->users as $member) {
        $member->services()->attach($service->id);
    }

    return $service;
}

function coloring(Salon $salon, array $attributes = []): Service
{
    return service($salon, [
        'name' => 'Barvení a střih', 'price_from' => 1400, 'price_to' => 2200,
        'work_1' => 30, 'pause' => 40, 'work_2' => 60,
    ] + $attributes);
}

function customer(Salon $salon, array $attributes = []): Customer
{
    return Customer::factory()->create(['salon_id' => $salon->id] + $attributes);
}

/**
 * Časy slotů jako ['9:00', '9:15', …].
 *
 * @return list<string>
 */
function times(Collection $slots): array
{
    return $slots->map->time()->all();
}

/** Vydá zákaznici osobní token a vrátí jeho čitelnou podobu. */
function tokenFor(Customer $customer, ?int $scopeStaffId = null): string
{
    return CustomerAccessToken::issue($customer, $scopeStaffId);
}

/** Adresa v zákaznické části: k('/terminy') → /k/{token}/terminy */
function k(string $token, string $path = ''): string
{
    return '/k/'.$token.$path;
}
