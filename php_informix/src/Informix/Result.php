<?php

declare(strict_types=1);

namespace Informix;

/**
 * Rezultatul unei interogări (echivalentul "result id" din extensia veche).
 *
 * Ține PDOStatement-ul viu cât timp e nevoie, pentru că pdo_informix livrează
 * LOB-urile ca stream-uri care depind de statement. Rândurile sunt procesate
 * ca în ext/informix: toate valorile scalare devin string-uri, NULL devine
 * "" sau "NULL" (ifx.nullformat), iar TEXT/BYTE/BLOB/CLOB devin blob id-uri
 * dacă nu sunt activate ifx_textasvarchar()/ifx_byteasvarchar().
 */
final class Result
{
    private $stmt = null;

    private $executed = false;

    private $freed = false;

    private $fetched = 0;

    private $affected = 0;

    private $serial = null;

    /** @var list<array{name: string, native: string, len: int, precision: int, scale: int, not_null: bool}>|null */
    private $meta = null;

    /** @var list<mixed>|null */
    private $params;

    private $lastError = null;

    /** Ultimul rând brut (numeric), pentru ifx_htmltbl_result(). */
    private $lastRaw = [];

    private $connection;

    public $sql;

    private $cursorFlags;

    public function __construct(
        Connection $connection,
        string $sql,
        int $cursorFlags = 0,
        $params = null
    ) {
        $this->connection = $connection;
        $this->sql = $sql;
        $this->cursorFlags = $cursorFlags;
        $this->params = $params;
    }

    /**
     * Prepare + execute (ifx_query).
     *
     * @throws \PDOException
     */
    public static function query(
        Connection $connection,
        string $sql,
        int $cursorFlags = 0,
        $params = null
    ) {
        $result = self::prepare($connection, $sql, $cursorFlags, $params);
        $result->execute();

        return $result;
    }

    /**
     * Doar prepare (ifx_prepare); execuția se face cu ifx_do()/execute().
     *
     * @throws \PDOException
     */
    public static function prepare(
        Connection $connection,
        string $sql,
        int $cursorFlags = 0,
        $params = null
    ) {
        $result = new self($connection, $sql, $cursorFlags, $params);
        $scroll = ($cursorFlags & 1) !== 0 && self::looksLikeSelect($sql);
        $result->stmt = $connection->prepare($sql, $scroll);

        return $result;
    }

    /**
     * Execută statement-ul pregătit (ifx_do).
     *
     * @throws \PDOException
     */
    public function execute() {
        if ($this->freed || $this->stmt === null) {
            throw new \RuntimeException('Rezultatul Informix a fost eliberat');
        }

        try {
            if ($this->executed) {
                $this->stmt->closeCursor();
            }
            if ($this->params !== null) {
                foreach (array_values($this->params) as $index => $value) {
                    $this->bindParameter($index + 1, $value);
                }
            }
            if ($this->stmt->execute() === false) {
                $info = $this->stmt->errorInfo();
                throw new \PDOException((string) ($info[2] ?? 'Execute failed'));
            }
        } catch (\PDOException $e) {
            $this->lastError = Error::fromThrowable($e, 'execute');
            $this->connection->setError($this->lastError);
            throw $e;
        }

        $this->executed = true;
        $this->fetched = 0;
        $this->meta = null;
        $this->affected = $this->stmt->rowCount();
        $this->serial = self::looksLikeInsert($this->sql) ? $this->connection->lastInsertId() : null;
        // pdo_informix întoarce rowCount() = 0 când statement-ul are parametri
        // LOB (driverul nu mai poate citi SQLRowCount). SQLCA păstrează însă
        // numărul real de rânduri, exact ca sqlerrd[2] din extensia veche.
        if ($this->affected === 0 && self::looksLikeDml($this->sql)) {
            $this->affected = $this->readSqlcaRowCount();
        }
        $this->lastError = null;
        $this->connection->clearError();

        return true;
    }

    /**
     * ifx_fetch_row(): rândul următor sau rândul cerut, într-un array asociativ.
     */
    public function fetch($position = null) {
        $raw = $this->fetchRaw($position);
        if ($raw === false) {
            return false;
        }

        $meta = $this->metadata();
        if ($meta === []) {
            return false;
        }

        $row = [];
        foreach ($meta as $index => $column) {
            $row[$column['name']] = $this->convertValue($raw[$index] ?? null, $column);
        }

        return $row;
    }

    /**
     * Toate rândurile rămase (extensie modernă, nu exista în API-ul vechi).
     *
     * @return list<array<string, mixed>>
     */
    public function fetchAll($position = null) {
        $rows = [];
        while (($row = $this->fetch($position)) !== false) {
            $rows[] = $row;
            $position = null;
        }

        return $rows;
    }

    /**
     * ifx_htmltbl_result(): tipărește toate rândurile rămase ca tabel HTML.
     */
    public function htmlTable($options = null) {
        if (!$this->isSelect()) {
            $this->lastError = new Error('HY000', -1, 'Not a select cursor', null, 'htmltbl');

            return false;
        }

        $meta = $this->metadata();
        $first = $this->fetchRaw();
        if ($first === false) {
            if ($this->isEndOfData()) {
                echo "<h2>No rows found</h2>\n";

                return 0;
            }

            return false;
        }

        $escape = static function (string $value) {
            return Config::get('htmltbl_escape')
                ? htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
                : $value;
        };

        echo $options === null || $options === '' ? '<table><tr>' : '<table ' . $options . '><tr>';
        foreach ($meta as $column) {
            echo '<th>' . $escape(ucfirst($column['name'])) . '</th>';
        }
        echo "</tr>\n";

        $rows = 0;
        $raw = $first;
        while (true) {
            echo '<tr>';
            foreach ($meta as $index => $column) {
                echo '<td>' . $escape($this->htmlCell($raw[$index] ?? null, $column)) . '</td>';
            }
            echo "</tr>\n";
            $rows++;

            $raw = $this->fetchRaw();
            if ($raw === false) {
                if (!$this->isEndOfData()) {
                    return false;
                }
                break;
            }
        }

        echo "</table>\n";
        $this->lastError = null;

        return $rows;
    }

    /**
     * ifx_fieldtypes().
     *
     * @return array<string, string>|false
     */
    public function fieldTypes() {
        if (!$this->isSelect()) {
            return false;
        }
        $types = [];
        foreach ($this->metadata() as $column) {
            $types[$column['name']] = SqlType::name($column['native']);
        }

        return $types;
    }

    /**
     * ifx_fieldproperties(): "SQLTYPE;length;precision;scale;ISNULLABLE".
     *
     * @return array<string, string>|false
     */
    public function fieldProperties() {
        if (!$this->isSelect()) {
            return false;
        }
        $properties = [];
        foreach ($this->metadata() as $column) {
            $properties[$column['name']] = sprintf(
                '%s;%d;%d;%d;%s',
                SqlType::name($column['native']),
                $column['len'],
                $column['precision'],
                $column['scale'],
                $column['not_null'] ? 'N' : 'Y'
            );
        }

        return $properties;
    }

    public function numFields() {
        return $this->stmt !== null ? $this->stmt->columnCount() : 0;
    }

    public function numRows() {
        return $this->fetched;
    }

    public function affectedRows() {
        return $this->affected;
    }

    /**
     * ifx_getsqlca(): sqlerrd[0..5]. pdo_informix nu expune SQLCA, așa că
     * sqlerrd[1] (ultimul serial) și sqlerrd[2] (rânduri afectate) sunt
     * completate din lastInsertId()/rowCount(); restul rămân 0.
     *
     * @return array{sqlerrd0: int, sqlerrd1: int, sqlerrd2: int, sqlerrd3: int, sqlerrd4: int, sqlerrd5: int}
     */
    public function sqlca() {
        return [
            'sqlerrd0' => 0,
            'sqlerrd1' => $this->serial === null ? 0 : (int) $this->serial,
            'sqlerrd2' => $this->affected,
            'sqlerrd3' => 0,
            'sqlerrd4' => 0,
            'sqlerrd5' => 0,
        ];
    }

    public function free() {
        if ($this->freed) {
            return true;
        }
        $this->freed = true;
        if ($this->stmt !== null) {
            try {
                $this->stmt->closeCursor();
            } catch (\Throwable $e) {
                // cursorul poate fi deja închis de server
            }
        }
        $this->stmt = null;
        $this->meta = null;

        return true;
    }

    public function isSelect() {
        return $this->executed && !$this->freed && $this->numFields() > 0;
    }

    public function isExecuted() {
        return $this->executed;
    }

    public function isFreed() {
        return $this->freed;
    }

    public function cursorFlags() {
        return $this->cursorFlags;
    }

    public function connection() {
        return $this->connection;
    }

    public function lastError() {
        return $this->lastError;
    }

    public function isEndOfData() {
        return $this->lastError !== null && $this->lastError->sqlCode === 100;
    }

    public function __destruct()
    {
        $this->free();
    }

    /**
     * @return list<array{name: string, native: string, len: int, precision: int, scale: int, not_null: bool}>
     */
    private function metadata() {
        if ($this->meta !== null) {
            return $this->meta;
        }
        if (!$this->executed || $this->stmt === null) {
            return $this->meta = [];
        }

        $meta = [];
        $count = $this->stmt->columnCount();
        for ($i = 0; $i < $count; $i++) {
            try {
                $raw = $this->stmt->getColumnMeta($i);
            } catch (\Throwable $e) {
                $raw = false;
            }
            $raw = is_array($raw) ? $raw : [];
            $name = rtrim((string) ($raw['name'] ?? ''));
            if ($name === '' || $name === '(expression)') {
                // PDO nu are un nume pentru expresii; extensia veche folosea
                // "(expression)" iar ifx_htmltbl_result îl afișa ca [Expr_n].
                $name = '[Expr_' . ($i + 1) . ']';
            }
            $flags = is_array($raw['flags'] ?? null) ? $raw['flags'] : [];
            $meta[] = [
                'name'      => $name,
                'native'    => SqlType::nativeName($raw['native_type'] ?? ''),
                'len'       => (int) ($raw['len'] ?? 0),
                'precision' => (int) ($raw['precision'] ?? 0),
                'scale'     => (int) ($raw['scale'] ?? 0),
                'not_null'  => (bool) ($flags['not_null'] ?? false),
            ];
        }

        return $this->meta = $meta;
    }

    /**
     * @return array<int|string, mixed>|false
     */
    private function fetchRaw($position = null) {
        if ($this->freed || $this->stmt === null) {
            $this->lastError = new Error('HY000', -1, 'Result is not valid', null, 'fetch');

            return false;
        }
        if (!$this->executed) {
            $this->lastError = new Error('HY010', -1, 'Result is not executed (call ifx_do())', null, 'fetch');

            return false;
        }
        if ($this->stmt->columnCount() === 0) {
            $this->lastError = new Error('HY000', -1, 'Not a select cursor!', null, 'fetch');

            return false;
        }

        list($orientation, $offset, $valid) = $this->orientation($position);
        if (!$valid) {
            $this->lastError = new Error('HY000', -1, 'Invalid positioning arg on fetch', null, 'fetch');

            return false;
        }

        try {
            $row = $this->stmt->fetch(\PDO::FETCH_NUM, $orientation, $offset);
        } catch (\PDOException $e) {
            $this->lastError = Error::fromThrowable($e, 'fetch');
            $this->connection->setError($this->lastError);

            return false;
        }

        if ($row === false) {
            $this->lastError = new Error('02000', 100, 'No rows found', null, 'fetch');

            return false;
        }

        $this->fetched++;
        $this->lastRaw = $row;
        $this->lastError = null;

        return $row;
    }

    /**
     * @return array{0: int, 1: int, 2: bool}
     */
    private function orientation($position) {
        if (($this->cursorFlags & 1) === 0) {
            // cursor secvențial: poziția este ignorată, ca în extensia veche
            return [\PDO::FETCH_ORI_NEXT, 0, true];
        }

        if ($position === null || $position === '') {
            return [\PDO::FETCH_ORI_NEXT, 0, true];
        }

        if (is_int($position)) {
            return $position === 0
                ? [\PDO::FETCH_ORI_NEXT, 0, true]
                : [\PDO::FETCH_ORI_ABS, $position, true];
        }

        if (is_string($position)) {
            switch (strtoupper(trim($position))) {
                case 'NEXT':
                    return [\PDO::FETCH_ORI_NEXT, 0, true];
                case 'PREVIOUS':
                case 'PRIOR':
                    return [\PDO::FETCH_ORI_PRIOR, 0, true];
                case 'FIRST':
                    return [\PDO::FETCH_ORI_FIRST, 0, true];
                case 'LAST':
                    return [\PDO::FETCH_ORI_LAST, 0, true];
                case 'CURRENT':
                    return [\PDO::FETCH_ORI_REL, 0, true];
                default:
                    return [\PDO::FETCH_ORI_NEXT, 0, false];
            }
        }

        return [\PDO::FETCH_ORI_NEXT, 0, false];
    }

    private function convertValue($value, array $column)
    {
        if (is_resource($value)) {
            $content = stream_get_contents($value);
            @fclose($value);
            $value = $content === false ? '' : $content;
        }

        $native = $column['native'];

        if ($value === null) {
            if (SqlType::isLob($native) && !$this->lobAsString($native)) {
                return HandleRegistry::add(
                    BlobHandle::fromContent(Config::nullValue(), SqlType::isText($native))
                );
            }

            return Config::nullValue();
        }

        if (SqlType::isLob($native)) {
            $value = is_string($value) ? $value : (string) $value;
            if ($this->lobAsString($native)) {
                return $value;
            }

            return HandleRegistry::add(
                BlobHandle::fromContent($value, SqlType::isText($native))
            );
        }

        $value = is_string($value) ? $value : (string) $value;
        if (Config::get('charasvarchar') && SqlType::isChar($native)) {
            $value = rtrim($value);
        }

        return $value;
    }

    private function lobAsString(string $native) {
        return SqlType::isText($native)
            ? (bool) Config::get('textasvarchar')
            : (bool) Config::get('byteasvarchar');
    }

    private function htmlCell($value, array $column) {
        $native = $column['native'];

        if ($value === null) {
            return Config::nullValue();
        }
        if (is_resource($value)) {
            $content = stream_get_contents($value);
            @fclose($value);
            $value = $content === false ? '' : $content;
        }
        if (SqlType::isByte($native)) {
            return '(BYTE)';
        }

        return is_string($value) ? $value : (string) $value;
    }

    private function bindParameter(int $position, $value) {
        if ($this->stmt === null) {
            return;
        }

        if ($value instanceof BlobHandle) {
            $this->stmt->bindValue($position, $value->content(), \PDO::PARAM_LOB);

            return;
        }
        if ($value instanceof CharHandle) {
            $this->stmt->bindValue($position, $value->content(), \PDO::PARAM_STR);

            return;
        }
        if ($value instanceof SlobHandle) {
            $this->stmt->bindValue($position, $value->contents(), \PDO::PARAM_LOB);

            return;
        }
        if ($value === null) {
            $this->stmt->bindValue($position, null, \PDO::PARAM_NULL);

            return;
        }
        if (is_bool($value)) {
            $this->stmt->bindValue($position, $value ? '1' : '0', \PDO::PARAM_STR);

            return;
        }

        $this->stmt->bindValue($position, (string) $value, \PDO::PARAM_STR);
    }

    private static function looksLikeInsert(string $sql) {
        return preg_match('/^\s*(?:--[^\n]*\n\s*|\/\*.*?\*\/\s*)*insert\b/is', $sql) === 1;
    }

    private static function looksLikeSelect(string $sql) {
        return preg_match(
            '/^\s*(?:--[^\n]*\n\s*|\/\*.*?\*\/\s*)*(select|with)\b/is',
            $sql
        ) === 1;
    }

    private static function looksLikeDml(string $sql) {
        return preg_match(
            '/^\s*(?:--[^\n]*\n\s*|\/\*.*?\*\/\s*)*(insert|update|delete|merge)\b/is',
            $sql
        ) === 1;
    }

    /**
     * Numărul real de rânduri afectate, din SQLCA (sqlerrd[2]).
     */
    private function readSqlcaRowCount() {
        try {
            $value = $this->connection
                ->pdo()
                ->query("select dbinfo('sqlca.sqlerrd2') from systables where tabid = 1")
                ->fetchColumn();

            return (int) $value;
        } catch (\Throwable $e) {
            return 0;
        }
    }
}
