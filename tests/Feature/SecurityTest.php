<?php

use App\Enums\BookingStatus;
use App\Enums\UserRole;
use App\Mail\AccessLinkMail;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\Salon;
use App\Models\User;
use App\Services\Security\PublicFormGuard;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    $this->travelTo(TEST_NOW);
    Mail::fake();
    [$this->salon, $this->staff] = salonWithStaff();
    $this->cut = service($this->salon);
});

function stamp(int $secondsAgo = 30): string
{
    return Crypt::encryptString((string) now()->subSeconds($secondsAgo)->getTimestamp());
}

function publicBooking($test, array $overrides = []): array
{
    return $overrides + [
        'services' => [$test->cut->id], 'date' => TEST_DAY, 'time' => '10:00',
        'name' => 'Jana Nováková', 'phone' => '603 123 456', 'email' => 'jana@example.com', 'consent' => '1',
        PublicFormGuard::FIELD => stamp(),
    ];
}

describe('veřejný formulář proti robotům', function () {
    test('bez časového razítka ze serveru formulář neprojde', function () {
        $this->post('/s/'.$this->salon->slug, publicBooking($this, [PublicFormGuard::FIELD => null]))->assertSessionHasErrors('form');
        $this->post('/s/'.$this->salon->slug, publicBooking($this, [PublicFormGuard::FIELD => 'podvrzene']))->assertSessionHasErrors('form');
        $this->post('/s/'.$this->salon->slug, publicBooking($this, [PublicFormGuard::FIELD => (string) now()->subMinute()->getTimestamp()]))->assertSessionHasErrors('form');

        expect(Customer::count())->toBe(0);
        Mail::assertNothingQueued();
    });

    test('příliš rychlé nebo příliš staré odeslání neprojde', function () {
        $this->post('/s/'.$this->salon->slug, publicBooking($this, [PublicFormGuard::FIELD => stamp(1)]))->assertSessionHasErrors('form');
        $this->post('/s/'.$this->salon->slug, publicBooking($this, [PublicFormGuard::FIELD => stamp(4 * 3600)]))->assertSessionHasErrors('form');

        expect(Customer::count())->toBe(0);
    });

    test('formulář na stránce razítko má', function () {
        $this->get('/s/'.$this->salon->slug)->assertOk()->assertSee('name="'.PublicFormGuard::FIELD.'"', false);
    });

    test('z jedné adresy projdou nejvýš 4 první rezervace za hodinu', function () {
        foreach (['9:00', '10:00', '11:00', '13:00'] as $i => $time) {
            $this->post('/s/'.$this->salon->slug, publicBooking($this, ['time' => $time, 'email' => "z{$i}@example.com", 'phone' => "60312345{$i}"]))
                ->assertSessionHasNoErrors();
        }

        $this->post('/s/'.$this->salon->slug, publicBooking($this, ['time' => '14:00', 'email' => 'z9@example.com', 'phone' => '603999999']))
            ->assertSessionHasErrors('form');

        expect(Customer::count())->toBe(4);

        $this->travel(61)->minutes();

        $this->post('/s/'.$this->salon->slug, publicBooking($this, ['time' => '14:00', 'email' => 'z9@example.com', 'phone' => '603999999']))
            ->assertSessionHasNoErrors();
    });

    test('poznámka s odkazem je odmítnuta', function () {
        $this->post('/s/'.$this->salon->slug, publicBooking($this, ['note' => 'Levné půjčky na www.spam.example']))
            ->assertSessionHasErrors('note');

        expect(Booking::count())->toBe(0);
    });

    test('cizí e-mail nejde zahltit: nejvýš 2 čekající rezervace a 1 e-mail za hodinu', function () {
        $jana = customer($this->salon, ['email' => 'jana@example.com', 'phone' => '+420603123456']);

        foreach (['9:00', '10:00', '11:00'] as $time) {
            $this->post('/s/'.$this->salon->slug, publicBooking($this, ['time' => $time]))->assertRedirect("/s/{$this->salon->slug}/odeslano");
        }

        expect($jana->bookings()->where('status', BookingStatus::Pending)->count())->toBe(2);
        Mail::assertQueuedCount(1);
    });
});

test('obnova odkazu pošle na jednu zákaznici nejvýš 3 e-maily za hodinu', function () {
    customer($this->salon, ['email' => 'jana@example.com']);

    foreach (range(1, 5) as $i) {
        $this->post('/obnovit', ['email' => 'jana@example.com'])->assertSessionHas('status');
    }

    Mail::assertQueued(AccessLinkMail::class, 3);
});

describe('hlavičky a vyhledávače', function () {
    test('stránky se nemají ukládat do cache, vkládat do rámců ani indexovat', function () {
        $response = $this->get(k(tokenFor(customer($this->salon))));

        $response->assertHeader('X-Robots-Tag', 'noindex, nofollow')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Content-Security-Policy', "frame-ancestors 'none'; base-uri 'self'; form-action 'self'; object-src 'none'");

        expect($response->headers->get('Cache-Control'))->toContain('no-store')->toContain('private');
    });

    test('robots.txt zakazuje indexování celé aplikace', function () {
        expect(file_get_contents(public_path('robots.txt')))->toContain("User-agent: *\nDisallow: /");
    });

    test('ve veřejné složce nejsou zbytky po vývoji', function () {
        $files = collect(scandir(public_path()))->reject(fn ($f) => in_array($f, ['.', '..', 'build', 'hot', 'storage'], true))->values()->all();

        expect($files)->toEqualCanonicalizing(['.htaccess', 'icons', 'index.php', 'robots.txt', 'sw.js']);
    });
});

describe('instalace na ostrém serveru', function () {
    test('ukázková data se známým heslem se na ostrém serveru nevytvoří', function () {
        app()->detectEnvironment(fn () => 'production');

        $this->artisan('db:seed', ['--force' => true])->assertSuccessful();

        expect(User::where('email', 'petra@example.com')->exists())->toBeFalse();
    });

    test('luxio:install založí salon a majitelku s vlastním heslem', function () {
        $this->artisan('luxio:install')
            ->expectsQuestion('Název salonu', 'Salon Eva')
            ->expectsQuestion('Adresa veřejného odkazu (/s/…)', 'salon-eva')
            ->expectsQuestion('Jméno majitelky (uvidí ho zákaznice)', 'Eva')
            ->expectsQuestion('Přihlašovací e-mail', 'Eva@Example.com')
            ->expectsQuestion('Heslo (aspoň 10 znaků)', 'velmi-tajne-heslo')
            ->assertSuccessful();

        $eva = User::where('email', 'eva@example.com')->sole();

        expect($eva->role)->toBe(UserRole::Owner)
            ->and($eva->salon->slug)->toBe('salon-eva')
            ->and(Hash::check('velmi-tajne-heslo', $eva->password))->toBeTrue();
    });

    test('krátké heslo instalace odmítne', function () {
        $this->artisan('luxio:install')
            ->expectsQuestion('Název salonu', 'Salon Eva')
            ->expectsQuestion('Adresa veřejného odkazu (/s/…)', 'salon-eva')
            ->expectsQuestion('Jméno majitelky (uvidí ho zákaznice)', 'Eva')
            ->expectsQuestion('Přihlašovací e-mail', 'eva@example.com')
            ->expectsQuestion('Heslo (aspoň 10 znaků)', 'kratke')
            ->assertFailed();

        expect(Salon::where('slug', 'salon-eva')->exists())->toBeFalse();
    });
});
