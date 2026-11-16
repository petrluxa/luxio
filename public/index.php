<?php

use Illuminate\Foundation\Application;
use Illuminate\Http\Request;

define('LARAVEL_START', microtime(true));

// Kde leží aplikace:
//  - běžně o složku výš (kořen webu míří do `public/`),
//  - na sdíleném hostingu bez vlastního kořene webu v podsložce `_app` vedle tohoto
//    souboru (obsah `public/` je nahraný přímo do složky webu, viz README – Nasazení).
$base = is_file(__DIR__.'/_app/bootstrap/app.php') ? __DIR__.'/_app' : dirname(__DIR__);

// Determine if the application is in maintenance mode...
if (file_exists($maintenance = $base.'/storage/framework/maintenance.php')) {
    require $maintenance;
}

// Register the Composer autoloader...
require $base.'/vendor/autoload.php';

// Bootstrap Laravel and handle the request...
/** @var Application $app */
$app = require_once $base.'/bootstrap/app.php';

if ($base !== dirname(__DIR__)) {
    $app->usePublicPath(__DIR__);
}

$app->handleRequest(Request::capture());
