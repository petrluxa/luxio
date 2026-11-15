# Zadání: rezervační systém pro kadeřnictví

Verze 1 · 15. 11. 2026

## 1. Cíl

Jednoduchý rezervační systém pro kadeřnictví (na začátku jedna kadeřnice, připraveno na víc kadeřnic).
Zákaznice si rezervují termíny přes **osobní odkaz s tokenem**, bez hesla. Aplikace je **PWA**, kterou si
uloží na plochu telefonu, a dostávají **push notifikace** (připomínka, zrušení, změna). Žádné SMS.

## 2. Role

| Role | Přístup | Co dělá |
|---|---|---|
| Zákaznice | osobní odkaz `/k/{token}` (bez hesla) | rezervuje, vidí a ruší své termíny, dostává notifikace |
| Nová zákaznice | veřejný odkaz salonu `/s/{salon}` | první rezervace, při ní se jí založí účet + token |
| Kadeřnice (staff) | e-mail + heslo, „zapamatovat“ | kalendář, rezervace, zákaznice, své služby a pracovní dobu |
| Majitelka (owner) | e-mail + heslo | vše jako staff + nastavení salonu, správa kadeřnic |

## 3. Přístup zákaznice (token)

### 3.1 První rezervace (nová zákaznice)
1. Otevře veřejný odkaz salonu (Instagram, web, Google, QR).
2. Vybere kadeřnici (nebo „Kdokoliv“), službu, den a čas.
3. Vyplní **jméno, telefon, e-mail** (vše povinné) + souhlas se zpracováním údajů.
4. Systém založí `customer` a osobní `customer_access_token`, uloží do prohlížeče dlouhodobou session
   (cookie 1 rok) a přesměruje na `/k/{token}`.
5. Rezervace je podle nastavení salonu buď **potvrzená hned**, nebo **čeká na schválení** kadeřnicí
   (výchozí: nové zákaznice čekají na schválení, stávající potvrzeno hned).
6. Po rezervaci se zobrazí obrazovka **„Uložte si aplikaci na plochu“** (návod pro iPhone/Android)
   a poté výzva k povolení notifikací (jen po ťuknutí na tlačítko – požadavek iOS).
7. Odejde jeden e-mail s osobním odkazem (aby ho zákaznice neztratila).

Pokud e-mail nebo telefon už existuje, nová zákaznice se nezakládá: rezervace se přiřadí existující
zákaznici, ale **jen jako „čeká na schválení“** a nový token se nevydá – odkaz se pošle na uložený e-mail
(ochrana proti převzetí cizího účtu).

### 3.2 Osobní odkaz
- `/k/{token}` – token 40 znaků (`Str::random(40)`), v DB jen `sha256` hash.
- Kadeřnice může odkaz vygenerovat i pro existující zákaznici a poslat ho přes WhatsApp/Messenger
  (tlačítko Sdílet – Web Share API, fallback kopírovat).
- Rozsah tokenu: `scope_staff_id` = konkrétní kadeřnice, nebo `null` = celý salon.
- Kadeřnice může token **zrušit / vyměnit** (např. při zablokování zákaznice).
- Rate limit: 20 požadavků/min na IP pro `/k/*`, neplatný token → obecná chyba bez detailu.
- Ztracený odkaz: `/obnovit` → zadá e-mail → přijde nový odkaz (platnost odkazu v e-mailu 30 min,
  starý token se nezneplatní, pokud ho zákaznice nezruší).

## 4. Služby a čas

### 4.1 Služba
- název, popis, cena (`price_from`, volitelně `price_to` → zobrazit „od 1 400 Kč“), aktivní, pořadí
- **fáze trvání**: `work_1` (min), `pause` (min, působení barvy), `work_2` (min)
  - příklad Dámský střih: 45 / 0 / 0
  - příklad Barvení a střih: 30 / 40 / 60 → celkem 2 h 10 min, ale 40 min pauza je volná
- `buffer_after` (min) – úklid po službě
- Kadeřnice může mít u služby vlastní délku/cenu (pivot `staff_service`).
- Rezervace může obsahovat **víc služeb** (střih + foukaná) – řadí se za sebou, délky se sčítají.

### 4.2 Pracovní doba
- `working_hours`: kadeřnice × den v týdnu × interval (víc intervalů denně = polední pauza)
- `time_offs`: dovolená / blokace od–do (celý den nebo část)
- Státní svátky ČR: automaticky zavřeno (lze přepsat výjimkou)

### 4.3 Pravidla salonu (nastavení)
| Nastavení | Výchozí |
|---|---|
| krok nabídky časů | 15 min |
| nejdřív lze rezervovat | 2 h předem |
| nejdál lze rezervovat | 60 dní |
| zrušení zákaznicí nejpozději | 24 h předem |
| nové zákaznice čekají na schválení | ano |
| připomínka | 48 h předem (+ volitelně 2 h předem) |

### 4.4 Výpočet volných termínů (jádro)
Služba `App\Services\Availability\SlotFinder`:
- vstup: kadeřnice (nebo všechny), seznam služeb, datum
- každá existující rezervace se ukládá jako **segmenty** (`booking_segments`): `work` blokuje,
  `pause` neblokuje (do pauzy se smí vejít jiná rezervace, pokud se do ní celá vejde, včetně bufferu)
- kandidátní začátky po kroku 15 min v rámci pracovní doby; termín je volný, pokud žádný `work`
  segment nové rezervace nekoliduje s `work` segmentem existující rezervace, time_off ani koncem
  pracovní doby; `pause` nové rezervace smí přesahovat jen do pracovní doby
- respektuje „nejdřív/nejdál“ lhůty
- „Kdokoliv“ = sjednocení časů všech kadeřnic, při rezervaci se přiřadí ta s nejméně rezervacemi daný den
- souběh: při uložení rezervace znovu ověřit dostupnost v DB transakci se zámkem (`lockForUpdate`
  na řádcích kadeřnice/dne), aby nevznikla dvojí rezervace
- **pokrýt testy**: pauza barvy, polední pauza, time_off, hranice dne, víc služeb, souběžná rezervace

## 5. Rezervace

Stavy: `pending` (čeká na schválení) · `confirmed` · `cancelled_by_customer` · `cancelled_by_staff`
· `completed` · `no_show`

- Zákaznice: vytvořit, zobrazit, zrušit (do lhůty), přeplánovat (= zrušit + nová, v jednom kroku).
- Po lhůtě zrušení: tlačítko nahradí text „Pro zrušení zavolejte“ + telefon salonu.
- Kadeřnice: vytvořit ručně (zákaznice volá), přesunout, zrušit s důvodem, schválit/zamítnout pending,
  označit „nepřišla“; poznámka ke každé návštěvě.
- Uložené údaje: snapshot služeb (název, délky, cena) v `booking_items`, aby změna ceníku neměnila historii.

## 6. Notifikace

**Kanál:** Web Push (PWA). **Záloha:** e-mail, pouze pokud adresát nemá žádný aktivní push odběr.

| Událost | Komu | Text (příklad) |
|---|---|---|
| nová rezervace (confirmed) | zákaznice | „Termín potvrzen: st 19. 11. v 10:30 – Dámský střih“ |
| nová rezervace | kadeřnice | „Nová rezervace: Jana Nováková, st 19. 11. 10:30“ |
| čeká na schválení | kadeřnice | „Nová zákaznice čeká na schválení: …“ |
| schváleno / zamítnuto | zákaznice | „Termín potvrzen …“ / „Termín bohužel nelze …“ |
| připomínka 48 h | zákaznice | „Za 2 dny máte termín: pá 21. 11. v 10:00 u Petry“ |
| zrušení zákaznicí | kadeřnice | „Jana Nováková zrušila termín pá 21. 11. 10:00“ |
| zrušení / přesun kadeřnicí | zákaznice | „Váš termín byl přesunut na …“ |

- Klik na notifikaci otevře příslušnou rezervaci v aplikaci.
- Připomínky: příkaz `bookings:send-reminders` každých 5 min, vybere `confirmed` rezervace se
  `starts_at` v okně [teď + 48 h − 5 min, teď + 48 h] a `reminder_sent_at IS NULL`.
- Push odběry: tabulka balíčku webpush (polymorfní – `Customer` i `User`), víc zařízení na osobu,
  neplatné odběry (410 Gone) mazat.
- E-maily přes transakční službu (Brevo / Mailgun / SES), SPF + DKIM + DMARC; přiložit `.ics`.

## 7. PWA

- `manifest.webmanifest` (název, ikony 192/512, `display: standalone`, `start_url`)
- **start_url zákaznice musí obsahovat její token** – manifest generovat dynamicky
  (`/k/{token}/manifest.webmanifest`), aby ikona na ploše otevřela její účet (iOS nesdílí cookies mezi
  Safari a aplikací na ploše).
- Service worker: příjem push, klik na notifikaci, jednoduchá offline stránka.
- Obrazovka „Přidat na plochu“: detekce iOS Safari vs. Android Chrome (`beforeinstallprompt`),
  krokový návod s obrázky; na iOS bez instalace na plochu push nejde – vysvětlit.
- Kadeřnice má stejnou PWA (start_url `/admin`).

## 8. Obrazovky

### Zákaznice
1. **Domů** – příští návštěva (karta), tlačítka „Nová rezervace“, „Moje termíny“
2. **Nová rezervace** – kadeřnice (chips) → služby (karty s výběrem, víc služeb) → den (chips na 14 dní,
   šipka dál) → čas (dlaždice) → shrnutí → Rezervovat (vzor viz `docs/design-mockup.html`)
3. **Moje termíny** – budoucí + historie, zrušit / přesunout
4. **Profil** – jméno, telefon, e-mail, notifikace zapnuto/vypnuto, „Přidat na plochu“
5. **Onboarding** – přidat na plochu → povolit notifikace

### Kadeřnice (admin)
1. **Dnes** – časová osa dne, další zákaznice nahoře, rychlé akce (zavolat, poznámka, nepřišla)
2. **Kalendář** – den / týden, ťuknutí na volné místo = nová rezervace, přesun termínu
3. **Ke schválení** – seznam pending s tlačítky Schválit / Zamítnout
4. **Zákaznice** – hledání, karta: kontakty, historie návštěv, **receptura barvy**, poznámky,
   blokace, „Poslat odkaz“ (vygenerovat/sdílet token)
5. **Služby** – ceník, délky fází, pořadí
6. **Pracovní doba a volno**
7. **Nastavení** – pravidla z 4.3, údaje salonu, kadeřnice (owner)

## 9. Datový model

```
salons            id, name, slug, phone, address, timezone, settings(json), timestamps
users             id, salon_id, name, email, password, role(owner|staff), color, is_bookable, timestamps
services          id, salon_id, name, description, price_from, price_to null, work_1, pause, work_2,
                  buffer_after, is_active, sort, timestamps
staff_service     user_id, service_id, price_from null, work_1 null, pause null, work_2 null
working_hours     id, user_id, weekday(1-7), starts_at(time), ends_at(time)
time_offs         id, user_id, starts_at(datetime), ends_at(datetime), reason null
customers         id, salon_id, name, phone, email, email_verified_at null, preferred_staff_id null,
                  note null, color_recipe(text) null, is_blocked, consent_at, timestamps
customer_access_tokens  id, customer_id, token_hash(unique), scope_staff_id null, last_used_at null,
                  revoked_at null, timestamps
bookings          id, salon_id, staff_id, customer_id, starts_at, ends_at, status, total_price,
                  customer_note null, staff_note null, source(online|manual), reminder_sent_at null,
                  cancelled_at null, cancel_reason null, timestamps
booking_items     id, booking_id, service_id, name, price, work_1, pause, work_2, buffer_after, sequence
booking_segments  id, booking_id, staff_id, starts_at, ends_at, type(work|pause|buffer)
                  index (staff_id, starts_at)
push_subscriptions  (z balíčku webpush, polymorfní)
notification_log  id, notifiable, type, channel(push|mail), booking_id null, sent_at, error null
```

## 10. Milníky

1. **Základ** – Laravel 12, Tailwind, Alpine, Pest, auth pro admin (Breeze nebo vlastní jednoduchý),
   migrace, modely, seeder (1 salon, 1 kadeřnice, 5 služeb, pracovní doba, 20 zákaznic, rezervace).
2. **SlotFinder** – výpočet volných termínů + testy (kap. 4.4).
3. **Zákaznice** – veřejný odkaz, první rezervace, token, Domů, Nová rezervace, Moje termíny, zrušení.
4. **Admin** – Dnes, Kalendář, Ke schválení, Zákaznice, Služby, Pracovní doba, Nastavení.
5. **PWA + push** – manifest, service worker, onboarding, odběry, notifikace z kap. 6, připomínky.
6. **E-mail záloha + .ics**, obnova odkazu.
7. **Doladění** – tmavý režim, prázdné stavy, chybové stavy, výkon, nasazení (cron, queue worker, HTTPS).

## 11. Otevřené otázky

- Název aplikace a doména
- Hosting (stejný jako Roger?)
- Kolik kadeřnic bude od začátku
- Bude se později prodávat dalším salonům? (pak od začátku vše přes `salon_id` – model s tím počítá)
