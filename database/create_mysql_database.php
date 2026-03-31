<?php

/**
 * Create the MySQL schema named in .env if it does not exist.
 * Run: php database/create_mysql_database.php
 */

require __DIR__ . '/../vendor/autoload.php';

$app = require_once __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$connection = config('database.connections.mysql');
$database = $connection['database'] ?? '';

if ($connection['driver'] !== 'mysql' || $database === '') {
    fwrite(STDERR, "Set DB_CONNECTION=mysql and DB_DATABASE in .env first.\n");
    exit(1);
}

$dsn = sprintf(
    'mysql:host=%s;port=%s',
    $connection['host'] ?? '127.0.0.1',
    $connection['port'] ?? '3306',
);

$pdo = new PDO(
    $dsn,
    $connection['username'] ?? 'root',
    $connection['password'] ?? '',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
);

$pdo->exec(
    'CREATE DATABASE IF NOT EXISTS `' . str_replace('`', '``', $database) . '` '
    . 'CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci',
);

echo "Database `{$database}` is ready.\n";
