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

Tmavý režim přes `@media (prefers-color-scheme: dark)`; v Tailwindu barvy namapovat na tyto proměnné.

**Neonová žlutá se nikdy nepoužívá jako barva textu ani tenkého obrysu na světlém pozadí** –
není tam čitelná. Je to výplň (tlačítko, vybraný chip, odznak s počtem) s tmavým textem
`--on-primary`. Kde je potřeba barevný text nebo obrys, patří tam `--link`.

Stejné odstíny mají i místa mimo CSS: `theme-color` v `layouts/base.blade.php`, stránka
`offline.blade.php`, manifest v `PwaController` a ikony v `public/icons`.

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
