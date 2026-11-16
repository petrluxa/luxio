#!/usr/bin/env bash
# Spustí rozbalovač na serveru a volá ho opakovaně, dokud neohlásí HOTOVO.
#
#   nastroje/rozbal-na-serveru.sh https://example.com          (adresa složky webu, kam se nahrál balíček)
#
# Klíč si přečte z názvu souboru _nasazeni/rozbal-<klíč>.php a nikam ho nevypisuje.
set -euo pipefail

base="${1:?Zadejte adresu složky webu, např. https://example.com}"
dir="$(cd "$(dirname "$0")/.." && pwd)/_nasazeni"
file="$(basename "$(ls "$dir"/rozbal-*.php | head -1)")"
token="${file#rozbal-}"
token="${token%.php}"
from=0

for attempt in $(seq 1 400); do
    out="$(curl -sS --max-time 120 "${base%/}/${file}?t=${token}&krok=rozbal&od=${from}")"
    last="$(printf '%s\n' "$out" | tail -1)"

    case "$last" in
        HOTOVO)
            printf '%s\n' "$out" | grep -v '^$' | tail -16
            exit 0
            ;;
        "DALSI od="*)
            from="${last#DALSI od=}"
            printf '%s\n' "$out" | tail -2 | head -1
            ;;
        *)
            echo "Rozbalovač odpověděl nečekaně:"
            printf '%s\n' "$out" | tail -8
            exit 1
            ;;
    esac
done

echo "Rozbalení se nedokončilo ani po 400 voláních."
exit 1
