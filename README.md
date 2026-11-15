# Luxio – rezervační systém pro kadeřnictví

Zákaznice si přes osobní odkaz (bez hesla) rezervují termíny u své kadeřnice, kadeřnice spravuje
kalendář v administraci. Aplikace je PWA, upozornění chodí jako Web Push na telefon.

- Zadání: [docs/SPEC.md](docs/SPEC.md)
- Vzhled: [docs/DESIGN.md](docs/DESIGN.md), vzor [docs/design-mockup.html](docs/design-mockup.html)

## Technologie

Laravel 12 · PHP 8.3+ · MySQL 8 · Blade + Alpine.js + Tailwind CSS (Vite) · Pest

## Spuštění na vlastním počítači

```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate --seed      # vypíše odkaz do administrace i ukázkový odkaz zákaznice
npm install
npm run build                   # nebo `npm run dev` při úpravách vzhledu
php artisan serve
```

Výchozí `.env` používá SQLite, takže není potřeba nic dalšího instalovat. Pro MySQL nastavte
`DB_CONNECTION=mysql` a přístupy k databázi.

Ukázková data: administrace `petra@example.com` / `heslo123`, veřejný odkaz salonu `/s/petra`.

## Testy

```bash
php vendor/bin/pest
```

Jádro systému – výpočet volných termínů – je v `app/Services/Availability` a pokrývají ho testy
v `tests/Feature/Availability` a `tests/Unit`.

## Jak je aplikace poskládaná

| Část | Kde |
|---|---|
| Výpočet volných termínů (pracovní doba, svátky, volno, pauza na působení barvy) | `app/Services/Availability` |
| Vytvoření, zrušení, přesun a schválení rezervace (transakce se zámkem) | `app/Services/Booking` |
| Osobní odkaz zákaznice `/k/{token}` (v databázi jen SHA-256 hash) | `app/Http/Middleware/ResolveCustomerToken.php` |
| Veřejný odkaz salonu `/s/{salon}` – první rezervace | `app/Http/Controllers/PublicBookingController.php` |
| Administrace kadeřnice `/admin` | `app/Http/Controllers/Admin` |
| České formáty data, času a ceny | `app/Support/Cz.php` |
