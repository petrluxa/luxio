<?php

use App\Enums\BookingNotice;
use App\Enums\BookingSource;
use App\Enums\BookingStatus;
use App\Models\NotificationLog;
use App\Notifications\BookingNotification;
use App\Services\Booking\BookingManager;
use Illuminate\Notifications\Events\NotificationFailed;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use NotificationChannels\WebPush\WebPushChannel;

beforeEach(function () {
    $this->travelTo(TEST_NOW);
    [$this->salon, $this->petra] = salonWithStaff();
    $this->petra->update(['name' => 'Petra']);
    $this->cut = service($this->salon);
    $this->jana = customer($this->salon, ['name' => 'Jana Nováková']);
    $this->manager = app(BookingManager::class);
});

function sentTo($notifiable, BookingNotice $notice): void
{
    Notification::assertSentTo($notifiable, BookingNotification::class, fn (BookingNotification $n) => $n->notice === $notice);
}

function notSentTo($notifiable, ?BookingNotice $notice = null): void
{
    Notification::assertNotSentTo($notifiable, BookingNotification::class, fn (BookingNotification $n) => $notice === null || $n->notice === $notice);
}

function subscribe($notifiable): void
{
    static $device = 0;

    // Každé zařízení má vlastní adresu (uniqid() se na Windows při rychlém volání opakuje).
    $notifiable->updatePushSubscription('https://push.example.com/zarizeni-'.(++$device), 'klic', 'tajemstvi', 'aes128gcm');
}

describe('kdo se o čem dozví', function () {
    beforeEach(function () {
        Notification::fake();

        // Upozornění chodí jen na telefon – zákaznice i kadeřnice je mají zapnutá.
        subscribe($this->jana);
        subscribe($this->petra);
    });

    test('nová potvrzená rezervace: zákaznice i kadeřnice', function () {
        $this->manager->create($this->jana, [$this->cut], at('10:00'), $this->petra);

        sentTo($this->jana, BookingNotice::Confirmed);
        sentTo($this->petra, BookingNotice::NewBooking);
    });

    test('rezervace ke schválení: jen kadeřnice', function () {
        $this->manager->create($this->jana, [$this->cut], at('10:00'), $this->petra, BookingStatus::Pending);

        sentTo($this->petra, BookingNotice::Pending);
        notSentTo($this->jana);
    });

    test('schválení a zamítnutí: zákaznice', function () {
        $first = $this->manager->create($this->jana, [$this->cut], at('10:00'), $this->petra, BookingStatus::Pending);
        $second = $this->manager->create($this->jana, [$this->cut], at('13:00'), $this->petra, BookingStatus::Pending);

        $this->manager->approve($first);
        $this->manager->reject($second, 'Mám plno');

        sentTo($this->jana, BookingNotice::Confirmed);
        sentTo($this->jana, BookingNotice::Rejected);
    });

    test('zrušení zákaznicí: kadeřnice; zrušení kadeřnicí: zákaznice', function () {
        $first = $this->manager->create($this->jana, [$this->cut], at('10:00'), $this->petra);
        $second = $this->manager->create($this->jana, [$this->cut], at('13:00'), $this->petra);

        $this->manager->cancelByCustomer($first);
        $this->manager->cancelByStaff($second, 'Nemoc');

        sentTo($this->petra, BookingNotice::CancelledByCustomer);
        sentTo($this->jana, BookingNotice::CancelledByStaff);
        notSentTo($this->jana, BookingNotice::CancelledByCustomer);
    });

    test('přesun kadeřnicí: zákaznice se dozví nový i původní čas', function () {
        $booking = $this->manager->create($this->jana, [$this->cut], at('10:00'), $this->petra);

        $this->manager->move($booking, at('14:00'));

        Notification::assertSentTo($this->jana, BookingNotification::class, fn (BookingNotification $n) => $n->notice === BookingNotice::MovedByStaff
            && $n->previousStart->format('H:i') === '10:00'
            && str_contains($n->body(), '14:00')
            && str_contains($n->body(), 'původně'));
    });

    test('přesun zákaznicí: kadeřnice', function () {
        $old = $this->manager->create($this->jana, [$this->cut], at('10:00'), $this->petra);

        $this->manager->create($this->jana, [$this->cut], at('14:00'), $this->petra, replaces: $old);

        sentTo($this->petra, BookingNotice::MovedByCustomer);
        notSentTo($this->petra, BookingNotice::CancelledByCustomer);
    });

    test('ruční rezervace v administraci: zákaznice ano, kadeřnice sama sobě ne', function () {
        $this->manager->create($this->jana, [$this->cut], at('10:00'), $this->petra, source: BookingSource::Manual);

        sentTo($this->jana, BookingNotice::Confirmed);
        notSentTo($this->petra);
    });
});

describe('kanál: jen push, žádné e-maily ani SMS', function () {
    beforeEach(function () {
        Notification::fake();
        $this->booking = $this->manager->create($this->jana, [$this->cut], at('10:00'), $this->petra);
        $this->via = fn ($notifiable, BookingNotice $notice = BookingNotice::Confirmed) => (new BookingNotification($this->booking, $notice))->via($notifiable);
    });

    test('s aktivním push odběrem jde upozornění jen přes push', function () {
        subscribe($this->jana);

        expect(($this->via)($this->jana->fresh()))->toBe([WebPushChannel::class]);
    });

    test('bez push odběru se neposílá nic – zákaznici ani kadeřnici', function () {
        expect(($this->via)($this->jana))->toBe([])
            ->and(($this->via)($this->petra, BookingNotice::NewBooking))->toBe([])
            ->and($this->jana->reachableByPush())->toBeFalse();
    });

    test('vypnutá upozornění: neposílá se nic, ani když má zařízení přihlášené', function () {
        subscribe($this->jana);
        expect($this->jana->fresh()->reachableByPush())->toBeTrue();

        $this->jana->update(['notifications_enabled' => false]);

        expect(($this->via)($this->jana, BookingNotice::Reminder))->toBe([])
            ->and(($this->via)($this->jana, BookingNotice::Confirmed))->toBe([])
            ->and(($this->via)($this->jana, BookingNotice::CancelledByStaff))->toBe([])
            ->and(($this->via)($this->jana, BookingNotice::MovedByStaff))->toBe([])
            ->and($this->jana->reachableByPush())->toBeFalse();
    });
});

describe('texty upozornění', function () {
    beforeEach(function () {
        Notification::fake();
        $this->booking = $this->manager->create($this->jana, [$this->cut], at('10:00'), $this->petra);
        $this->text = fn (BookingNotice $notice) => str_replace("\u{00A0}", ' ', (new BookingNotification($this->booking->fresh(), $notice))->body());
    });

    test('odpovídají zadání', function () {
        expect((new BookingNotification($this->booking, BookingNotice::Confirmed))->title())->toBe('Termín potvrzen')
            ->and(($this->text)(BookingNotice::Confirmed))->toBe('st 11. 11. v 10:00 – Dámský střih')
            ->and(($this->text)(BookingNotice::NewBooking))->toBe('Jana Nováková, st 11. 11. 10:00 – Dámský střih')
            ->and(($this->text)(BookingNotice::Pending))->toBe('Rezervace čeká na schválení: Jana Nováková, st 11. 11. 10:00')
            ->and(($this->text)(BookingNotice::CancelledByCustomer))->toBe('Jana Nováková zrušila termín st 11. 11. 10:00')
            ->and(($this->text)(BookingNotice::Reminder))->toBe('Za 2 dny máte termín: st 11. 11. v 10:00 u Petry');
    });

    test('nová zákaznice a mužský tvar', function () {
        $this->jana->update(['is_verified' => false]);
        expect(($this->text)(BookingNotice::Pending))->toStartWith('Nová zákaznice čeká na schválení');

        $this->jana->update(['name' => 'Petr Novák']);
        expect(($this->text)(BookingNotice::CancelledByCustomer))->toBe('Petr Novák zrušil termín st 11. 11. 10:00');
    });

    test('připomínka den předem říká „Zítra“', function () {
        $this->travelTo('2026-11-10 10:00:00');

        expect(($this->text)(BookingNotice::Reminder))->toStartWith('Zítra máte termín');
    });

    test('push pro zákaznici nese cestu k rezervaci, pro kadeřnici adresu v administraci', function () {
        $forCustomer = (new BookingNotification($this->booking, BookingNotice::Confirmed))->toWebPush($this->jana)->toArray();
        $forStaff = (new BookingNotification($this->booking, BookingNotice::NewBooking))->toWebPush($this->petra)->toArray();

        expect($forCustomer['title'])->toBe('Termín potvrzen')
            ->and($forCustomer['data'])->toBe(['path' => "/terminy/{$this->booking->id}"])
            ->and($forStaff['data'])->toBe(['url' => "/admin/rezervace/{$this->booking->id}"])
            // Ikony bez lomítka na začátku: service worker je doplní i při běhu v podsložce.
            ->and($forCustomer['icon'])->toBe('icons/icon-192.png')
            ->and($forCustomer['badge'])->toBe('icons/badge-96.png')
            ->and(json_encode($forCustomer))->not->toContain('/k/');
    });
});

describe('bez push odběru a záznam upozornění', function () {
    test('rezervace i její zrušení proběhnou bez jediného e-mailu', function () {
        Mail::fake();

        // Skutečné odeslání (fronta běží v testu hned): nikdo nemá push odběr → neodejde nic.
        $booking = $this->manager->create($this->jana, [$this->cut], at('10:00'), $this->petra);
        $this->manager->cancelByStaff($booking, 'Nemoc');

        Mail::assertNothingOutgoing();
        expect(NotificationLog::count())->toBe(0);
    });

    test('odeslané upozornění se zapíše do záznamu', function () {
        $booking = $this->manager->create($this->jana, [$this->cut], at('10:00'), $this->petra);

        event(new NotificationSent($this->jana, new BookingNotification($booking, BookingNotice::Confirmed), WebPushChannel::class));

        expect(NotificationLog::sole())
            ->type->toBe('confirmed')
            ->channel->toBe('push')
            ->booking_id->toBe($booking->id)
            ->sent_at->not->toBeNull()
            ->error->toBeNull();
    });

    test('nepovedené odeslání se zapíše s chybou', function () {
        $booking = $this->manager->create($this->jana, [$this->cut], at('10:00'), $this->petra);

        event(new NotificationFailed($this->petra, new BookingNotification($booking, BookingNotice::NewBooking), WebPushChannel::class, ['message' => 'Odběr už neplatí']));

        expect(NotificationLog::sole())
            ->type->toBe('new_booking')
            ->sent_at->toBeNull()
            ->error->toBe('Odběr už neplatí');
    });
});

describe('připomínky', function () {
    beforeEach(function () {
        Notification::fake();
        subscribe($this->jana);
        // Středa 11. 11. v 10:00 → připomínka 48 h předem = pondělí 9. 11. v 10:00.
        $this->booking = $this->manager->create($this->jana, [$this->cut], at('10:00'), $this->petra);
    });

    test('odejde 48 hodin předem a jen jednou', function () {
        $this->travelTo('2026-11-09 09:54:00');
        $this->artisan('bookings:send-reminders')->assertSuccessful();
        notSentTo($this->jana, BookingNotice::Reminder);

        $this->travelTo('2026-11-09 10:01:00');
        $this->artisan('bookings:send-reminders')->assertSuccessful();
        $this->artisan('bookings:send-reminders')->assertSuccessful();

        Notification::assertSentToTimes($this->jana, BookingNotification::class, 2); // potvrzení + jedna připomínka
        sentTo($this->jana, BookingNotice::Reminder);
        expect($this->booking->fresh()->reminder_sent_at)->not->toBeNull();
    });

    test('termín domluvený na poslední chvíli připomínku 48 h nedostane', function () {
        $this->travelTo('2026-11-10 08:00:00');
        $this->artisan('bookings:send-reminders');

        notSentTo($this->jana, BookingNotice::Reminder);
    });

    test('čekající ani zrušené rezervace se nepřipomínají', function () {
        $pending = $this->manager->create(customer($this->salon), [$this->cut], at('13:00'), $this->petra, BookingStatus::Pending);
        subscribe($pending->customer);
        $this->manager->cancelByStaff($this->booking);

        $this->travelTo('2026-11-09 10:01:00');
        $this->artisan('bookings:send-reminders');
        $this->travelTo('2026-11-09 13:01:00');
        $this->artisan('bookings:send-reminders');

        notSentTo($this->jana, BookingNotice::Reminder);
        notSentTo($pending->customer, BookingNotice::Reminder);
    });

    test('volitelná druhá připomínka 2 hodiny předem', function () {
        $this->salon->update(['settings' => ['second_reminder_hours' => 2]]);

        $this->travelTo('2026-11-11 08:02:00');
        $this->artisan('bookings:send-reminders');

        sentTo($this->jana, BookingNotice::Reminder);
        expect($this->booking->fresh())->second_reminder_sent_at->not->toBeNull()->reminder_sent_at->toBeNull();
    });

    test('po přesunu termínu se připomínka pošle znovu', function () {
        $this->travelTo('2026-11-09 10:01:00');
        $this->artisan('bookings:send-reminders');

        $this->manager->move($this->booking->fresh(), at('10:00', '2026-11-13'));

        expect($this->booking->fresh()->reminder_sent_at)->toBeNull();
    });

    test('skončené termíny se označí jako proběhlé', function () {
        $this->travelTo('2026-11-11 12:00:00');
        $this->artisan('bookings:complete-past')->assertSuccessful();

        expect($this->booking->fresh()->status)->toBe(BookingStatus::Completed);
    });
});

describe('aplikace na plochu a push odběry', function () {
    test('manifest zákaznice začíná na jejím osobním odkazu', function () {
        $token = tokenFor($this->jana);

        $this->get(k($token, '/manifest.webmanifest'))
            ->assertOk()
            ->assertHeader('Content-Type', 'application/manifest+json')
            ->assertJsonPath('start_url', url("/k/{$token}"))
            ->assertJsonPath('scope', url('/').'/')
            ->assertJsonPath('display', 'standalone')
            ->assertJsonPath('name', $this->salon->name)
            ->assertJsonCount(3, 'icons');

        $this->get(k($token))->assertSee("/k/{$token}/manifest.webmanifest", false)->assertSee('data-base="/k/'.$token.'"', false);
    });

    test('manifest administrace začíná v administraci a jde stáhnout bez přihlášení', function () {
        $this->get('/admin/manifest.webmanifest')->assertOk()->assertJsonPath('start_url', url('/admin'));
    });

    test('ikony, service worker a offline stránka existují', function () {
        foreach (['sw.js', 'icons/icon-192.png', 'icons/icon-512.png', 'icons/maskable-512.png', 'icons/apple-touch-icon.png', 'icons/badge-96.png'] as $file) {
            expect(public_path($file))->toBeFile();
        }

        $this->get('/offline')->assertOk()->assertSee('Jste bez připojení');
    });

    test('onboarding nabídne přidání na plochu a povolení upozornění', function () {
        $this->get(k(tokenFor($this->jana), '/aplikace'))
            ->assertOk()
            ->assertSee('Přidat na plochu')
            ->assertSee('Povolit upozornění')
            ->assertSee('Na iPhonu jdou upozornění zapnout až v aplikaci otevřené z plochy');
    });

    test('zákaznice si zapne a vypne upozornění na zařízení', function () {
        $token = tokenFor($this->jana);
        $this->jana->update(['notifications_enabled' => false]);

        $subscription = ['endpoint' => 'https://push.example.com/abc', 'keys' => ['p256dh' => 'klic', 'auth' => 'tajemstvi'], 'contentEncoding' => 'aes128gcm'];

        $this->postJson(k($token, '/push'), $subscription)->assertOk();
        $this->postJson(k($token, '/push'), $subscription)->assertOk();   // opakované uložení nezdvojí odběr

        expect($this->jana->pushSubscriptions()->count())->toBe(1)
            ->and($this->jana->fresh()->notifications_enabled)->toBeTrue();

        $this->deleteJson(k($token, '/push'), ['endpoint' => 'https://push.example.com/abc'])->assertOk();

        expect($this->jana->pushSubscriptions()->count())->toBe(0);
    });

    test('kadeřnice si zapne upozornění po přihlášení; bez přihlášení to nejde', function () {
        $subscription = ['endpoint' => 'https://push.example.com/xyz', 'keys' => ['p256dh' => 'klic', 'auth' => 'tajemstvi']];

        $this->postJson('/admin/push', $subscription)->assertUnauthorized();
        $this->actingAs($this->petra)->postJson('/admin/push', $subscription)->assertOk();

        expect($this->petra->pushSubscriptions()->count())->toBe(1);
        $this->get('/admin/ucet')->assertSee('Aplikace a upozornění');
    });

    test('odběr musí mít platnou adresu a klíče', function () {
        $this->postJson(k(tokenFor($this->jana), '/push'), ['endpoint' => 'http://nezabezpecene.example.com', 'keys' => []])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['endpoint', 'keys.p256dh', 'keys.auth']);
    });
});
