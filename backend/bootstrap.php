<?php

declare(strict_types=1);

/**
 * Loads everything the API and the command-line scripts in bin/ need: the class autoloader
 * (StudentVoice\Foo\Bar -> src/Foo/Bar.php) and the settings in backend/.env.
 */

spl_autoload_register(function (string $class): void {
    $prefix = 'StudentVoice\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $file = __DIR__ . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

StudentVoice\Config::load(__DIR__ . '/.env');

date_default_timezone_set((string) StudentVoice\Config::get('APP_TIMEZONE', 'Africa/Kampala'));
