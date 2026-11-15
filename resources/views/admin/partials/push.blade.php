{{-- Aplikace na plochu a upozornění na telefon pro kadeřnici. --}}
<h2 class="section-title mt-6">Aplikace a upozornění</h2>
@include('partials.install-steps', [
    'pushConfig' => ['vapidKey' => config('webpush.vapid.public_key'), 'subscribeUrl' => route('admin.push.store')],
    'pushText' => 'Dáme vám vědět o nové rezervaci, zrušení a o zákaznicích, které čekají na schválení.',
    'fallbackText' => 'Upozornění vám budou chodit e-mailem.',
])
