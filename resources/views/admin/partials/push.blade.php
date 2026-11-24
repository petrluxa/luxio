{{-- Aplikace na plochu a upozornění na telefon pro kadeřnici. --}}
<h2 class="section-title mt-6 scroll-mt-24" id="upozorneni">Aplikace a upozornění</h2>
@include('partials.install-steps', [
    'pushConfig' => ['vapidKey' => config('webpush.vapid.public_key'), 'subscribeUrl' => route('admin.push.store'), 'testUrl' => route('admin.push.test')],
    'pushText' => 'Dáme vám vědět o nové rezervaci, zrušení a o zákaznicích, které čekají na schválení.',
    'fallbackText' => 'Nové rezervace uvidíte tady v administraci.',
])
