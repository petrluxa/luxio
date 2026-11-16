# Design

Styl: čistý iOS vzhled (velký nadpis, karty se zaoblením, výběr jako „chips“, služby jako karty
s kolečkem, dvě tlačítka dole). Barvy: béžová, neonová žlutá a hnědá – podle fotky ze salonu
(béžová stěna, zlatavý nápis) a zvoleného neonového odstínu. Vzor: `design-mockup.html`.

## Barvy (CSS proměnné)

| Token | Světlý | Tmavý | Použití |
|---|---|---|---|
| `--bg` | `#EFE7DB` | `#1C1713` | pozadí stránky (béžová) |
| `--card` | `#FFFDF8` | `#28211B` | karty, chips |
| `--text` | `#2E241B` | `#F3EBDF` | hlavní text |
| `--muted` | `#6E6152` | `#AFA08E` | vedlejší text |
| `--border` | `#DED2C1` | `#40352B` | rámečky |
| `--primary` | `#D9EF3F` | `#D9EF3F` | neonová žlutá – hlavní tlačítko, vybraný chip (jen jako výplň) |
| `--on-primary` | `#1F1A0A` | `#1F1A0A` | text na neonové žluté |
| `--link` | `#5A4532` | `#E6CFA6` | hnědá / zlatavá – odkazy, obrysová tlačítka, rámeček vybrané karty, ikony |
| `--selected` | `#F3F8CC` | `#2E2D14` | pozadí vybrané karty |
| `--banner` | `#74624F` | `#3A2F25` | info pruh (příští návštěva) – hnědá ze stěny |
| `--banner-text` | `#FFF1D6` | `#F8DEAE` | text info pruhu – zlatavá z nápisu |
| `--danger` | `#B8342D` | `#FF8E85` | zrušení |

Tmavá verze se řídí nastavením zařízení (`prefers-color-scheme`), dokud si ji uživatel nepřepne ručně:
pak má `<html>` atribut `data-theme="light|dark"`, který má přednost. Tmavé hodnoty proměnných jsou proto
v CSS dvakrát (v media dotazu a pod `[data-theme='dark']`) – měnit obě místa. V Tailwindu jsou barvy
namapované na tyto proměnné.

**Neonová žlutá se nikdy nepoužívá jako barva textu ani tenkého obrysu na světlém pozadí** –
není tam čitelná. Je to výplň (tlačítko, vybraný chip, odznak s počtem) s tmavým textem
`--on-primary`. Kde je potřeba barevný text nebo obrys, patří tam `--link`.

Stejné odstíny mají i místa mimo CSS: `theme-color` v `layouts/base.blade.php`, stránka
`offline.blade.php`, manifest v `PwaController` a ikony v `public/icons`.

## Administrace

- **Horní lišta** (přilepená): velký název salonu (22px, na počítači 28px / 700), pod ním adresa a telefon;
  na počítači tlačítka hlavní nabídky – vybrané je `--primary`. Na telefonu je nabídka dole.
- **Rozvrh**: panel s měsíčním kalendářem vlevo a dnem uprostřed; mřížka se záhlavím v `--banner`
  (jména velkými písmeny, `--banner-text`), osa času po půl hodině.
  Rezervace: potvrzená = výplň `--primary` s textem `--on-primary` (neonová je tu výplň, ne text),
  čeká na schválení = `--banner`, proběhlá nebo zaplacená = ztlumená (`--muted` do `--card`),
  levý okraj v barvě kadeřnice. Pauza (působí barva) čárkovaný obrys, úklid tenký šedý pruh.
- **Cedule salonu** v panelu rozvrhu: stěna `--sign-wall` s teplou září shora a ze stran, nápis
  `--sign-letter` s vrstveným stínem (prostorová písmena), podtitul proloženými verzálkami. V tmavé verzi
  („zhasnuto“) záře zmizí a nápis ztmavne. Velikost písma se počítá z délky nápisu a šířky cedule.
- Rozvrh mimo pracovní dobu má `--cal-closed` (ve světlé verzi tmavší béžová, v tmavé téměř černá),
  aby byla pracovní doba v obou verzích zřetelná.
- **Boxíky v Adminu**: karta se zaoblením 16px, ikona ve čtverci `--banner`, počet v kolečku `--primary`.
- Šířka obsahu: formuláře 672px, přehledy 1024px, rozvrh přes celé okno (do 1720px).

## Typografie
- Systémové písmo (`-apple-system, "Segoe UI", Roboto, sans-serif`)
- Nadpis obrazovky 28px / 600, sekce 16px / 600, text 15px, popisky 13px

## Komponenty
- **Chip**: výška 40px, zaoblení 14px, rámeček 1px `--border`; vybraný = `--primary` pozadí, text `--on-primary`
- **Karta výběru**: zaoblení 14px, padding 14px, kolečko vlevo; vybraná = `--selected` + rámeček 1.5px `--link`
- **Info pruh**: zaoblení 14px, `--banner`, text vycentrovaný
- **Spodní lišta**: přilepená dole (safe-area), dvě tlačítka 50/50 – vlevo obrysové (`--link`), vpravo plné `--primary`
- **Časy**: mřížka 4 sloupce, dlaždice 44px
- Dotykové plochy min. 44px, žádné stíny, jemné rámečky
