<?php

use Illuminate\Foundation\Application;
use Illuminate\Http\Request;

/**
 * Entry point Vercel PHP Runtime (serverless function).
 *
 * Replika 1:1 dari public/index.php — hanya beda direktori (api/ berada
 * di level yang sama dengan public/, sehingga path absolut tetap ../).
 * Tidak ada logika aplikasi di sini: seluruh request tetap melewati
 * bootstrap/app.php yang sama (middleware, routing, permission).
 *
 * Vercel edge mengirim skema asli via X-Forwarded-Proto; serverless CLI
 * tidak selalu menandai HTTPS=on, jadi kita normalisasi agar Laravel
 * menghasilkan URL https dan cookie secure dengan benar.
 */
define('LARAVEL_START', microtime(true));

if (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https') {
    $_SERVER['HTTPS'] = 'on';
}

// Determine if the application is in maintenance mode...
if (file_exists($maintenance = __DIR__.'/../storage/framework/maintenance.php')) {
    require $maintenance;
}

// Register the Composer autoloader...
require __DIR__.'/../vendor/autoload.php';

// Bootstrap Laravel and handle the request...
/** @var Application $app */
$app = require_once __DIR__.'/../bootstrap/app.php';

$app->handleRequest(Request::capture());
