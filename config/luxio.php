<?php

return [

    /*
    | Fronta (push upozornění) vyřizovaná plánovačem – pro hosting bez trvale
    | běžícího procesu. Kde běží `queue:work` pod supervizorem, nastavte false.
    */
    'queue_via_scheduler' => (bool) env('QUEUE_VIA_SCHEDULER', true),

    /*
    | Hosting bez příkazové řádky (bez SSH a bez cronu)
    |--------------------------------------------------------------------------
    | `setup_key` – tajná část adresy /instalace/{klíč}: spustí migrace databáze
    |               a při první instalaci založí salon. Prázdné = adresa neexistuje.
    | `cron_key`  – tajná část adresy /cron/{klíč}, kterou po několika minutách volá
    |               časovač hostingu (připomínky, uzavírání proběhlých termínů, fronta).
    |               Prázdné = adresa neexistuje (použije se běžný cron a schedule:run).
    */
    'setup_key' => env('LUXIO_SETUP_KEY'),
    'cron_key' => env('LUXIO_CRON_KEY'),

    /*
    | Jak dlouho zpětně se ještě pošle připomínka (minuty). Musí být delší než
    | rozestup, ve kterém se připomínky spouštějí – jinak by některé vypadly.
    */
    'reminder_window_minutes' => (int) env('REMINDER_WINDOW_MINUTES', 10),

    /*
    | Web za proxy nebo CDN: komu věřit hlavičky se skutečnou adresou návštěvníka
    | (limity se počítají podle IP adresy). `*` = všem, nebo seznam adres oddělený čárkou.
    */
    'trusted_proxies' => env('TRUSTED_PROXIES'),

];
