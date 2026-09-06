<?php
// Autoloader minimal pour firebase/php-jwt (lib vendored sans Composer)
spl_autoload_register(function (string $class): void {
    $prefix = 'Firebase\\JWT\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }
    $file = __DIR__ . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($file)) {
        require $file;
    }
});
