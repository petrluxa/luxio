# Luxio – rezervační systém pro kadeřnictví

Zákaznice si přes osobní odkaz (bez hesla) rezervují termíny u své kadeřnice, kadeřnice spravuje
kalendář v administraci. Aplikace je PWA, upozornění chodí jako Web Push na telefon. Žádné SMS.

- Zadání: [docs/SPEC.md](docs/SPEC.md)
- Vzhled: [docs/DESIGN.md](docs/DESIGN.md), vzor [docs/design-mockup.html](docs/design-mockup.html)

## Technologie

Laravel 12 · PHP 8.3+ · MySQL 8 · Blade + Alpine.js + Tailwind CSS (Vite) · Web Push (VAPID) · Pest

## Spuštění na vlastním počítači

```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan webpush:vapid       # klíče pro upozornění
php artisan migrate --seed      # vypíše odkaz do administrace i ukázkový odkaz zákaznice
npm install
npm run build                   # nebo `npm run dev` při úpravách vzhledu
php artisan serve
php artisan queue:work          # ve druhém okně: odesílá upozornění a e-maily
```

Výchozí `.env` používá SQLite a e-maily zapisuje do `storage/logs/laravel.log`, takže není potřeba
nic dalšího instalovat. Pro MySQL nastavte `DB_CONNECTION=mysql` a přístupy k databázi.

Ukázková data (jen pro vývoj): administrace `petra@example.com` / `heslo123`, veřejný odkaz `/s/petra`.

Na Windows potřebuje generování klíčů a šifrování upozornění proměnnou `OPENSSL_CONF`
s cestou k `openssl.cnf` (v balíku PHP ve složce `extras/ssl`).

## Testy

```bash
php vendor/bin/pest
```

Jádro systému – výpočet volných termínů – je v `app/Services/Availability` a pokrývají ho testy
v `tests/Feature/Availability` a `tests/Unit` (pauza na působení barvy, polední pauza, volno,
hranice dne, víc služeb, souběžná rezervace, státní svátky, lhůty).

## Jak je aplikace poskládaná

| Část | Kde |
|---|---|
| Výpočet volných termínů (pracovní doba, svátky, volno, pauza barvy) | `app/Services/Availability` |
| Vytvoření, zrušení, přesun a schválení rezervace (transakce se zámkem) | `app/Services/Booking` |
| Osobní odkaz zákaznice `/k/{token}` (v databázi jen SHA-256 hash) | `app/Http/Middleware/ResolveCustomerToken.php` |
| Veřejný odkaz salonu `/s/{salon}` – první rezervace | `app/Http/Controllers/PublicBookingController.php` |
| Administrace kadeřnice `/admin` | `app/Http/Controllers/Admin`, `routes/admin.php` |
| Upozornění (push, e-mail jako záloha, `.ics`) | `app/Notifications`, `app/Listeners` |
| Připomínky a plánovač | `app/Console/Commands`, `routes/console.php` |
| PWA: manifest, service worker, onboarding | `app/Http/Controllers/PwaController.php`, `public/sw.js`, `resources/js/push.js` |
| České formáty data, času a ceny | `app/Support/Cz.php` |

### Pravidla, která stojí za zmínku

- **Pauza barvy** kadeřnici neblokuje: do pauzy se vejde jiná zákaznice, pokud se tam vejde celá
  i s úklidem. Celá služba (práce i pauzy) musí ležet v jednom souvislém úseku pracovní doby;
  úklid po poslední zákaznici smí konec pracovní doby přesáhnout.
- **Víc služeb** v jedné rezervaci jde za sebou v pořadí ceníku, uklízí se jednou (nejdelší úklid).
- **„Kdokoliv“** nabídne sjednocení časů všech kadeřnic a přiřadí tu s nejméně rezervacemi ten den.
- **Nová zákaznice** čeká na schválení; schválením první rezervace se stane ověřenou.
- **Známý e-mail nebo telefon** ve veřejném formuláři nezaloží nový účet ani nevydá token –
  rezervace čeká na schválení a odkaz odejde na uložený e-mail.
- **Vypnutá upozornění** u zákaznice: připomínky se neposílají, zrušení nebo přesun termínu
  kadeřnicí jí ale přijde e-mailem.

## Nasazení na server

1. Kořen webu musí mířit do složky `public/` (zbytek aplikace nesmí být dostupný z internetu).
2. `.env`: `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL=https://…`, `SESSION_SECURE_COOKIE=true`,
   přístupy k MySQL, poštovní služba (Brevo / Mailgun / SES) a `VAPID_SUBJECT=mailto:…`.
3. Příkazy po nahrání:

   ```bash
   composer install --no-dev --optimize-autoloader
   npm ci && npm run build
   php artisan key:generate        # jen poprvé
   php artisan webpush:vapid       # jen poprvé – změna klíčů zruší všechny odběry upozornění
   php artisan migrate --force
   php artisan luxio:install       # jen poprvé – založí salon a majitelku s vlastním heslem
   php artisan config:cache && php artisan route:cache && php artisan view:cache
   ```

4. **Cron každou minutu** – spouští připomínky, uzavírání proběhlých termínů i frontu:

   ```
   * * * * * cd /cesta/k/aplikaci && php artisan schedule:run >> /dev/null 2>&1
   ```

   Kde může běžet trvalý proces (`php artisan queue:work` pod supervizorem), nastavte
   `QUEUE_VIA_SCHEDULER=false`.
5. **HTTPS je nutné** – bez něj nefunguje přidání na plochu ani upozornění.
6. **Pošta**: doména odesílatele má mít SPF, DKIM a DMARC, jinak e-maily skončí ve spamu.
7. Za proxy nebo CDN nastavte důvěryhodné proxy, aby limity podle IP adresy počítaly skutečné návštěvníky.

Ukázková data (`db:seed`) se na ostrém serveru nevytvoří – mají veřejně známé heslo.
