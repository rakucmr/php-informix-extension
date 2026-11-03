<?php

declare(strict_types=1);

namespace Informix;

/**
 * Implementarea funcțiilor legacy. Fiecare metodă publică de aici este
 * apelată de câte un wrapper ifx_* / ifxus_* din src/compat.php.
 *
 * Convenția de erori este cea a extensiei vechi: funcțiile întorc false la
 * eroare, emit un E_USER_WARNING și lasă starea pentru ifx_error() /
 * ifx_errormsg(). Excepțiile PDO nu ies niciodată în codul aplicației.
 */
final class Api
{
    private static $lastError = null;

    private static $status = ' ';

    private static $defaultLink = null;

    private static $lastLink = null;

    /** @var array<int, string> */
    const MESSAGES = [
        100   => 'No rows found',
        -1    => 'The specified key is not unique in the index.',
        -201  => 'A syntax error has occurred.',
        -206  => 'The specified table is not in the database.',
        -217  => 'Column not found in any table in the query.',
        -239  => 'Could not insert new row - duplicate value in a unique index.',
        -268  => 'Unique constraint violated.',
        -451  => 'Attempt to use a cursor that is not open.',
        -608  => 'Illegal attempt to convert Text/Byte blob type.',
        -1204 => 'Invalid year in date.',
        -11041 => 'Data source name not found and no default driver specified.',
        -11060 => 'General error.',
        -11067 => 'Function sequence error.',
    ];

    private function __construct()
    {
    }

    /** Golește toată starea statică; folosit de teste și de requesturi lungi. */
    public static function reset() {
        Connection::closeAll();
        HandleRegistry::clear();
        Config::reset();
        self::$lastError = null;
        self::$status = ' ';
        self::$defaultLink = null;
        self::$lastLink = null;
    }

    public static function connect(
        $database = null,
        $user = null,
        $password = null,
        bool $persistent = false,
        string $function = 'ifx_connect'
    ) {
        try {
            $connection = Connection::connect(
                self::argString($database),
                self::argString($user),
                self::argString($password),
                $persistent
            );
        } catch (\PDOException $e) {
            $error = Error::fromThrowable($e, 'connect');
            self::setError($error);
            self::warn($function, $error->message . ' (' . $error->ifxError() . ')');

            return null;
        } catch (\Throwable $e) {
            $error = new Error('08001', -1, $e->getMessage(), null, 'connect');
            self::setError($error);
            self::warn($function, $e->getMessage() . ' (' . $error->ifxError() . ')');

            return null;
        }

        self::$defaultLink = $connection;
        self::$lastLink = $connection;
        self::success();

        return $connection;
    }

    public static function close($link = null) {
        $connection = self::link($link, 'ifx_close');
        if ($connection === null) {
            return false;
        }

        if (self::$defaultLink === $connection) {
            self::$defaultLink = null;
        }
        if (self::$lastLink === $connection) {
            self::$lastLink = null;
        }
        $connection->close();
        self::success();

        return true;
    }

    public static function query(
        $query,
        $link = null,
        $cursorType = 0,
        $blobArray = null,
        string $function = 'ifx_query'
    ) {
        list($cursorType, $blobArray) = self::normalizeBlobArgument($cursorType, $blobArray);
        $connection = self::link($link, $function);
        if ($connection === null) {
            return false;
        }

        $sql = self::argString($query);
        if ($sql === null) {
            self::warn($function, 'Query must be a string');

            return false;
        }

        $params = self::normalizeParams($blobArray, $sql, $function);
        if ($params === false) {
            return false;
        }

        try {
            $result = Result::query($connection, $sql, (int) $cursorType, $params);
        } catch (\PDOException $e) {
            $error = Error::fromThrowable($e, 'query');
            $connection->setError($error);
            self::setError($error);
            self::warn($function, 'Query fails (' . $error->ifxError() . ')');

            return false;
        }

        self::$lastLink = $connection;
        self::success();

        return $result;
    }

    public static function prepare(
        $query,
        $link = null,
        $cursorType = 0,
        $blobArray = null,
        string $function = 'ifx_prepare'
    ) {
        list($cursorType, $blobArray) = self::normalizeBlobArgument($cursorType, $blobArray);
        $connection = self::link($link, $function);
        if ($connection === null) {
            return false;
        }

        $sql = self::argString($query);
        if ($sql === null) {
            self::warn($function, 'Query must be a string');

            return false;
        }

        $params = self::normalizeParams($blobArray, $sql, $function);
        if ($params === false) {
            return false;
        }

        try {
            $result = Result::prepare($connection, $sql, (int) $cursorType, $params);
        } catch (\PDOException $e) {
            $error = Error::fromThrowable($e, 'prepare');
            $connection->setError($error);
            self::setError($error);
            self::warn($function, 'Prepare fails (' . $error->ifxError() . ')');

            return false;
        }

        self::$lastLink = $connection;
        self::success();

        return $result;
    }

    public static function do($result, string $function = 'ifx_do') {
        $res = self::result($result, $function);
        if ($res === null) {
            return false;
        }

        try {
            $res->execute();
        } catch (\PDOException $e) {
            $error = Error::fromThrowable($e, 'execute');
            self::setError($error);
            self::warn($function, 'Execute immediate fails (' . $error->ifxError() . ')');

            return false;
        }

        self::success();

        return true;
    }

    public static function fetchRow($result, $position = null, string $function = 'ifx_fetch_row') {
        $res = self::result($result, $function);
        if ($res === null) {
            return false;
        }

        $row = $res->fetch($position);
        if ($row === false) {
            $error = $res->lastError();
            if ($error !== null && !$res->isEndOfData()) {
                self::setError($error);
                self::warn($function, $error->message !== ''
                    ? $error->message
                    : 'Can not fetch row (' . $error->ifxError() . ')');
            } elseif ($error !== null) {
                self::setError($error);
            }

            return false;
        }

        self::success();

        return $row;
    }

    public static function htmlTable($result, $options = null, string $function = 'ifx_htmltbl_result') {
        $res = self::result($result, $function);
        if ($res === null) {
            return false;
        }

        $options = self::argString($options);
        $count = $res->htmlTable($options);
        if ($count === false) {
            $error = $res->lastError();
            if ($error !== null) {
                self::setError($error);
            }
            self::warn($function, $error !== null ? $error->message : 'Can not format result');

            return false;
        }

        self::success();

        return $count;
    }

    public static function fieldTypes($result, string $function = 'ifx_fieldtypes') {
        $res = self::result($result, $function);
        if ($res === null) {
            return false;
        }

        $types = $res->fieldTypes();
        if ($types === false) {
            self::warn($function, $res->isExecuted()
                ? 'Not a select cursor!'
                : 'Column metadata is available only after ifx_do()');

            return false;
        }

        self::success();

        return $types;
    }

    public static function fieldProperties($result, string $function = 'ifx_fieldproperties') {
        $res = self::result($result, $function);
        if ($res === null) {
            return false;
        }

        $properties = $res->fieldProperties();
        if ($properties === false) {
            self::warn($function, $res->isExecuted()
                ? 'Not a select cursor!'
                : 'Column metadata is available only after ifx_do()');

            return false;
        }

        self::success();

        return $properties;
    }

    public static function numRows($result, string $function = 'ifx_num_rows') {
        $res = self::result($result, $function);
        if ($res === null) {
            return false;
        }
        self::success();

        return $res->numRows();
    }

    public static function numFields($result, string $function = 'ifx_num_fields') {
        $res = self::result($result, $function);
        if ($res === null) {
            return false;
        }
        self::success();

        return $res->numFields();
    }

    public static function affectedRows($result, string $function = 'ifx_affected_rows') {
        $res = self::result($result, $function);
        if ($res === null) {
            return false;
        }
        self::success();

        return $res->affectedRows();
    }

    public static function sqlca($result, string $function = 'ifx_getsqlca') {
        $res = self::result($result, $function);
        if ($res === null) {
            return false;
        }
        self::success();

        return $res->sqlca();
    }

    public static function freeResult($result, string $function = 'ifx_free_result') {
        $res = self::result($result, $function);
        if ($res === null) {
            return false;
        }
        $res->free();
        self::success();

        return true;
    }

    public static function error($link = null) {
        if (self::$lastError !== null) {
            return self::$lastError->ifxError();
        }

        return sprintf('%s [SQLSTATE=00 000  SQLCODE=0]', self::$status);
    }

    public static function errorMessage($code = null) {
        if ($code === null) {
            return self::$lastError->message ?? '';
        }

        $code = (int) $code;
        if (self::$lastError !== null && self::$lastError->sqlCode === $code) {
            return self::$lastError->message;
        }

        return self::MESSAGES[$code] ?? ('Informix error ' . $code);
    }

    // ---------------------------------------------------------------- BLOBs

    public static function createBlob($type, $mode, $param, string $function = 'ifx_create_blob') {
        // ca în extensia veche: 0 = BYTE, orice altă valoare = TEXT
        $blobType = (int) $type === 0 ? BlobHandle::TYPE_BYTE : BlobHandle::TYPE_TEXT;
        $mode = (int) $mode;
        $param = self::argString($param) ?? '';

        if ($mode !== 0 && $param !== '' && !is_file($param)) {
            self::warn($function, 'Could not create blob-resource file. File copy failed: ' . $param);

            return false;
        }

        try {
            $blob = new BlobHandle($blobType, $mode, $param, $mode !== 0 ? $param : null);
        } catch (\Throwable $e) {
            self::warn($function, $e->getMessage());

            return false;
        }

        self::success();

        return HandleRegistry::add($blob);
    }

    public static function copyBlob($id, string $function = 'ifx_copy_blob') {
        $blob = self::blob($id, $function);
        if ($blob === null) {
            return false;
        }
        $copy = $blob->duplicate();
        if ($copy === null) {
            self::warn($function, 'Can not copy a freed blob');

            return false;
        }
        self::success();

        return HandleRegistry::add($copy);
    }

    public static function freeBlob($id, string $function = 'ifx_free_blob') {
        $blob = self::blob($id, $function);
        if ($blob === null) {
            return false;
        }
        HandleRegistry::remove((int) $id);
        $blob->free();
        self::success();

        return true;
    }

    public static function getBlob($id, string $function = 'ifx_get_blob') {
        $blob = self::blob($id, $function);
        if ($blob === null) {
            return false;
        }
        $payload = $blob->payload();
        self::success();

        return $payload;
    }

    public static function updateBlob($id, $content, string $function = 'ifx_update_blob') {
        $blob = self::blob($id, $function);
        if ($blob === null) {
            return false;
        }
        $content = self::argString($content);
        if ($content === null) {
            self::warn($function, 'Content must be a string');

            return false;
        }
        $blob->update($content);
        self::success();

        return true;
    }

    public static function blobinfileMode($mode, string $function = 'ifx_blobinfile_mode') {
        Config::set('blobinfile', (int) $mode);
        self::success();

        return true;
    }

    public static function textAsVarchar($mode, string $function = 'ifx_textasvarchar') {
        Config::set('textasvarchar', (int) $mode);
        self::success();

        return true;
    }

    public static function byteAsVarchar($mode, string $function = 'ifx_byteasvarchar') {
        Config::set('byteasvarchar', (int) $mode);
        self::success();

        return true;
    }

    public static function charAsVarchar($mode, string $function = 'ifx_charasvarchar') {
        Config::set('charasvarchar', (int) $mode);
        self::success();

        return true;
    }

    public static function nullFormat($mode, string $function = 'ifx_nullformat') {
        Config::set('nullformat', (int) $mode);
        self::success();

        return true;
    }

    // ----------------------------------------------------------------- CHARs

    public static function createChar($param, string $function = 'ifx_create_char') {
        $content = self::argString($param);
        if ($content === null) {
            self::warn($function, 'Content must be a string');

            return false;
        }
        self::success();

        return HandleRegistry::add(new CharHandle($content));
    }

    public static function getChar($id, string $function = 'ifx_get_char') {
        $char = self::char($id, $function);
        if ($char === null) {
            return false;
        }
        self::success();

        return $char->content();
    }

    public static function updateChar($id, $content, string $function = 'ifx_update_char') {
        $char = self::char($id, $function);
        if ($char === null) {
            return false;
        }
        $content = self::argString($content);
        if ($content === null) {
            self::warn($function, 'Content must be a string');

            return false;
        }
        $char->update($content);
        self::success();

        return true;
    }

    public static function freeChar($id, string $function = 'ifx_free_char') {
        $char = self::char($id, $function);
        if ($char === null) {
            return false;
        }
        HandleRegistry::remove((int) $id);
        $char->free();
        self::success();

        return true;
    }

    // ----------------------------------------------------------------- SLOBs

    public static function createSlob($mode, string $function = 'ifxus_create_slob') {
        self::success();

        return HandleRegistry::add(new SlobHandle((int) $mode));
    }

    public static function openSlob($id, $mode, string $function = 'ifxus_open_slob') {
        $slob = self::slob($id, $function);
        if ($slob === null) {
            return false;
        }
        $result = $slob->open((int) $mode);
        if ($result < 0) {
            self::warn($function, 'Can not open slob-resource');

            return false;
        }
        self::success();

        return $result;
    }

    public static function closeSlob($id, string $function = 'ifxus_close_slob') {
        $slob = self::slob($id, $function);
        if ($slob === null) {
            return false;
        }
        if (!$slob->close()) {
            self::warn($function, 'Slob-resource already closed');

            return false;
        }
        self::success();

        return true;
    }

    public static function freeSlob($id, string $function = 'ifxus_free_slob') {
        $slob = self::slob($id, $function);
        if ($slob === null) {
            return false;
        }
        HandleRegistry::remove((int) $id);
        $slob->free();
        self::success();

        return true;
    }

    public static function tellSlob($id, string $function = 'ifxus_tell_slob') {
        $slob = self::slob($id, $function);
        if ($slob === null) {
            return false;
        }
        $position = $slob->tell();
        if ($position === false) {
            self::warn($function, 'Can not perform tell-operation');

            return false;
        }
        self::success();

        return $position;
    }

    public static function seekSlob($id, $mode, $offset, string $function = 'ifxus_seek_slob') {
        $slob = self::slob($id, $function);
        if ($slob === null) {
            return false;
        }
        $position = $slob->seek((int) $offset, (int) $mode);
        if ($position === false) {
            self::warn($function, 'Can not perform seek-operation');

            return false;
        }
        self::success();

        return $position;
    }

    public static function readSlob($id, $bytes, string $function = 'ifxus_read_slob') {
        $slob = self::slob($id, $function);
        if ($slob === null) {
            return false;
        }
        $data = $slob->read((int) $bytes);
        if ($data === false) {
            self::warn($function, 'Error reading slob');

            return false;
        }
        self::success();

        return $data;
    }

    public static function writeSlob($id, $content, string $function = 'ifxus_write_slob') {
        $slob = self::slob($id, $function);
        if ($slob === null) {
            return false;
        }
        $content = self::argString($content);
        if ($content === null) {
            self::warn($function, 'Content must be a string');

            return false;
        }
        $written = $slob->write($content);
        if ($written === false) {
            self::warn($function, 'Error writing slob');

            return false;
        }
        self::success();

        return $written;
    }

    // --------------------------------------------------------------- helpers

    private static function link($link, string $function) {
        if ($link === null) {
            if (self::$defaultLink === null || self::$defaultLink->isClosed()) {
                self::warn($function, 'No Informix connection has been opened');

                return null;
            }

            return self::$defaultLink;
        }
        if ($link instanceof Connection && !$link->isClosed()) {
            return $link;
        }
        self::warn($function, 'Supplied argument is not a valid Informix link');

        return null;
    }

    private static function result($result, string $function) {
        if ($result instanceof Result && !$result->isFreed()) {
            return $result;
        }
        self::warn($function, 'Supplied argument is not a valid Informix result');

        return null;
    }

    private static function blob($id, string $function) {
        $handle = self::numericHandle($id);
        if ($handle instanceof BlobHandle && !$handle->isFreed()) {
            return $handle;
        }
        self::warn($function, self::handleLabel($id) . ' is not an Informix blob-result index');

        return null;
    }

    private static function char($id, string $function) {
        $handle = self::numericHandle($id);
        if ($handle instanceof CharHandle && !$handle->isFreed()) {
            return $handle;
        }
        self::warn($function, self::handleLabel($id) . ' is not an Informix char-result index');

        return null;
    }

    private static function slob($id, string $function) {
        $handle = self::numericHandle($id);
        if ($handle instanceof SlobHandle && !$handle->isFreed()) {
            return $handle;
        }
        self::warn($function, self::handleLabel($id) . ' is not an Informix slob-result index');

        return null;
    }

    private static function numericHandle($id)
    {
        if (is_int($id)) {
            return HandleRegistry::get($id);
        }
        if (is_string($id) && preg_match('/^-?\d+$/', $id) === 1) {
            return HandleRegistry::get((int) $id);
        }

        return null;
    }

    private static function handleLabel($id) {
        if (is_scalar($id)) {
            return (string) $id;
        }
        if (is_object($id) && method_exists($id, '__toString')) {
            return (string) $id;
        }

        return is_object($id) ? get_class($id) : gettype($id);
    }

    /**
     * Numără placeholderele `?`, ignorând cele din literali și comentarii.
     */
    private static function countPlaceholders(string $sql) {
        $clean = preg_replace("/'(?:''|[^'])*'/s", "''", $sql) ?? $sql;
        $clean = preg_replace('/"(?:""|[^"])*"/s', '""', $clean) ?? $clean;
        $clean = preg_replace('/--[^\n]*/', '', $clean) ?? $clean;
        $clean = preg_replace('~/\*.*?\*/~s', '', $clean) ?? $clean;

        return substr_count($clean, '?');
    }

    /**
     * @return array{0: mixed, 1: mixed}
     */
    private static function normalizeBlobArgument($cursorType, $blobArray) {
        // extensia veche accepta array-ul de blob-uri și ca al treilea argument
        if (is_array($cursorType) && $blobArray === null) {
            return [0, $cursorType];
        }

        return [$cursorType, $blobArray];
    }

    /**
     * @return list<mixed>|false|null
     */
    private static function normalizeParams($blobArray, string $sql, string $function)
    {
        if ($blobArray === null) {
            return null;
        }
        if (!is_array($blobArray)) {
            self::warn($function, 'Blob-parameter is not an array');

            return false;
        }

        $values = array_values($blobArray);
        $placeholders = self::countPlaceholders($sql);
        if ($placeholders === 0 && $values === []) {
            return null;
        }
        if ($placeholders !== count($values)) {
            self::warn($function, "number of created blobs doesn't match placeholders (?)");

            return false;
        }

        $normalized = [];
        foreach ($values as $value) {
            if ($value instanceof BlobHandle || $value instanceof CharHandle || $value instanceof SlobHandle) {
                if ($value->isFreed()) {
                    self::warn($function, 'A freed Informix handle was used as parameter');

                    return false;
                }
                $normalized[] = $value;
                continue;
            }
            if (is_int($value) || (is_string($value) && preg_match('/^-?\d+$/', $value) === 1)) {
                $handle = self::numericHandle($value);
                if ($handle instanceof BlobHandle || $handle instanceof CharHandle || $handle instanceof SlobHandle) {
                    $normalized[] = $handle;
                    continue;
                }
                self::warn($function, $value . ' is not an Informix blob-result index');

                return false;
            }
            // Extensie față de API-ul vechi: alături de blob id-uri se pot
            // trimite și valori scalare pentru placeholderele obișnuite.
            if ($value === null || is_scalar($value)) {
                $normalized[] = $value;
                continue;
            }
            self::warn($function, 'Unsupported parameter type: ' . get_debug_type($value));

            return false;
        }

        return $normalized;
    }

    private static function argString($value) {
        if ($value === null) {
            return null;
        }
        if (is_scalar($value) || $value instanceof \Stringable) {
            return (string) $value;
        }

        return null;
    }

    private static function success() {
        self::$lastError = null;
        self::$status = ' ';
    }

    private static function setError(Error $error) {
        self::$lastError = $error;
        self::$status = $error->status();
    }

    public static function warn(string $function, string $message) {
        trigger_error($function . '(): ' . $message, E_USER_WARNING);
    }
}
