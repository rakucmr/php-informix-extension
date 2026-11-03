<?php

declare(strict_types=1);

/**
 * Autoloader minimal (fără Composer) + instalarea funcțiilor ifx_*.
 *
 * Poate fi inclus direct:
 *
 *     require '/path/to/src/autoload.php';
 *
 * sau prin Composer (composer.json declară acest fișier la "autoload.files").
 */

require_once __DIR__ . '/polyfill.php';

spl_autoload_register(static function (string $class) {
    $prefix = 'Informix\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $relative = substr($class, strlen($prefix));
    $file = __DIR__ . '/Informix/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($file)) {
        require_once $file;
    }
});

require_once __DIR__ . '/compat.php';
