# Design

Styl: čistý iOS vzhled (velký nadpis, karty se zaoblením, výběr jako „chips“, služby jako karty
s kolečkem, dvě tlačítka dole). Barvy: bílá, béžová, starorůžová. Vzor: `design-mockup.html`.

## Barvy (CSS proměnné)

| Token | Světlý | Tmavý | Použití |
|---|---|---|---|
| `--bg` | `#F6EFE8` | `#1E1918` | pozadí stránky (béžová) |
| `--card` | `#FFFFFF` | `#2A2321` | karty, chips |
| `--text` | `#3B2E2C` | `#F3E9E4` | hlavní text |
| `--muted` | `#8E7A73` | `#A8968F` | vedlejší text |
| `--border` | `#E7DAD0` | `#3B322F` | rámečky |
| `--primary` | `#B97D80` | `#D6A7A8` | starorůžová – hlavní tlačítko, vybraný chip |
| `--on-primary` | `#FFFFFF` | `#2A1F1E` | text na starorůžové |
| `--selected` | `#F5E3E2` | `#3D2C2D` | pozadí vybrané karty |
| `--banner` | `#FBEDEB` | `#2F2524` | info pruh (příští návštěva) |
| `--banner-text` | `#9A5F63` | `#E3B9BA` | text info pruhu |
| `--danger` | `#B5524E` | `#E59591` | zrušení |

Tmavý režim přes `@media (prefers-color-scheme: dark)`; v Tailwindu barvy namapovat na tyto proměnné.

## Typografie
- Systémové písmo (`-apple-system, "Segoe UI", Roboto, sans-serif`)
- Nadpis obrazovky 28px / 600, sekce 16px / 600, text 15px, popisky 13px

## Komponenty
- **Chip**: výška 40px, zaoblení 14px, rámeček 1px `--border`; vybraný = `--primary` pozadí
- **Karta výběru**: zaoblení 14px, padding 14px, kolečko vlevo; vybraná = `--selected` + rámeček 1.5px `--primary`
- **Info pruh**: zaoblení 14px, `--banner`, text vycentrovaný
- **Spodní lišta**: přilepená dole (safe-area), dvě tlačítka 50/50 – vlevo obrysové, vpravo plné `--primary`
- **Časy**: mřížka 4 sloupce, dlaždice 44px
- Dotykové plochy min. 44px, žádné stíny, jemné rámečky
