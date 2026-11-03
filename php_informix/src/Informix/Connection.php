<?php

declare(strict_types=1);

namespace Informix;

/**
 * O conexiune Informix, ținută peste un obiect PDO cu driverul "informix".
 *
 * ifx_connect("baza@server", "user", "parola") este tradus în DSN-ul
 * acceptat de pdo_informix:
 *
 *     informix:server=server;database=baza;host=...;service=...;protocol=...;
 *
 * host/service/protocol sunt opționale: dacă lipsesc, driverul rezolvă
 * serverul prin sqlhosts (INFORMIXSQLHOSTS / INFORMIXDIR/etc/sqlhosts).
 */
final class Connection
{
    /** @var array<string, self> */
    private static $cache = [];

    private $pdo;

    private $closed = false;

    private $lastError = null;

    private $cacheKey = '';

    public $dsn = '';

    public $database = '';

    public $server = '';

    public $user = '';

    public $persistent = false;

    private function __construct()
    {
    }

    /**
     * Deschide (sau refolosește, ca extensia veche) o conexiune.
     *
     * @throws \PDOException             dacă serverul refuză conexiunea
     * @throws \InvalidArgumentException dacă argumentele/mediul nu permit construirea DSN-ului
     */
    public static function connect(
        $database = null,
        $user = null,
        $password = null,
        bool $persistent = false
    ) {
        list($dsn, $db, $server) = self::buildDsn($database);

        if ($persistent && !Config::get('allow_persistent')) {
            $persistent = false;
        }

        if ($user === null) { $user = Config::get('default_user'); }
        if ($password === null) { $password = Config::get('default_password'); }
        $user = $user === null ? '' : (string) $user;
        $password = $password === null ? '' : (string) $password;

        $cacheKey = ($persistent ? 'p:' : 'n:') . $dsn . '|' . $user . '|' . $password;

        if (isset(self::$cache[$cacheKey]) && !self::$cache[$cacheKey]->isClosed()) {
            return self::$cache[$cacheKey];
        }
        unset(self::$cache[$cacheKey]);

        self::checkLimits($persistent);

        $options = [
            \PDO::ATTR_ERRMODE            => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            \PDO::ATTR_STRINGIFY_FETCHES  => true,
            \PDO::ATTR_CASE               => \PDO::CASE_NATURAL,
            \PDO::ATTR_ORACLE_NULLS       => \PDO::NULL_NATURAL,
        ];
        if ($persistent) {
            $options[\PDO::ATTR_PERSISTENT] = true;
        }
        $timeout = Config::get('connect_timeout');
        if ($timeout !== null) {
            $options[\PDO::ATTR_TIMEOUT] = (int) $timeout;
        }

        $conn = new self();
        $conn->pdo = new \PDO($dsn, $user, $password, $options);
        $conn->dsn = $dsn;
        $conn->database = $db;
        $conn->server = $server;
        $conn->user = $user;
        $conn->persistent = $persistent;
        $conn->cacheKey = $cacheKey;

        self::$cache[$cacheKey] = $conn;

        return $conn;
    }

    public function pdo() {
        if ($this->closed) {
            throw new \RuntimeException('Conexiunea Informix a fost închisă');
        }

        return $this->pdo;
    }

    /**
     * @throws \PDOException
     */
    public function prepare(string $sql, bool $scroll = false) {
        $options = $scroll ? [\PDO::ATTR_CURSOR => \PDO::CURSOR_SCROLL] : [];

        return $this->pdo()->prepare($sql, $options);
    }

    /**
     * @throws \PDOException
     */
    public function exec(string $sql) {
        $result = $this->pdo()->exec($sql);

        return $result === false ? 0 : $result;
    }

    public function lastInsertId() {
        try {
            return (string) $this->pdo()->lastInsertId();
        } catch (\Throwable $e) {
            return '0';
        }
    }

    public function beginTransaction() {
        return $this->pdo()->beginTransaction();
    }

    public function commit() {
        return $this->pdo()->commit();
    }

    public function rollBack() {
        return $this->pdo()->rollBack();
    }

    public function inTransaction() {
        return $this->pdo()->inTransaction();
    }

    public function close() {
        if ($this->closed) {
            return true;
        }
        $this->closed = true;
        unset(self::$cache[$this->cacheKey]);
        // PDOStatement-urile încă vii păstrează o referință la handle-ul nativ;
        // obiectul PDO se eliberează când dispar și ele.
        unset($this->pdo);

        return true;
    }

    public function isClosed() {
        return $this->closed;
    }

    public function setError(Error $error) {
        $this->lastError = $error;
    }

    public function lastError() {
        return $this->lastError;
    }

    public function clearError() {
        $this->lastError = null;
    }

    public static function closeAll() {
        foreach (self::$cache as $connection) {
            $connection->close();
        }
        self::$cache = [];
    }

    public static function cachedCount(bool $persistent) {
        $prefix = $persistent ? 'p:' : 'n:';
        $count = 0;
        foreach (self::$cache as $key => $connection) {
            if (str_starts_with($key, $prefix) && !$connection->isClosed()) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * @return array{0: string, 1: string, 2: string}
     */
    private static function buildDsn($database) {
        $database = trim((string) $database);
        if ($database === '') {
            $database = trim((string) Config::get('default_database'));
        }
        if ($database === '') {
            throw new \InvalidArgumentException(
                'Lipsește baza de date: ifx_connect("baza@server") sau IFX_DEFAULT_DATABASE'
            );
        }

        if (stripos($database, 'informix:') === 0 || stripos($database, 'odbc:') === 0) {
            $dsn = $database;
            $db = '';
            $server = '';
            if (preg_match('/database\s*=\s*([^;]+)/i', $dsn, $m) === 1) {
                $db = trim($m[1]);
            }
            if (preg_match('/server\s*=\s*([^;]+)/i', $dsn, $m) === 1) {
                $server = trim($m[1]);
            }

            if (stripos($dsn, 'odbc:') === 0) {
                return [$dsn, $db, $server];
            }
            if (self::driverName() === 'odbc') {
                return [self::odbcDsn($server, $db), $db, $server];
            }

            return [$dsn, $db, $server];
        }

        $server = null;
        if (strpos($database, '@') !== false) {
            list($db, $server) = explode('@', $database, 2);
            $db = trim($db);
            $server = trim($server);
        } else {
            $db = $database;
        }

        if ($server === null || $server === '') {
            $server = (string) (Config::get('default_host') ?: getenv('INFORMIXSERVER') ?: '');
        }

        if ($db === '') {
            throw new \InvalidArgumentException('Lipsește numele bazei de date din "baza@server"');
        }
        if ($server === '') {
            throw new \InvalidArgumentException(
                'Lipsește serverul: folosește "baza@server", IFX_DEFAULT_HOST sau INFORMIXSERVER'
            );
        }

        if (self::driverName() === 'odbc') {
            return [self::odbcDsn($server, $db), $db, $server];
        }

        $parts = ['server=' . $server, 'database=' . $db];
        $optional = [
            'dsn_host'     => 'host',
            'dsn_service'  => 'service',
            'dsn_protocol' => 'protocol',
        ];
        foreach ($optional as $option => $parameter) {
            $value = Config::get($option);
            if ($value !== null && $value !== '') {
                $parts[] = $parameter . '=' . $value;
            }
        }

        return ['informix:' . implode(';', $parts) . ';', $db, $server];
    }

    /**
     * Driverul PDO folosit: pdo_informix dacă există, altfel pdo_odbc.
     */
    private static function driverName() {
        static $driver = null;
        if ($driver !== null) {
            return $driver;
        }

        $available = \PDO::getAvailableDrivers();
        if (in_array('informix', $available, true)) {
            return $driver = 'informix';
        }
        if (Config::get('use_odbc') && in_array('odbc', $available, true)) {
            return $driver = 'odbc';
        }

        throw new \RuntimeException(
            'Nu există driverul pdo_informix. Instalați pdo_informix sau setați IFX_USE_ODBC=1 ' .
            'pentru fallback-ul experimental PDO ODBC.'
        );
    }

    private static function odbcDsn(string $server, string $database) {
        $driver = (string) (Config::get('odbc_driver') ?: getenv('IFX_ODBC_DRIVER') ?: self::defaultOdbcDriver());
        list($host, $service) = self::odbcEndpoint($server);

        $parts = [
            'DRIVER={' . $driver . '}',
            'HOST=' . $host,
            'SERVICE=' . $service,
            'SERVER=' . $server,
            'DATABASE=' . $database,
        ];

        foreach (['db_locale' => 'DB_LOCALE', 'client_locale' => 'CLIENT_LOCALE'] as $option => $key) {
            $value = Config::get($option);
            if ($value !== null && $value !== '') {
                $parts[] = $key . '=' . $value;
            }
        }

        return 'odbc:' . implode(';', $parts) . ';';
    }

    private static function defaultOdbcDriver() {
        return DIRECTORY_SEPARATOR === '\\'
            ? 'IBM INFORMIX ODBC DRIVER (64-bit)'
            : 'IBM INFORMIX ODBC DRIVER';
    }

    /**
     * @return array{0: string, 1: string}
     */
    private static function odbcEndpoint(string $server) {
        $host = (string) (Config::get('odbc_host') ?: Config::get('dsn_host') ?: getenv('IFX_HOST') ?: '');
        $service = (string) (Config::get('odbc_service') ?: Config::get('dsn_service') ?: getenv('IFX_SERVICE') ?: '');

        if ($host !== '' && $service !== '') {
            return [$host, $service];
        }

        $file = getenv('INFORMIXSQLHOSTS');
        if ($file === false || $file === '') {
            $informixDir = getenv('INFORMIXDIR');
            if ($informixDir !== false && $informixDir !== '') {
                $file = rtrim($informixDir, '\\/') . DIRECTORY_SEPARATOR . 'etc' . DIRECTORY_SEPARATOR . 'sqlhosts';
            }
        }
        if ($file !== false && $file !== '' && is_file($file)) {
            foreach (file($file) as $line) {
                $line = trim($line);
                if ($line === '' || $line[0] === '#') {
                    continue;
                }
                $fields = preg_split('/\s+/', $line);
                if (count($fields) >= 4 && $fields[0] === $server) {
                    if ($host === '') {
                        $host = $fields[2];
                    }
                    if ($service === '') {
                        $service = $fields[3];
                    }
                    break;
                }
            }
        }

        if ($host === '' || $service === '') {
            throw new \InvalidArgumentException(
                'Fallback-ul ODBC are nevoie de HOST și SERVICE: setați IFX_HOST/IFX_SERVICE sau introduceți-le în sqlhosts'
            );
        }

        return [$host, $service];
    }

    private static function checkLimits(bool $persistent) {
        $max = (int) Config::get($persistent ? 'max_persistent' : 'max_links');
        if ($max < 0) {
            return;
        }
        $count = self::cachedCount($persistent);
        if ($count >= $max) {
            $label = $persistent ? 'persistente' : 'normale';
            throw new \RuntimeException("Prea multe conexiuni {$label} deschise ({$count})");
        }
    }
}
