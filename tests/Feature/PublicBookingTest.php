<?php

use App\Enums\BookingSource;
use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\CustomerAccessToken;
use App\Services\Security\PublicFormGuard;
use App\Support\CustomerCookie;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    $this->travelTo(TEST_NOW);
    Mail::fake();
    [$this->salon, $this->staff] = salonWithStaff();
    $this->cut = service($this->salon);
});

// Aplikace e-maily vůbec neposílá – ať se v těchto testech stane cokoliv.
afterEach(fn () => Mail::assertNothingOutgoing());

function firstBooking($test, array $overrides = []): array
{
    return $overrides + [
        'services' => [$test->cut->id],
        'date' => TEST_DAY,
        'time' => '10:00',
        'name' => 'Jana Nováková',
        'phone' => '603 123 456',
        'consent' => '1',
        PublicFormGuard::FIELD => Crypt::encryptString((string) now()->subSeconds(30)->getTimestamp()),
    ];
}

test('veřejný odkaz salonu ukáže průvodce s kontaktními údaji bez e-mailu', function () {
    $this->get('/s/'.$this->salon->slug)
        ->assertOk()
        ->assertSee($this->salon->name)
        ->assertViewHas('wizard', fn (array $wizard) => $wizard['services'][0]['name'] === 'Dámský střih')
        ->assertSee('Vaše údaje')
        ->assertSee('Souhlasím se zpracováním jména a telefonu')
        ->assertDontSee('name="email"', false);
});

test('nabídka termínů je dostupná i bez účtu', function () {
    $this->getJson("/s/{$this->salon->slug}/terminy?services[]={$this->cut->id}")
        ->assertOk()
        ->assertJsonPath('days.2.times.0', '9:00');
});

test('první rezervace založí zákaznici, token a čeká na schválení', function () {
    $response = $this->post('/s/'.$this->salon->slug, firstBooking($this));

    $jana = Customer::sole();
    $booking = Booking::sole();

    expect($jana)
        ->name->toBe('Jana Nováková')
        ->phone->toBe('+420603123456')
        ->is_verified->toBeFalse()
        ->consent_at->not->toBeNull()
        ->and($booking->status)->toBe(BookingStatus::Pending)
        ->and($booking->source)->toBe(BookingSource::Online)
        ->and($booking->customer_id)->toBe($jana->id)
        ->and(CustomerAccessToken::count())->toBe(1);

    // Přesměrování na osobní odkaz (onboarding) + dlouhodobá cookie.
    $location = $response->headers->get('Location');
    preg_match('#/k/([A-Za-z0-9]{40})/aplikace#', $location, $m);

    expect($m)->not->toBeEmpty()
        ->and(CustomerAccessToken::findValid($m[1])?->customer_id)->toBe($jana->id);

    $response->assertCookie(CustomerCookie::NAME, $m[1]);

    // Odkaz e-mailem neposíláme – zákaznice si ho uloží na plochu nebo zkopíruje.
    $this->get($location)->assertOk()
        ->assertSee('Rezervace je odeslaná')
        ->assertSee('Uložte si aplikaci na plochu')
        ->assertSee('Váš osobní odkaz')
        ->assertSee(k($m[1]));
});

test('salon může schvalování nových zákaznic vypnout', function () {
    $this->salon->update(['settings' => ['approve_new_customers' => false]]);

    $this->post('/s/'.$this->salon->slug, firstBooking($this));

    expect(Booking::sole()->status)->toBe(BookingStatus::Confirmed);
});

describe('známý telefon (ochrana proti převzetí cizího účtu)', function () {
    beforeEach(function () {
        $this->known = customer($this->salon, ['name' => 'Jana Nováková', 'phone' => '+420603123456']);
    });

    test('nová zákaznice se nezaloží, rezervace čeká a prohlížeč nedostane token', function () {
        $response = $this->post('/s/'.$this->salon->slug, firstBooking($this, ['name' => 'Někdo Jiný']));

        $response->assertRedirect("/s/{$this->salon->slug}/odeslano")->assertCookieMissing(CustomerCookie::NAME);

        expect(Customer::count())->toBe(1)
            ->and($this->known->fresh()->name)->toBe('Jana Nováková')         // jméno z formuláře kartu nepřepíše
            ->and(Booking::sole()->customer_id)->toBe($this->known->id)
            ->and(Booking::sole()->status)->toBe(BookingStatus::Pending)       // i když je zákaznice ověřená
            ->and(Booking::sole()->source)->toBe(BookingSource::SalonLink)
            ->and(CustomerAccessToken::count())->toBe(0);

        $this->get("/s/{$this->salon->slug}/odeslano")->assertOk()
            ->assertSee('Čeká na potvrzení')
            ->assertSee('pošle vám váš osobní odkaz');
    });

    test('kadeřnice u takové rezervace vidí, že má zákaznici poslat odkaz', function () {
        $this->post('/s/'.$this->salon->slug, firstBooking($this));

        $this->actingAs($this->staff)->get('/admin/ke-schvaleni')->assertOk()
            ->assertSee('Přišla přes veřejný odkaz salonu')
            ->assertSee('/admin/zakaznice/'.$this->known->id);

        $this->actingAs($this->staff)->get('/admin/rezervace/'.Booking::sole()->id)->assertOk()
            ->assertSee('zákaznice u sebe nemá osobní odkaz')
            ->assertSee('přes veřejný odkaz salonu');
    });

    test('zablokovaná zákaznice rezervaci nevytvoří, odpověď je ale stejná', function () {
        $this->known->update(['is_blocked' => true]);

        $this->post('/s/'.$this->salon->slug, firstBooking($this))->assertRedirect("/s/{$this->salon->slug}/odeslano");

        expect(Booking::count())->toBe(0);
    });
});

test('jméno, telefon i souhlas jsou povinné', function () {
    $this->post('/s/'.$this->salon->slug, firstBooking($this, ['name' => '', 'phone' => '123', 'consent' => null]))
        ->assertSessionHasErrors(['name', 'phone', 'consent']);

    expect(Customer::count())->toBe(0)->and(Booking::count())->toBe(0);
});

test('obsazený termín zákaznici nezaloží', function () {
    $this->post('/s/'.$this->salon->slug, firstBooking($this, ['time' => '12:15']))->assertSessionHas('error');

    expect(Customer::count())->toBe(0);
});

test('robot, který vyplní skryté pole, nic nevytvoří', function () {
    $this->post('/s/'.$this->salon->slug, firstBooking($this, ['website' => 'http://spam.example']))->assertRedirect();

    expect(Customer::count())->toBe(0);
});

test('stálá zákaznice s cookie jde z veřejného odkazu rovnou do svého účtu', function () {
    $token = tokenFor(customer($this->salon));

    $this->withCookie(CustomerCookie::NAME, $token)
        ->get('/s/'.$this->salon->slug)
        ->assertRedirect(k($token, '/rezervace'));

    $this->withCookie(CustomerCookie::NAME, $token)->get('/')->assertRedirect(k($token));
});

describe('ztracený odkaz', function () {
    test('stránka odkáže na kadeřnici a nic neposílá ani nevydává', function () {
        $this->salon->update(['phone' => '+420603123456']);
        customer($this->salon);

        $this->get('/obnovit?salon='.$this->salon->slug)->assertOk()
            ->assertSee('Nový odkaz do rezervací vám pošle vaše kadeřnice')
            ->assertSee('tel:+420603123456', false)
            ->assertDontSee('<form', false);

        // Bez salonu (nebo s neexistujícím) jen obecná rada, žádná chyba.
        $this->get('/obnovit')->assertOk()->assertDontSee('tel:', false);
        $this->get('/obnovit?salon=neexistuje')->assertOk()->assertDontSee('tel:', false);

        expect(CustomerAccessToken::count())->toBe(0);
    });

    test('kdo má odkaz ještě uložený v prohlížeči, dostane se rovnou do účtu', function () {
        $token = tokenFor(customer($this->salon));

        $this->withCookie(CustomerCookie::NAME, $token)->get('/obnovit')->assertRedirect(k($token));
    });

    test('formulář pro zaslání odkazu e-mailem už neexistuje', function () {
        $this->post('/obnovit', ['email' => 'jana@example.com'])->assertStatus(405);
        $this->get('/obnovit/1')->assertNotFound();
    });
});
