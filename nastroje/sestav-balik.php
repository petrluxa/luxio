<?php

/**
 * Sestaví balíček pro nahrání na sdílený hosting bez příkazové řádky
 * (viz README – „Podsložka webu a hosting bez příkazové řádky“).
 *
 *   php nastroje/sestav-balik.php             první nasazení (včetně .env ze souboru .env.nasazeni)
 *   php nastroje/sestav-balik.php --bez-env   aktualizace (nastavení na serveru zůstane, jak je)
 *
 * Výsledek ve složce _nasazeni/:
 *   luxio-balik.zip       celá aplikace v rozložení pro server (veřejná část + podsložka _app)
 *   rozbal-<token>.php    rozbalovač: zkontroluje server, rozbalí balíček do podsložky „luxio“
 *                         a smaže balíček i sám sebe
 *
 * Oba soubory se nahrají do složky webu a otevře se adresa  …/rozbal-<token>.php?t=<token>.
 * Před sestavením musí proběhnout `npm run build`.
 */
$root = dirname(__DIR__);
$out = $root.'/_nasazeni';
$stage = $out.'/luxio';
$app = $stage.'/_app';
$withEnv = ! in_array('--bez-env', $argv, true);

$fail = function (string $message): never {
    fwrite(STDERR, "CHYBA: {$message}\n");
    exit(1);
};

$rrmdir = function (string $dir) use (&$rrmdir): void {
    if (! is_dir($dir)) {
        return;
    }

    foreach (scandir($dir) as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }

        $path = $dir.'/'.$item;
        is_dir($path) && ! is_link($path) ? $rrmdir($path) : unlink($path);
    }

    rmdir($dir);
};

/** Zkopíruje složku; $skip dostane cestu relativně ke zdroji a vrátí true pro vynechání. */
$copy = function (string $from, string $to, ?callable $skip = null) use (&$copy, $root): void {
    @mkdir($to, 0777, true);

    foreach (scandir($from) as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }

        $source = $from.'/'.$item;
        $relative = ltrim(str_replace('\\', '/', substr($source, strlen($root))), '/');

        if (is_link($source) || ($skip && $skip($relative))) {
            continue;
        }

        is_dir($source) ? $copy($source, $to.'/'.$item, $skip) : copy($source, $to.'/'.$item);
    }
};

if (! is_file($root.'/public/build/manifest.json')) {
    $fail('chybí sestavené styly a skripty – spusťte nejdřív `npm run build`.');
}

if ($withEnv && ! is_file($root.'/.env.nasazeni')) {
    $fail('chybí soubor .env.nasazeni s nastavením pro server (vytvoří ho nastroje/priprav-env.php).');
}

echo "Připravuji složku…\n";
$rrmdir($stage);
array_map('unlink', glob($out.'/rozbal-*.php') ?: []);
@unlink($out.'/luxio-balik.zip');
@mkdir($app, 0777, true);

// 1) Veřejná část: obsah public/ přímo do složky webu.
$copy($root.'/public', $stage, fn (string $path) => in_array($path, ['public/hot', 'public/storage'], true));

// 2) Aplikace do podsložky _app (z internetu nedostupná).
foreach (['app', 'config', 'lang', 'routes'] as $dir) {
    $copy($root.'/'.$dir, $app.'/'.$dir);
}

$copy($root.'/bootstrap', $app.'/bootstrap', fn (string $path) => str_starts_with($path, 'bootstrap/cache/'));
$copy($root.'/database', $app.'/database', fn (string $path) => str_contains($path, '.sqlite'));
$copy($root.'/resources/views', $app.'/resources/views');

foreach (['artisan', 'composer.json', 'composer.lock'] as $file) {
    copy($root.'/'.$file, $app.'/'.$file);
}

foreach (['bootstrap/cache', 'storage/app/private', 'storage/app/public', 'storage/framework/cache/data', 'storage/framework/sessions', 'storage/framework/views', 'storage/logs'] as $dir) {
    @mkdir($app.'/'.$dir, 0777, true);
}

// 3) Knihovny bez vývojářských nástrojů.
echo "Instaluji knihovny (bez vývojářských)…\n";
$composer = is_file($root.'/_php/composer.phar') ? escapeshellarg(PHP_BINARY).' '.escapeshellarg($root.'/_php/composer.phar') : 'composer';
passthru($composer.' install --no-dev --optimize-autoloader --no-interaction --no-progress --quiet --working-dir='.escapeshellarg($app), $code);

if ($code !== 0 || ! is_file($app.'/vendor/autoload.php')) {
    $fail('instalace knihoven se nepovedla.');
}

// 4) Podsložka s aplikací je zvenku zakázaná (druhá pojistka je pravidlo v .htaccess o úroveň výš).
file_put_contents($app.'/.htaccess', <<<'HTACCESS'
<IfModule mod_authz_core.c>
    Require all denied
</IfModule>
<IfModule !mod_authz_core.c>
    Order deny,allow
    Deny from all
</IfModule>

HTACCESS);

if ($withEnv) {
    copy($root.'/.env.nasazeni', $app.'/.env');
}

// 5) Balíček. Cesty v něm musí mít obyčejná lomítka, jinak je Linux nerozbalí do složek.
echo "Balím…\n";
$zip = new ZipArchive;

if ($zip->open($out.'/luxio-balik.zip', ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    $fail('balíček se nepodařilo vytvořit.');
}

$files = 0;
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($stage, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);

foreach ($iterator as $item) {
    $relative = str_replace('\\', '/', substr($item->getPathname(), strlen($stage) + 1));

    if ($item->isDir()) {
        $zip->addEmptyDir($relative);
    } else {
        $zip->addFile($item->getPathname(), $relative);
        $files++;
    }
}

$zip->close();

// 6) Rozbalovač s jednorázovým klíčem.
$token = bin2hex(random_bytes(16));
$extractor = str_replace('__TOKEN__', $token, file_get_contents(__DIR__.'/rozbal.php.txt'));
file_put_contents($out."/rozbal-{$token}.php", $extractor);

$rrmdir($stage);

printf("Hotovo: %d souborů, balíček %.1f MB%s.\n", $files, filesize($out.'/luxio-balik.zip') / 1048576, $withEnv ? ' (včetně .env)' : ' (bez .env)');
echo "Nahrát do složky webu:  _nasazeni/luxio-balik.zip  a  _nasazeni/rozbal-{$token}.php\n";
echo "Pak otevřít:            …/rozbal-{$token}.php?t={$token}\n";
