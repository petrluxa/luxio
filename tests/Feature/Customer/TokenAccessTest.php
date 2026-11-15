<?php

use App\Models\CustomerAccessToken;
use App\Services\Booking\BookingManager;
use App\Support\CustomerCookie;
use Illuminate\Support\Facades\DB;

beforeEach(fn () => $this->travelTo(TEST_NOW));

test('osobní odkaz otevře účet zákaznice bez hesla', function () {
    [$salon] = salonWithStaff();
    $jana = customer($salon, ['name' => 'Jana Nováková']);
    $token = tokenFor($jana);

    $this->get(k($token))
        ->assertOk()
        ->assertSee($salon->name)
        ->assertSee('Nová rezervace')
        ->assertCookie(CustomerCookie::NAME, $token);
});

test('token má 40 znaků a v databázi je jen jeho SHA-256 hash', function () {
    [$salon] = salonWithStaff();
    $token = tokenFor(customer($salon));

    $row = DB::table('customer_access_tokens')->first();

    expect($token)->toHaveLength(40)
        ->and($row->token_hash)->toBe(hash('sha256', $token))
        ->and(json_encode($row))->not->toContain($token);
});

test('neplatný token dostane obecnou chybu bez detailu', function () {
    [$salon] = salonWithStaff();
    tokenFor(customer($salon));

    $this->get(k(str_repeat('x', 40)))
        ->assertNotFound()
        ->assertSee('Odkaz neplatí')
        ->assertDontSee($salon->name);

    $this->get(k('kratky'))->assertNotFound()->assertSee('Odkaz neplatí');
});

test('zrušený token přestane fungovat, ostatní tokeny zákaznice platí dál', function () {
    [$salon] = salonWithStaff();
    $jana = customer($salon);
    $old = tokenFor($jana);
    $new = tokenFor($jana);

    CustomerAccessToken::findValid($old)->revoke();

    $this->get(k($old))->assertNotFound();
    $this->get(k($new))->assertOk();
});

test('cizí rezervaci zákaznice neuvidí ani nezruší', function () {
    [$salon, $staff] = salonWithStaff();
    $cut = service($salon);
    $jana = customer($salon);
    $eva = customer($salon);
    $booking = app(BookingManager::class)->create($eva, [$cut], at('10:00'), $staff);

    $token = tokenFor($jana);

    $this->get(k($token, "/terminy/{$booking->id}"))->assertNotFound();
    $this->post(k($token, "/terminy/{$booking->id}/zrusit"))->assertNotFound();

    expect($booking->fresh()->status->isActive())->toBeTrue();
});

test('na osobní odkazy platí limit 20 požadavků za minutu z jedné adresy', function () {
    [$salon] = salonWithStaff();
    $token = tokenFor(customer($salon));

    foreach (range(1, 20) as $i) {
        $this->get(k($token))->assertOk();
    }

    $this->get(k($token))->assertStatus(429);

    $this->travel(61)->seconds();

    $this->get(k($token))->assertOk();
});

test('hádání tokenů se po 20 neplatných pokusech zablokuje', function () {
    [$salon] = salonWithStaff();
    $valid = tokenFor(customer($salon));

    foreach (range(1, 20) as $i) {
        $this->get(k(str_pad((string) $i, 40, 'a')).'/rezervace/terminy')->assertNotFound();
    }

    // Další pokus už neprojde ani s platným tokenem – dokud limit nevyprší.
    $this->get(k($valid, '/rezervace/terminy'))->assertStatus(429);
});

test('odpovědi nesou hlavičku, která nepustí adresu s tokenem na cizí weby', function () {
    [$salon] = salonWithStaff();

    $this->get(k(tokenFor(customer($salon))))
        ->assertHeader('Referrer-Policy', 'no-referrer')
        ->assertHeader('X-Frame-Options', 'DENY');
});
