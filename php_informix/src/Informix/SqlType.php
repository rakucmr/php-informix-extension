<?php

declare(strict_types=1);

namespace Informix;

/**
 * Corespondența dintre numele native raportate de pdo_informix
 * (SQLDescribeCol / SQL_DESC_TYPE_NAME) și constantele SQL* folosite de
 * ifx_fieldtypes() și ifx_fieldproperties().
 */
final class SqlType
{
    /** @var array<string, string> */
    const MAP = [
        'SERIAL'      => 'SQLSERIAL',
        'SERIAL8'     => 'SQLSERIAL8',
        'INTEGER'     => 'SQLINT',
        'INT'         => 'SQLINT',
        'INT8'        => 'SQLINT8',
        'BIGINT'      => 'SQLINT8',
        'SMALLINT'    => 'SQLSMINT',
        'DECIMAL'     => 'SQLDECIMAL',
        'DEC'         => 'SQLDECIMAL',
        'NUMERIC'     => 'SQLDECIMAL',
        'MONEY'       => 'SQLMONEY',
        'SMALLFLOAT'  => 'SQLSMFLOAT',
        'REAL'        => 'SQLSMFLOAT',
        'FLOAT'       => 'SQLFLOAT',
        'DOUBLE'      => 'SQLFLOAT',
        'DATE'        => 'SQLDATE',
        'DATETIME'    => 'SQLDTIME',
        'INTERVAL'    => 'SQLINTERVAL',
        'CHAR'        => 'SQLCHAR',
        'CHARACTER'   => 'SQLCHAR',
        'VARCHAR'     => 'SQLVCHAR',
        'VARCHAR2'    => 'SQLVCHAR',
        'NCHAR'       => 'SQLNCHAR',
        'NVARCHAR'    => 'SQLNVCHAR',
        'LVARCHAR'    => 'SQLLVARCHAR',
        'TEXT'        => 'SQLTEXT',
        'BYTE'        => 'SQLBYTES',
        // Smart LOB-urile erau raportate de extensia veche ca SQLUDTFIXED.
        'BLOB'        => 'SQLUDTFIXED',
        'CLOB'        => 'SQLUDTFIXED',
        'JSON'        => 'SQLUDTFIXED',
        'BSON'        => 'SQLUDTFIXED',
        'BOOLEAN'     => 'SQLBOOL',
        'BOOL'        => 'SQLBOOL',
    ];

    /** @var array<string, true> */
    const LOB = [
        'TEXT' => true,
        'BYTE' => true,
        'BLOB' => true,
        'CLOB' => true,
    ];

    private function __construct()
    {
    }

    public static function nativeName($native) {
        return strtoupper(trim((string) $native));
    }

    /**
     * Numele "SQL..." folosit de ifx_fieldtypes(). Pentru tipurile necunoscute
     * întoarce numele nativ, ca să nu pierdem informația.
     */
    public static function name($native) {
        $native = self::nativeName($native);
        if (isset(self::MAP[$native])) {
            return self::MAP[$native];
        }
        if (str_starts_with($native, 'DATETIME')) {
            return 'SQLDTIME';
        }
        if (str_starts_with($native, 'INTERVAL')) {
            return 'SQLINTERVAL';
        }

        return $native !== '' ? $native : 'SQLUNKNOWN';
    }

    public static function isLob($native) {
        return isset(self::LOB[self::nativeName($native)]);
    }

    public static function isText($native) {
        $native = self::nativeName($native);

        return $native === 'TEXT' || $native === 'CLOB';
    }

    public static function isByte($native) {
        $native = self::nativeName($native);

        return $native === 'BYTE' || $native === 'BLOB';
    }

    public static function isChar($native) {
        $native = self::nativeName($native);

        return $native === 'CHAR' || $native === 'NCHAR';
    }
}
