<?php

use App\Notifications\TestNotification;
use App\Support\SingleDevice;
use GuzzleHttp\Psr7\Request as PushRequest;
use GuzzleHttp\Psr7\Response as PushResponse;
use Minishlink\WebPush\MessageSentReport;
use NotificationChannels\WebPush\WebPushChannel;

/*
 * Zkušební upozornění: tlačítko po zapnutí upozornění, které ukáže, jestli na zařízení opravdu chodí.
 */

beforeEach(function () {
    $this->travelTo(TEST_NOW);
    [$this->salon, $this->petra] = salonWithStaff();
    $this->jana = customer($this->salon, ['name' => 'Jana Nováková']);
});

/** Služba upozornění (Apple, Google…) se v testu nevolá – odpověď za ni dá tato náhrada. */
function pushService(?MessageSentReport $report, ?array &$sent = null): void
{
    $sent = [];

    test()->mock(WebPushChannel::class)
        ->shouldReceive('send')
        ->andReturnUsing(function ($notifiable, $notification) use ($report, &$sent) {
            $sent[] = [$notifiable, $notification];

            return $report ? [$report] : [];
        });
}

function pushReport(string $endpoint, ?int $status, bool $success, string $reason = 'OK'): MessageSentReport
{
    return new MessageSentReport(new PushRequest('POST', $endpoint), $status ? new PushResponse($status) : null, $success, $reason);
}

test('kadeřnice si pošle zkušební upozornění jen na zařízení, ze kterého o něj žádá', function () {
    $this->petra->updatePushSubscription('https://push.example.com/telefon', 'klic', 'tajemstvi', 'aes128gcm');
    $this->petra->updatePushSubscription('https://push.example.com/pocitac', 'klic', 'tajemstvi', 'aes128gcm');

    pushService(pushReport('https://push.example.com/telefon', 201, true), $sent);

    $this->actingAs($this->petra)
        ->postJson('/admin/push/zkouska', ['endpoint' => 'https://push.example.com/telefon'])
        ->assertOk()
        ->assertJson(['ok' => true, 'expired' => false, 'status' => 201, 'reason' => null]);

    [$notifiable, $notification] = $sent[0];

    expect($sent)->toHaveCount(1)
        ->and($notifiable)->toBeInstanceOf(SingleDevice::class)
        ->and($notifiable->routeNotificationFor('WebPush')->pluck('endpoint')->all())->toBe(['https://push.example.com/telefon'])
        ->and($notification)->toBeInstanceOf(TestNotification::class);

    $message = $notification->toWebPush($notifiable)->toArray();

    expect($message['title'])->toBe('Zkušební upozornění')
        ->and($message['data'])->toBe(['url' => '/admin/ucet']);
});

test('zákaznice si pošle zkušební upozornění ze svého odkazu', function () {
    $token = tokenFor($this->jana);
    $this->jana->updatePushSubscription('https://push.example.com/jana', 'klic', 'tajemstvi', 'aes128gcm');

    pushService(pushReport('https://push.example.com/jana', 201, true), $sent);

    $this->postJson(k($token, '/push/zkouska'), ['endpoint' => 'https://push.example.com/jana'])
        ->assertOk()
        ->assertJson(['ok' => true]);

    // Ťuknutí na upozornění otevře její aplikaci – osobní odkaz si doplní zařízení samo.
    expect($sent[0][1]->toWebPush($sent[0][0])->toArray()['data'])->toBe(['path' => '']);
});

test('cizí nebo neznámé zařízení zkušební upozornění nedostane', function () {
    $this->jana->updatePushSubscription('https://push.example.com/jana', 'klic', 'tajemstvi', 'aes128gcm');

    pushService(pushReport('https://push.example.com/jana', 201, true), $sent);

    $this->actingAs($this->petra)
        ->postJson('/admin/push/zkouska', ['endpoint' => 'https://push.example.com/jana'])
        ->assertOk()
        ->assertJson(['ok' => false, 'expired' => true]);

    expect($sent)->toBeEmpty();
});

test('odpověď řekne, proč upozornění neodešlo', function () {
    $this->petra->updatePushSubscription('https://push.example.com/telefon', 'klic', 'tajemstvi', 'aes128gcm');

    pushService(pushReport('https://push.example.com/telefon', 403, false, 'Podpis serveru nesedí'));

    $this->actingAs($this->petra)
        ->postJson('/admin/push/zkouska', ['endpoint' => 'https://push.example.com/telefon'])
        ->assertOk()
        ->assertJson(['ok' => false, 'expired' => false, 'status' => 403, 'reason' => 'Podpis serveru nesedí']);
});

test('zaniklý odběr se pozná, aby si ho zařízení založilo znovu', function () {
    $this->petra->updatePushSubscription('https://push.example.com/stary', 'klic', 'tajemstvi', 'aes128gcm');

    pushService(pushReport('https://push.example.com/stary', 410, false, 'Gone'));

    $this->actingAs($this->petra)
        ->postJson('/admin/push/zkouska', ['endpoint' => 'https://push.example.com/stary'])
        ->assertOk()
        ->assertJson(['ok' => false, 'expired' => true, 'status' => 410]);
});

test('když se server ke službě upozornění nedovolá, vrátí srozumitelnou odpověď místo chyby', function () {
    $this->petra->updatePushSubscription('https://push.example.com/telefon', 'klic', 'tajemstvi', 'aes128gcm');

    test()->mock(WebPushChannel::class)->shouldReceive('send')->andThrow(new RuntimeException('Spojení se nezdařilo'));

    $this->actingAs($this->petra)
        ->postJson('/admin/push/zkouska', ['endpoint' => 'https://push.example.com/telefon'])
        ->assertOk()
        ->assertJson(['ok' => false, 'status' => null, 'reason' => 'RuntimeException: Spojení se nezdařilo']);
});

test('bez přihlášení a bez adresy zařízení to nejde; obrazovky tlačítko nabízejí', function () {
    $this->postJson('/admin/push/zkouska', ['endpoint' => 'https://push.example.com/x'])->assertUnauthorized();
    $this->actingAs($this->petra)->postJson('/admin/push/zkouska', [])->assertStatus(422);

    // Adresa zkoušky je na stránce v nastavení tlačítka (šablona v ní lomítka zapisuje se zpětnými).
    $this->get('/admin/ucet')->assertOk()->assertSee('Poslat zkušební upozornění')->assertSee('testUrl', false)->assertSee('zkouska', false);
    $this->get(k(tokenFor($this->jana), '/aplikace'))->assertOk()->assertSee('Poslat zkušební upozornění')->assertSee('testUrl', false)->assertSee('zkouska', false);
});
