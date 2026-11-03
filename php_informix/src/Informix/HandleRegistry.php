<?php

declare(strict_types=1);

namespace Informix;

/**
 * Extensia veche întorcea pentru BLOB/CHAR/SLOB un "resource id" întreg.
 * În PHP 8 nu se mai pot crea resurse din userland, așa că întoarcem tot
 * întregi, ținuți într-un registru static. IDs-urile sunt valide pe durata
 * requestului, exact ca resursele vechi.
 */
final class HandleRegistry
{
    private static $nextId = 1;

    /** @var array<int, object> */
    private static $items = [];

    private function __construct()
    {
    }

    public static function add($handle) {
        $id = self::$nextId++;
        self::$items[$id] = $handle;

        return $id;
    }

    public static function get(int $id)
    {
        return self::$items[$id] ?? null;
    }

    public static function remove(int $id) {
        unset(self::$items[$id]);
    }

    public static function count() {
        return count(self::$items);
    }

    public static function clear() {
        foreach (self::$items as $handle) {
            if ($handle instanceof BlobHandle || $handle instanceof SlobHandle || $handle instanceof CharHandle) {
                $handle->free();
            }
        }
        self::$items = [];
        self::$nextId = 1;
    }
}
