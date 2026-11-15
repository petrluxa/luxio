<?php

use App\Enums\BookingStatus;
use App\Mail\AccessLinkMail;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\CustomerAccessToken;
use App\Support\CustomerCookie;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;

beforeEach(function () {
    $this->travelTo(TEST_NOW);
    Mail::fake();
    [$this->salon, $this->staff] = salonWithStaff();
    $this->cut = service($this->salon);
});

function firstBooking($test, array $overrides = []): array
{
    return $overrides + [
        'services' => [$test->cut->id],
        'date' => TEST_DAY,
        'time' => '10:00',
        'name' => 'Jana Nováková',
        'phone' => '603 123 456',
        'email' => 'Jana@Example.com',
        'consent' => '1',
    ];
}

test('veřejný odkaz salonu ukáže průvodce s kontaktními údaji', function () {
    $this->get('/s/'.$this->salon->slug)
        ->assertOk()
        ->assertSee($this->salon->name)
        ->assertViewHas('wizard', fn (array $wizard) => $wizard['services'][0]['name'] === 'Dámský střih')
        ->assertSee('Vaše údaje')
        ->assertSee('Souhlasím se zpracováním');
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
        ->email->toBe('jana@example.com')
        ->is_verified->toBeFalse()
        ->consent_at->not->toBeNull()
        ->and($booking->status)->toBe(BookingStatus::Pending)
        ->and($booking->customer_id)->toBe($jana->id)
        ->and(CustomerAccessToken::count())->toBe(1);

    // Přesměrování na osobní odkaz (onboarding) + dlouhodobá cookie.
    $location = $response->headers->get('Location');
    preg_match('#/k/([A-Za-z0-9]{40})/aplikace#', $location, $m);

    expect($m)->not->toBeEmpty()
        ->and(CustomerAccessToken::findValid($m[1])?->customer_id)->toBe($jana->id);

    $response->assertCookie(CustomerCookie::NAME, $m[1]);

    $this->get($location)->assertOk()->assertSee('Rezervace je odeslaná')->assertSee('Uložte si aplikaci na plochu');

    // Jeden e-mail s osobním odkazem, aby ho zákaznice neztratila.
    Mail::assertQueued(AccessLinkMail::class, fn (AccessLinkMail $mail) => $mail->hasTo('jana@example.com')
        && $mail->reason === 'welcome'
        && str_contains($mail->url, $m[1]));
    Mail::assertQueuedCount(1);
});

test('salon může schvalování nových zákaznic vypnout', function () {
    $this->salon->update(['settings' => ['approve_new_customers' => false]]);

    $this->post('/s/'.$this->salon->slug, firstBooking($this));

    expect(Booking::sole()->status)->toBe(BookingStatus::Confirmed);
});

describe('známý e-mail nebo telefon (ochrana proti převzetí cizího účtu)', function () {
    beforeEach(function () {
        $this->known = customer($this->salon, ['name' => 'Jana Nováková', 'email' => 'jana@example.com', 'phone' => '+420603123456']);
    });

    test('nová zákaznice se nezaloží, rezervace čeká a prohlížeč nedostane token', function () {
        $response = $this->post('/s/'.$this->salon->slug, firstBooking($this));

        $response->assertRedirect("/s/{$this->salon->slug}/odeslano")->assertCookieMissing(CustomerCookie::NAME);

        expect(Customer::count())->toBe(1)
            ->and(Booking::sole()->customer_id)->toBe($this->known->id)
            ->and(Booking::sole()->status)->toBe(BookingStatus::Pending)   // i když je zákaznice ověřená
            ->and(CustomerAccessToken::count())->toBe(0);

        $this->get("/s/{$this->salon->slug}/odeslano")->assertOk()->assertSee('Čeká na potvrzení');
    });

    test('odkaz odejde na uložený e-mail, ne na ten z formuláře', function () {
        $this->post('/s/'.$this->salon->slug, firstBooking($this, ['email' => 'utocnik@example.com']));

        Mail::assertQueued(AccessLinkMail::class, fn (AccessLinkMail $mail) => $mail->hasTo('jana@example.com') && $mail->reason === 'existing');
        Mail::assertNotQueued(AccessLinkMail::class, fn (AccessLinkMail $mail) => $mail->hasTo('utocnik@example.com'));

        expect(Customer::count())->toBe(1);
    });

    test('zablokovaná zákaznice rezervaci nevytvoří, odpověď je ale stejná', function () {
        $this->known->update(['is_blocked' => true]);

        $this->post('/s/'.$this->salon->slug, firstBooking($this))->assertRedirect("/s/{$this->salon->slug}/odeslano");

        expect(Booking::count())->toBe(0);
        Mail::assertNothingQueued();
    });
});

test('všechny údaje i souhlas jsou povinné', function () {
    $this->post('/s/'.$this->salon->slug, firstBooking($this, ['name' => '', 'phone' => '123', 'email' => 'neplatny', 'consent' => null]))
        ->assertSessionHasErrors(['name', 'phone', 'email', 'consent']);

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
    test('na známý e-mail odejde dočasný odkaz, odpověď je vždy stejná', function () {
        $jana = customer($this->salon, ['email' => 'jana@example.com']);

        $this->post('/obnovit', ['email' => 'JANA@example.com'])->assertSessionHas('status');
        $this->post('/obnovit', ['email' => 'nikdo@example.com'])->assertSessionHas('status');

        Mail::assertQueued(AccessLinkMail::class, fn (AccessLinkMail $mail) => $mail->hasTo('jana@example.com') && $mail->reason === 'recover' && $mail->validMinutes === 30);
        Mail::assertQueuedCount(1);
    });

    test('odkaz z e-mailu vydá nový token a starý nezneplatní', function () {
        $jana = customer($this->salon);
        $old = tokenFor($jana);
        $url = URL::temporarySignedRoute('recover.claim', now()->addMinutes(30), ['customer' => $jana->id]);

        // Samotné otevření odkazu ještě token nevydá (e-mailové programy odkazy načítají samy).
        $this->get($url)->assertOk()->assertSee('Pokračovat');
        expect(CustomerAccessToken::count())->toBe(1);

        $response = $this->post($url);
        preg_match('#/k/([A-Za-z0-9]{40})$#', $response->headers->get('Location'), $m);

        expect($m)->not->toBeEmpty()->and($m[1])->not->toBe($old);

        $this->get(k($m[1]))->assertOk();
        $this->get(k($old))->assertOk();
    });

    test('odkaz po 30 minutách ani s upraveným podpisem neplatí', function () {
        $jana = customer($this->salon);
        $url = URL::temporarySignedRoute('recover.claim', now()->addMinutes(30), ['customer' => $jana->id]);

        $this->get($url.'x')->assertForbidden();
        $this->post(str_replace("/obnovit/{$jana->id}", '/obnovit/'.customer($this->salon)->id, $url))->assertForbidden();

        $this->travel(31)->minutes();

        $this->get($url)->assertForbidden();
        $this->post($url)->assertForbidden();
    });
});
