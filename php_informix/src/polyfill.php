<?php

/**
 * Funcții PHP 8 folosite de bibliotecă, disponibile și pe PHP 7.x.
 * Fișierul este încărcat înaintea autoloader-ului.
 */

if (!function_exists('str_contains')) {
    function str_contains($haystack, $needle)
    {
        return $needle === '' || strpos($haystack, $needle) !== false;
    }
}

if (!function_exists('str_starts_with')) {
    function str_starts_with($haystack, $needle)
    {
        return $needle === '' || strncmp($haystack, $needle, strlen($needle)) === 0;
    }
}

if (!function_exists('str_ends_with')) {
    function str_ends_with($haystack, $needle)
    {
        if ($needle === '') {
            return true;
        }
        $length = strlen($needle);

        return $length <= strlen($haystack) && substr($haystack, -$length) === $needle;
    }
}

if (!function_exists('array_is_list')) {
    function array_is_list(array $array)
    {
        $index = 0;
        foreach ($array as $key => $value) {
            if ($key !== $index++) {
                return false;
            }
        }

        return true;
    }
}
