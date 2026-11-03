<?php

declare(strict_types=1);

namespace Informix;

/**
 * Configurarea globală a stratului de compatibilitate.
 *
 * Extensia veche își lua opțiunile din php.ini (ifx.blobinfile, ifx.textasvarchar
 * etc.). Aici nu există o extensie C care să înregistreze directive INI, așa că
 * aceleași opțiuni se pot seta în trei feluri:
 *
 *   1. variabile de mediu (IFX_BLOBINFILE=1 etc.), citite o singură dată;
 *   2. programatic: Config::set('blobinfile', 1);
 *   3. prin funcțiile legacy ifx_blobinfile_mode(), ifx_textasvarchar() etc.
 *
 * Numele pot fi scrise și în forma veche, cu prefix: Config::get('ifx.nullformat').
 */
final class Config
{
    /** @var array<string, mixed> */
    const DEFAULTS = [
        // echivalentele directivelor php.ini din ext/informix
        'blobinfile'       => 0,      // 0 = blob-urile citite rămân în memorie, 1 = în fișier temporar
        'textasvarchar'    => 0,      // 1 = coloanele TEXT se întorc ca string, nu ca blob id
        'byteasvarchar'    => 0,      // 1 = coloanele BYTE se întorc ca string, nu ca blob id
        'charasvarchar'    => 0,      // 1 = se taie spațiile de la finalul coloanelor CHAR
        'nullformat'       => 0,      // 0 = NULL devine "", 1 = NULL devine "NULL"
        'default_host'     => null,   // serverul Informix implicit (în extensia veche: ifx.default_host)
        'default_user'     => null,
        'default_password' => null,
        'default_database' => null,
        'allow_persistent' => true,   // ifx.allow_persistent
        'max_links'        => -1,     // ifx.max_links
        'max_persistent'   => -1,     // ifx.max_persistent

        // opțiuni proprii ale stratului modern
        'dsn_host'         => null,   // host= din DSN (altfel se folosește sqlhosts/INFORMIXSQLHOSTS)
        'dsn_service'      => null,   // service= din DSN
        'dsn_protocol'     => null,   // protocol= din DSN
        'odbc_driver'      => null,   // fallback PDO ODBC când pdo_informix lipsește
        'odbc_host'        => null,   // host pentru fallback-ul ODBC
        'odbc_service'     => null,   // service/port pentru fallback-ul ODBC
        'use_odbc'         => false,  // fallback-ul ODBC este opt-in (metadatele sunt limitate)
        'db_locale'        => null,   // DB_LOCALE transmis driverului ODBC
        'client_locale'    => null,   // CLIENT_LOCALE transmis driverului ODBC
        'htmltbl_escape'   => false,  // ifx_htmltbl_result escapează HTML (implicit: ca extensia veche, nu)
        'connect_timeout'  => null,   // opțional, transmis ca PDO::ATTR_TIMEOUT
    ];

    /** @var array<string, mixed> */
    private static $values = [];

    private static $booted = false;

    private function __construct()
    {
    }

    public static function get(string $name)
    {
        self::boot();
        $name = self::normalizeName($name);

        return self::$values[$name] ?? null;
    }

    public static function set(string $name, $value) {
        self::boot();
        $name = self::normalizeName($name);
        if (!array_key_exists($name, self::DEFAULTS)) {
            throw new \InvalidArgumentException("Opțiune ifx necunoscută: {$name}");
        }
        self::$values[$name] = self::normalizeValue($name, $value);
    }

    /** Resetează configurația la valorile implicite + variabilele de mediu. */
    public static function reset() {
        self::$values = [];
        self::$booted = false;
    }

    /** @return array<string, mixed> */
    public static function all() {
        self::boot();

        return self::$values;
    }

    public static function nullValue() {
        return self::get('nullformat') ? 'NULL' : '';
    }

    private static function normalizeName(string $name) {
        $name = strtolower(trim($name));
        if (str_starts_with($name, 'ifx.')) {
            $name = substr($name, 4);
        }

        return $name;
    }

    private static function normalizeValue(string $name, $value)
    {
        switch ($name) {
            case 'blobinfile':
            case 'textasvarchar':
            case 'byteasvarchar':
            case 'charasvarchar':
            case 'nullformat':
            case 'max_links':
            case 'max_persistent':
                return (int) $value;
            case 'allow_persistent':
            case 'htmltbl_escape':
            case 'use_odbc':
                return self::toBool($value);
            case 'connect_timeout':
                return $value === null ? null : (int) $value;
            default:
                return $value === null ? null : (string) $value;
        }
    }

    private static function toBool($value) {
        if (is_string($value)) {
            return !in_array(strtolower(trim($value)), ['', '0', 'false', 'no', 'off'], true);
        }

        return (bool) $value;
    }

    private static function boot() {
        if (self::$booted) {
            return;
        }
        self::$booted = true;
        self::$values = self::DEFAULTS;

        $env = static function ($key) {
            $v = getenv($key);

            return $v === false || $v === '' ? null : $v;
        };

        $map = [
            'blobinfile'       => ['IFX_BLOBINFILE'],
            'textasvarchar'    => ['IFX_TEXTASVARCHAR'],
            'byteasvarchar'    => ['IFX_BYTEASVARCHAR'],
            'charasvarchar'    => ['IFX_CHARASVARCHAR'],
            'nullformat'       => ['IFX_NULLFORMAT'],
            'allow_persistent' => ['IFX_ALLOW_PERSISTENT'],
            'default_host'     => ['IFX_DEFAULT_HOST', 'INFORMIXSERVER'],
            'default_user'     => ['IFX_DEFAULT_USER', 'INFORMIXUSER'],
            'default_password' => ['IFX_DEFAULT_PASSWORD', 'INFORMIXPASSWORD'],
            'default_database' => ['IFX_DEFAULT_DATABASE', 'INFORMIXDATABASE'],
            'dsn_host'         => ['IFX_HOST'],
            'dsn_service'      => ['IFX_SERVICE'],
            'dsn_protocol'     => ['IFX_PROTOCOL'],
            'odbc_driver'      => ['IFX_ODBC_DRIVER'],
            'odbc_host'        => ['IFX_ODBC_HOST'],
            'odbc_service'     => ['IFX_ODBC_SERVICE'],
            'use_odbc'         => ['IFX_USE_ODBC'],
            'db_locale'        => ['DB_LOCALE'],
            'client_locale'    => ['CLIENT_LOCALE'],
            'htmltbl_escape'   => ['IFX_HTMLTBL_ESCAPE'],
            'connect_timeout'  => ['IFX_CONNECT_TIMEOUT'],
        ];

        foreach ($map as $option => $variables) {
            foreach ($variables as $variable) {
                $value = $env($variable);
                if ($value !== null) {
                    self::$values[$option] = self::normalizeValue($option, $value);
                    break;
                }
            }
        }
    }
}
