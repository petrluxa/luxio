# Zadání: rezervační systém pro kadeřnictví

Verze 1 · 15. 11. 2026 · upraveno týž den: bez e-mailů (jen push), dvě kadeřnice, nové barvy

## 1. Cíl

Jednoduchý rezervační systém pro kadeřnictví (na začátku dvě kadeřnice, připraveno na víc).
Zákaznice si rezervují termíny přes **osobní odkaz s tokenem**, bez hesla. Aplikace je **PWA**, kterou si
uloží na plochu telefonu, a dostávají **push notifikace** (připomínka, zrušení, změna). Žádné SMS ani e-maily.

## 2. Role

| Role | Přístup | Co dělá |
|---|---|---|
| Zákaznice | osobní odkaz `/k/{token}` (bez hesla) | rezervuje, vidí a ruší své termíny, dostává notifikace |
| Nová zákaznice | veřejný odkaz salonu `/s/{salon}` | první rezervace, při ní se jí založí účet + token |
| Kadeřnice (staff) | přihlašovací jméno + heslo, „zapamatovat“ | rozvrh, rezervace, zákaznice, vyúčtování, své služby a pracovní dobu (včetně so a ne) |
| Správce salonu (owner) | přihlašovací jméno + heslo | vše jako kadeřnice + ceník, nastavení salonu, správa kadeřnic a jejich přihlášení |

Rozhodnutí z 16. 11. 2026:
- Přihlašuje se **jménem** (např. `sabina`), ne e-mailem – aplikace žádné e-maily neposílá.
- Správce nemusí být kadeřnice (recepce, manažer): pak nemá sloupec v rozvrhu ani přehled „Dnes“
  a po přihlášení vidí rozvrh dne všech kadeřnic.
- Kadeřnice může být v rozvrhu i bez vlastního přihlášení – rezervace jí pak zapisuje správce.
  Přihlašovací jméno a heslo jí nastaví správce (Admin → Tým).
- **Tým nejsou jen kadeřnice.** Každý člen týmu má nepovinné **zaměření** (kadeřnice, manikúra,
  pedikúra, kosmetika… – volný text) a **pořadí** sloupce v rozvrhu, které správce mění šipkami.
  Nový člen se řadí na konec. Kde má salon víc zaměření, dělí se podle nich rozvrh na skupiny
  a zákaznice vidí zaměření u jména („Lena · pedikúra“). V kódu i v textech zůstává pro člena týmu
  slovo „kadeřnice“ tam, kde jde o kadeřnictví obecně.

## 3. Přístup zákaznice (token)

### 3.1 První rezervace (nová zákaznice)
1. Otevře veřejný odkaz salonu (Instagram, web, Google, QR).
2. Vybere kadeřnici (nebo „Kdokoliv“), službu, den a čas.
3. Vyplní **jméno a telefon** (obojí povinné) + souhlas se zpracováním údajů. E-mail se nesbírá.
4. Systém založí `customer` a osobní `customer_access_token`, uloží do prohlížeče dlouhodobou session
   (cookie 1 rok) a přesměruje na `/k/{token}`.
5. Rezervace je podle nastavení salonu buď **potvrzená hned**, nebo **čeká na schválení** kadeřnicí
   (výchozí: nové zákaznice čekají na schválení, stávající potvrzeno hned).
6. Po rezervaci se zobrazí obrazovka **„Uložte si aplikaci na plochu“** (návod pro iPhone/Android)
   a poté výzva k povolení notifikací (jen po ťuknutí na tlačítko – požadavek iOS).
7. Na téže obrazovce si zákaznice může svůj osobní odkaz zkopírovat nebo sdílet, aby ho neztratila.
   E-mailem se neposílá.

Pokud telefon už existuje, nová zákaznice se nezakládá: rezervace se přiřadí existující
zákaznici, ale **jen jako „čeká na schválení“** a nový token se nevydá (ochrana proti převzetí cizího
účtu). Kadeřnice u takové rezervace vidí, že přišla přes veřejný odkaz, a osobní odkaz zákaznici
pošle sama z její karty.

### 3.2 Osobní odkaz
- `/k/{token}` – token 40 znaků (`Str::random(40)`), v DB jen `sha256` hash.
- Kadeřnice může odkaz vygenerovat i pro existující zákaznici a poslat ho přes WhatsApp/Messenger
  (tlačítko Sdílet – Web Share API, fallback kopírovat).
- Rozsah tokenu: `scope_staff_id` = konkrétní kadeřnice, nebo `null` = celý salon.
- Kadeřnice může token **zrušit / vyměnit** (např. při zablokování zákaznice).
- Rate limit: 20 požadavků/min na IP pro `/k/*`, neplatný token → obecná chyba bez detailu.
- Ztracený odkaz: `/obnovit` zákaznici řekne, ať napíše nebo zavolá kadeřnici (s telefonem salonu);
  nový odkaz jí kadeřnice pošle z karty zákaznice. Starý token se nezneplatní, pokud ho kadeřnice nezruší.

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
- **Dvě zákaznice najednou:** kadeřnice i správce smějí zapsat zákaznici i na obsazený čas nebo mimo
  pracovní dobu (pole „Jiný čas“ v průvodci, vždy s konkrétní kadeřnicí). Zákaznicím se takový čas
  nenabízí a samy si ho vynutit nemohou. Rozvrh souběžné rezervace kreslí vedle sebe.
- **Předání jiné kadeřnici:** rezervace přejde ke kolegyni ve stejný čas (i když má plno, s upozorněním).
  Zákaznici se neposílá oznámení – dá jí vědět kadeřnice.
- **Přetažení v rozvrhu (drag and drop):** rezervaci jde myší přetáhnout na jiný čas, do sloupce jiné
  kadeřnice, v týdnu na jiný den. Prstem až po krátkém podržení (jinak by nešlo stránkou posouvat).
  Čas se zaokrouhluje na krok nabídky časů, u okraje okna se stránka sama posouvá. Po puštění se přesun
  potvrzuje v okně, které upozorní na obsazený čas nebo čas mimo pracovní dobu; projde i tak. Platí jen
  pro živé rezervace (čekající a potvrzené), pro správce i kadeřnice.
- **Vyúčtování:** u návštěvy se zapíše, co kadeřnice použila (přípravek z katalogu nebo vlastní název
  + odstín a množství), částka a způsob platby. Zaplacením vzniká **doklad** s číslem `ROK-pořadí`
  (řada pro každý salon zvlášť; oprava částky číslo nemění, zrušené zaplacení ho nevrací do řady).
  Přípravky jdou uložit i bez platby. Zaplacená návštěva, která už začala, je `completed`.
- **Doklad o zaplacení:** veřejná stránka `/doklad/{rezervace}/{kód}`; kód se počítá z klíče aplikace
  (HMAC), neukládá se a bez něj stránka neexistuje. Kadeřnice odkaz pošle přes WhatsApp nebo jinak
  sdílí; zákaznice ho má i u termínu ve své aplikaci. Na dokladu je salon, provozovatel, IČO, datum,
  služby, částka a způsob platby – použité přípravky ne.
- Uložené údaje: snapshot služeb (název, délky, cena) v `booking_items`, aby změna ceníku neměnila historii.

## 6. Notifikace

**Kanál:** jen Web Push (PWA). Žádné e-maily ani SMS. Kdo nemá upozornění zapnutá, vidí změny
v aplikaci – a kadeřnice se po zrušení nebo přesunu dozví, že má dát zákaznici vědět sama.

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
4. **Profil** – jméno, telefon, notifikace zapnuto/vypnuto, „Přidat na plochu“
5. **Onboarding** – přidat na plochu → povolit notifikace

### Správce a kadeřnice (admin)

Rozložení jako u rozvrhu sportovišť (rozhodnutí z 16. 11. 2026): nahoře lišta s **velkým názvem
salonu** a na počítači hlavní nabídkou (Rozvrh, Schválit, Zákaznice, **Admin**, jméno přihlášeného);
na telefonu je nabídka dole. Posouvá se vždy celá stránka, žádné vnitřní posuvníky.

1. **Můj den** – osobní seznam dnešních zákaznic kadeřnice: další zákaznice nahoře, rychlé akce
   (zavolat, poznámka, nepřišla). Správce bez vlastního sloupce ho nemá. Není to úvodní obrazovka.
2. **Rozvrh** – po přihlášení se otevírá rovnou rozvrh. **Kadeřnice (kdo má vlastní sloupec) vidí
   svých sedm dní**: její „kurt“ rozložený do sedmi sloupců, první je vždy zvolený den (výchozí dnešek),
   šipky posouvají o týden, na telefonu se další dny posouvají prstem do strany. Stejně se zobrazí
   kdokoliv po kliknutí na jeho jméno. Správce bez vlastního sloupce vidí **den celého salonu** se sloupcem
   pro každého; kadeřnice se na něj přepne štítkem „Celý salon“ a zpět štítkem „Můj rozvrh“.
   Den se šipkami, Dnes (u celého salonu i Zítra) a **ikona kalendáře** pro skok na libovolný den
   (třeba za měsíc) jsou **v horní liště** (místo názvu salonu). V panelu pod ní vlevo **měsíční kalendář** (skok na den, tečka = den s rezervacemi)
   a uprostřed **cedule salonu**: podsvícený nápis na stěně (vlastní nápis a podtitul z údajů salonu,
   jinak název salonu), pod ní skupiny podle zaměření. Na telefonu je cedule jen nízký pruh
   a štítky jsou v jednom řádku, aby byl rozvrh vidět hned. Pod tím mřížka:
   **sloupce = členové týmu** (jako kurty; pořadí určuje správce), řádky po půl hodině,
   v záhlaví jméno, zaměření a začátek a konec pracovní doby. Nad mřížkou **skupiny podle zaměření**
   (Celý salon, Kadeřnice, Manikúra…) a výběr jednoho člověka; od osmi sloupců je záhlaví zhuštěné. Rezervace ukazuje čas a délku, jméno, telefon
   a službu; barva podle stavu (potvrzená neonová, čeká hnědá, proběhlá nebo zaplacená ztlumená).
   Kliknutí na místo = nová rezervace (i mimo pracovní dobu), tlačítko + u jména = další zákaznice,
   přetažení = přesun. Záhlaví sloupců se na počítači drží pod horní lištou; na telefonu se víc sloupců
   posouvá do strany. Sloupec může být i jiná služba než kadeřnice (např. modelace nehtů).
3. **Ke schválení** – seznam pending s tlačítky Schválit / Zamítnout
4. **Zákaznice** – hledání, karta: kontakty, historie návštěv včetně použitých přípravků,
   **receptura barvy**, poznámky, blokace, „Poslat odkaz“ (vygenerovat/sdílet token)
5. **Rezervace** – detail, přesun, předání jiné kadeřnici, **vyúčtování** a odeslání dokladu
6. **Admin** – rozcestník s boxíky, ať je na jednom místě vidět, co všechno se dá nastavit: Provoz
   (Rozvrh, Ke schválení, Zákaznice), Nabídka a ceník (Ceník služeb, Pracovní doba a volno, Přípravky),
   Salon (Kadeřnice, Údaje salonu a doklad, Pravidla rezervací – jen owner), Můj účet. Nová oblast =
   nový boxík.
7. **Přípravky** – katalog podle značky a druhu (barvy, šampony, péče, styling, ampule, ostatní),
   hledání, vlastní položky, skrytí nepoužívaných; výchozí katalog Kérastase a L'Oréal Professionnel
   (`database/data/pripravky.json`) se nahraje při založení salonu a jde doplnit znovu (owner)
8. **Ceník služeb** – **každá kadeřnice má svůj ceník**: v jedné tabulce zaškrtne, které služby dělá,
   a nastaví vlastní cenu a délky (práce – pauza – dokončení); ukládá se celý najednou. Zákaznice vidí
   u vybrané kadeřnice její ceny, u volby „Kdokoliv“ cenu „od“ nejnižší. Kadeřnice si může přidat
   vlastní službu (dostane ji jen ona) a tu si i upravit; společné služby a pořadí upravuje owner,
   který také vidí a upraví ceník kterékoliv kadeřnice.
9. **Pracovní doba a volno** – každá kadeřnice sobě, správce komukoliv
10. **Tým** – seznam v pořadí sloupců rozvrhu (šipky), jméno, zaměření, přihlašovací jméno a heslo,
    oprávnění, barva v rozvrhu (owner)
11. **Nastavení** – pravidla z 4.3, údaje salonu a dokladu (provozovatel, IČO) (owner)
12. **Můj účet** – vzhled (světlý / tmavý / podle zařízení), změna hesla, upozornění na tomto zařízení,
    odhlášení

**Světlá a tmavá verze:** výchozí je podle nastavení zařízení; přepíná se tlačítkem s měsícem v horní liště
nebo v účtu (vypínač přímo na ceduli správce nechtěl). Volbu si pamatuje prohlížeč (`localStorage`), ne server.

## 9. Datový model

```
salons            id, name, sign_title null, sign_subtitle null (nápis a podtitul cedule), company null, ico null, slug, phone, address, timezone, settings(json), timestamps
users             id, salon_id, name, position null (zaměření), login null (unique), password null,
                  role(owner|staff), color, is_bookable, sort (pořadí sloupce), timestamps
                  -- bez login/password se nepřihlásí
services          id, salon_id, name, description, price_from, price_to null, work_1, pause, work_2,
                  buffer_after, is_active, sort, timestamps
staff_service     user_id, service_id, price_from null, work_1 null, pause null, work_2 null
working_hours     id, user_id, weekday(1-7), starts_at(time), ends_at(time)
time_offs         id, user_id, starts_at(datetime), ends_at(datetime), reason null
customers         id, salon_id, name, phone, preferred_staff_id null,
                  note null, color_recipe(text) null, is_blocked, consent_at, timestamps
customer_access_tokens  id, customer_id, token_hash(unique), scope_staff_id null, last_used_at null,
                  revoked_at null, timestamps
bookings          id, salon_id, staff_id, customer_id, starts_at, ends_at, status, total_price,
                  paid_amount null, payment_method(cash|card|transfer) null, paid_at null,
                  receipt_number null (unique v salonu),
                  customer_note null, staff_note null, source(online|manual|salon_link), reminder_sent_at null,
                  cancelled_at null, cancel_reason null, timestamps
products          id, salon_id, brand, line null, name, kind null, category(barvy|sampony|pece|styling|
                  ampule|ostatni), size null, is_active, timestamps
booking_products  id, booking_id, product_id null, name (opis), note null (odstín, množství), sequence
booking_items     id, booking_id, service_id, name, price, work_1, pause, work_2, buffer_after, sequence
booking_segments  id, booking_id, staff_id, starts_at, ends_at, type(work|pause|buffer)
                  index (staff_id, starts_at)
push_subscriptions  (z balíčku webpush, polymorfní)
notification_log  id, notifiable, type, channel(push), booking_id null, sent_at, error null
```

## 10. Milníky

1. **Základ** – Laravel 12, Tailwind, Alpine, Pest, auth pro admin (Breeze nebo vlastní jednoduchý),
   migrace, modely, seeder (1 salon, 2 kadeřnice, 5 služeb, pracovní doba, 20 zákaznic, rezervace).
2. **SlotFinder** – výpočet volných termínů + testy (kap. 4.4).
3. **Zákaznice** – veřejný odkaz, první rezervace, token, Domů, Nová rezervace, Moje termíny, zrušení.
4. **Admin** – Dnes, Kalendář, Ke schválení, Zákaznice, Služby, Pracovní doba, Nastavení.
5. **PWA + push** – manifest, service worker, onboarding, odběry, notifikace z kap. 6, připomínky.
6. **Obnova odkazu** (přes kadeřnici), instalace a pravidelné úlohy bez příkazové řádky.
7. **Doladění** – tmavý režim, prázdné stavy, chybové stavy, výkon, nasazení (cron, queue worker, HTTPS).

## 11. Otevřené otázky

- Název aplikace a doména
- Hosting – zkušebně v podsložce jiného webu na sdíleném hostingu (bez příkazové řádky); ostrý zatím neurčen
- Kolik lidí bude od začátku – rozhodnuto: sedm kadeřnic, pedikúra, kosmetika a dvě manikúry
  (každý vlastní sloupec, služby podle zaměření) + správce salonu; zatím jeden salon
- Katalog L'Oréal Professionnel je první návrh sestavený bez oficiálního ceníku – kadeřnice ho projdou,
  nepoužívané skryjí a chybějící doplní; odstíny barev se zapisují až u návštěvy
- Bude se později prodávat dalším salonům? (pak od začátku vše přes `salon_id` – model s tím počítá)
