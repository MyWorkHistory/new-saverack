<?php

/**
 * Laravel router for PHP's built-in web server (php -S).
 * Some local setups (XAMPP / IDE) may point at this file in the project root.
 */

$uri = urldecode(
    parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH)
);

if ($uri !== '/' && file_exists(__DIR__.'/public'.$uri)) {
    return false;
}

require_once __DIR__.'/public/index.php';
