<?php

/**
 * Založí soubor .env.nasazeni – nastavení aplikace pro server (není v gitu).
 *
 *   php nastroje/priprav-env.php https://example.com/luxio
 *
 * Vygeneruje klíč aplikace, klíče pro push upozornění a tajné klíče adres /instalace/… a /cron/….
 * Přístupy k databázi (DB_…) do souboru doplňte ručně. Nic tajného nevypisuje.
 */
$root = dirname(__DIR__);
$file = $root.'/.env.nasazeni';
$url = rtrim((string) ($argv[1] ?? ''), '/');

if (! preg_match('#^https://[^/]+(/.*)?$#', $url, $m)) {
    fwrite(STDERR, "Zadejte adresu aplikace včetně https://, např.: php nastroje/priprav-env.php https://example.com/luxio\n");
    exit(1);
}

if (is_file($file)) {
    fwrite(STDERR, "Soubor .env.nasazeni už existuje – nechávám ho být (obsahuje klíče, které se nesmí změnit).\n");
    exit(1);
}

$base64url = fn (string $bytes) => rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
$secret = fn (int $bytes = 30) => $base64url(random_bytes($bytes));

// Klíče pro push (VAPID): pár na křivce P-256 ve tvaru, který čeká balíček webpush –
// veřejný klíč jako nekomprimovaný bod (65 bajtů), soukromý jako 32 bajtů, obojí base64url.
// Na Windows potřebuje OpenSSL výslovně cestu ke svému nastavení.
$options = ['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC];

if (PHP_OS_FAMILY === 'Windows' && is_file($root.'/_php/extras/ssl/openssl.cnf')) {
    $options['config'] = realpath($root.'/_php/extras/ssl/openssl.cnf');
}

$key = openssl_pkey_new($options);
$ec = $key ? (openssl_pkey_get_details($key)['ec'] ?? null) : null;

if (! $ec) {
    fwrite(STDERR, "Klíče pro push se nepodařilo vygenerovat (OpenSSL).\n");
    exit(1);
}

// Klíč aplikace musí být v běžném base64 (ne v „url“ variantě), jinak ho Laravel odmítne.
$appKey = base64_encode(random_bytes(32));

$pad = fn (string $bytes) => str_pad($bytes, 32, "\0", STR_PAD_LEFT);
$vapid = [
    'publicKey' => $base64url("\x04".$pad($ec['x']).$pad($ec['y'])),
    'privateKey' => $base64url($pad($ec['d'])),
];
$path = rtrim((string) ($m[1] ?? ''), '/') ?: '/';

file_put_contents($file, <<<ENV
APP_NAME=Luxio
APP_ENV=production
APP_KEY=base64:{$appKey}
APP_DEBUG=false
APP_URL={$url}
APP_TIMEZONE=Europe/Prague
APP_LOCALE=cs
APP_FALLBACK_LOCALE=en

LOG_CHANNEL=stack
LOG_STACK=single
LOG_LEVEL=warning

# Databáze – doplňte údaje z administrace hostingu.
DB_CONNECTION=mysql
DB_HOST=
DB_PORT=3306
DB_DATABASE=
DB_USERNAME=
DB_PASSWORD=
# Sdílená databáze: tabulky s předponou, ať se nepletou s jinou aplikací.
DB_PREFIX=luxio_

SESSION_DRIVER=database
SESSION_LIFETIME=120
SESSION_PATH={$path}
SESSION_SECURE_COOKIE=true
CACHE_STORE=database

# Bez trvale běžícího procesu: upozornění odchází hned při události.
QUEUE_CONNECTION=sync
MAIL_MAILER=log

# Push upozornění. VAPID_SUBJECT je kontakt na provozovatele pro služby, které upozornění doručují.
VAPID_SUBJECT={$url}
VAPID_PUBLIC_KEY={$vapid['publicKey']}
VAPID_PRIVATE_KEY={$vapid['privateKey']}

# Tajné části adres /instalace/{klíč} a /cron/{klíč}.
LUXIO_SETUP_KEY={$secret()}
LUXIO_CRON_KEY={$secret()}

# Zapnout JEN tehdy, když kontrola serveru (rozbalovač) ukáže hlavičku X-Forwarded-For a jako adresu
# návštěvníka adresu proxy. Když server předává skutečnou adresu sám, nechat vypnuté – jinak by si
# návštěvník mohl adresu podvrhnout a obejít limity.
# TRUSTED_PROXIES=*

ENV);

echo "Soubor .env.nasazeni je založený. Doplňte do něj přístupy k databázi (řádky DB_…).\n";
