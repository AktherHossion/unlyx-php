<?php

declare(strict_types=1);

// Use Composer's autoloader when `composer install` has run; otherwise a tiny PSR-4 loader,
// so the suite also runs with a globally installed phpunit.
$autoload = __DIR__ . '/../vendor/autoload.php';
if (is_file($autoload)) {
    require $autoload;
    return;
}

spl_autoload_register(static function (string $class): void {
    foreach (['Unlyx\\Tests\\' => __DIR__ . '/', 'Unlyx\\' => __DIR__ . '/../src/'] as $prefix => $dir) {
        if (str_starts_with($class, $prefix)) {
            $file = $dir . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
            if (is_file($file)) require $file;
            return;
        }
    }
});
