# Luxio – rezervační systém pro kadeřnictví

Zákaznice si přes osobní odkaz (bez hesla) rezervují termíny u své kadeřnice, kadeřnice spravuje
kalendář v administraci. Aplikace je PWA, upozornění chodí jako Web Push na telefon. Žádné SMS ani e-maily.

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
php artisan queue:work          # ve druhém okně: odesílá upozornění
```

Výchozí `.env` používá SQLite, takže není potřeba nic dalšího instalovat. Pro MySQL nastavte `DB_CONNECTION=mysql` a přístupy k databázi.

Ukázková data (jen pro vývoj): administrace `petra@example.com` / `heslo123` (majitelka),
druhá kadeřnice `lucie@example.com` / `heslo123`, veřejný odkaz `/s/petra`.

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
| Upozornění (jen push) | `app/Notifications`, `app/Listeners` |
| Instalace a pravidelné úlohy bez příkazové řádky | `app/Http/Controllers/System`, `routes/system.php` |
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
- **Známý telefon** ve veřejném formuláři nezaloží nový účet ani nevydá token – rezervace čeká
  na schválení a kadeřnice u ní vidí, že má zákaznici poslat její osobní odkaz.
- **Žádné e-maily ani SMS.** Kdo nemá upozornění na telefonu zapnutá, vidí změny jen v aplikaci –
  administrace proto kadeřnici po zrušení nebo přesunu řekne, že má dát zákaznici vědět sama.
- **Ztracený odkaz** posílá kadeřnice z karty zákaznice (Poslat odkaz → sdílet přes WhatsApp / Messenger).

## Nasazení na server

1. Kořen webu musí mířit do složky `public/` (zbytek aplikace nesmí být dostupný z internetu).
2. `.env`: `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL=https://…`, `SESSION_SECURE_COOKIE=true`,
   přístupy k MySQL a `VAPID_SUBJECT=mailto:…` (kontakt pro služby Apple a Google, které upozornění
   doručují – e-mail se na něj neposílá).
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
6. Za proxy nebo CDN nastavte `TRUSTED_PROXIES`, aby limity podle IP adresy počítaly skutečné návštěvníky.

Ukázková data (`db:seed`) se na ostrém serveru nevytvoří – mají veřejně známé heslo.

### Podsložka webu a hosting bez příkazové řádky

Aplikace umí běžet i v podsložce cizího webu (např. `https://example.com/luxio`) a na sdíleném
hostingu bez SSH a bez cronu:

- **Podsložka**: `APP_URL` včetně podsložky a `SESSION_PATH=/luxio`. Ikony, manifest, service worker
  i upozornění si podsložku doplní samy.
- **Rozložení souborů**: obsah `public/` se nahraje do složky webu, zbytek aplikace (včetně `vendor`
  z `composer install --no-dev` a `.env`) do její podsložky `_app/`. `index.php` ji tam najde sám;
  přístup zvenku do `_app/` zakazuje `.htaccess` – po nahrání ověřte, že `…/_app/.env` vrací 403.
- **Instalace**: `LUXIO_SETUP_KEY` zapne adresu `/instalace/{klíč}`. Její otevření připraví databázi
  (migrace) a při první instalaci nabídne založení salonu s vlastním heslem.
- **Pravidelné úlohy**: `LUXIO_CRON_KEY` zapne adresu `/cron/{klíč}`; časovač hostingu ji má volat
  každých 5 minut (připomínky, uzavírání proběhlých termínů, fronta). Při řidším volání prodlužte
  `REMINDER_WINDOW_MINUTES`.
- **Fronta**: bez trvale běžícího procesu nastavte `QUEUE_CONNECTION=sync` – upozornění pak odejde hned.
- **Sdílená databáze**: `DB_PREFIX=luxio_` oddělí tabulky od jiné aplikace ve stejné databázi.
  Tabulky se zakládají jako InnoDB (`DB_ENGINE`), i když má server výchozí MyISAM – ten neumí
  transakce, na kterých stojí ochrana proti dvojí rezervaci.
- **Sestavení a nahrání**: `php nastroje/priprav-env.php https://…/luxio` založí nastavení pro server
  (`.env.nasazeni`, není v gitu), `php nastroje/sestav-balik.php` sestaví balíček a rozbalovač do
  `_nasazeni/`. Oba soubory se nahrají do složky webu a `nastroje/rozbal-na-serveru.sh https://…`
  balíček po částech rozbalí; rozbalovač nejdřív zkontroluje verzi PHP a rozšíření a nakonec smaže
  balíček i sám sebe. Aktualizace: `sestav-balik.php --bez-env`.
