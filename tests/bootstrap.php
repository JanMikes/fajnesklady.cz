<?php

declare(strict_types=1);

use Symfony\Component\Dotenv\Dotenv;

require dirname(__DIR__).'/vendor/autoload.php';

// The method_exists check is technically redundant as bootEnv always exists in modern Symfony
// but keeping it for backwards compatibility in case someone uses an older version
(new Dotenv())->bootEnv(dirname(__DIR__).'/.env');

// Ensure APP_ENV is set to test (PHPUnit sets this in phpunit.xml via <server name="APP_ENV" value="test" force="true" />)
$_ENV['APP_ENV'] = $_SERVER['APP_ENV'] ?? $_ENV['APP_ENV'] ?? 'test';
$_SERVER['APP_ENV'] = $_ENV['APP_ENV'];

if ($_SERVER['APP_DEBUG'] ?? false) {
    umask(0000);
}
