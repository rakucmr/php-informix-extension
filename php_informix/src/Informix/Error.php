<?php

declare(strict_types=1);

namespace Informix;

/**
 * O eroare Informix, în forma în care o vedea extensia veche prin SQLCA:
 * SQLSTATE, SQLCODE și mesajul driverului.
 */
final class Error
{
    public $sqlState = '00000';
    public $sqlCode = 0;
    public $message = '';
    public $isamCode = null;
    public $operation = '';

    public function __construct(
        string $sqlState = '00000',
        int $sqlCode = 0,
        string $message = '',
        $isamCode = null,
        string $operation = ''
    ) {
        $this->sqlState = self::normalizeState($sqlState);
        $this->sqlCode = $sqlCode;
        $this->message = $message;
        $this->isamCode = $isamCode;
        $this->operation = $operation;
    }

    /**
     * Construiește o eroare dintr-un PDOException / excepție a driverului.
     */
    public static function fromThrowable(\Throwable $e, string $operation = '') {
        $info = null;
        if ($e instanceof \PDOException && is_array($e->errorInfo ?? null)) {
            $info = $e->errorInfo;
        }

        $state = 'HY000';
        $code = 0;
        $message = $e->getMessage();

        if ($info !== null && isset($info[0])) {
            $state = (string) ($info[0] ?? 'HY000');
            $code = (int) ($info[1] ?? 0);
            if (isset($info[2]) && $info[2] !== '') {
                $message = (string) $info[2];
            }
        } else {
            $thrownCode = $e->getCode();
            if (is_string($thrownCode) && preg_match('/^[0-9A-Z]{5}$/', $thrownCode) === 1) {
                $state = $thrownCode;
            }
            if (preg_match('/:\s*(-?\d+)\s/', ' ' . $message . ' ', $m) === 1) {
                $code = (int) $m[1];
            } elseif (is_int($thrownCode)) {
                $code = $thrownCode;
            }
            // Scoate prefixul PDO: "SQLSTATE[42S02]: Base table or view not found: -206 "
            $message = preg_replace('/^SQLSTATE\[[^\]]*\]:\s*/', '', $message) ?? $message;
        }

        $isam = null;
        if (preg_match('/\(ISAM:\s*(-?\d+)\)/', $message, $m) === 1) {
            $isam = (int) $m[1];
        }

        return new self($state, $code, $message, $isam, $operation);
    }

    public static function fromErrorInfo(array $info, string $operation = '') {
        return new self(
            (string) ($info[0] ?? 'HY000'),
            (int) ($info[1] ?? 0),
            (string) ($info[2] ?? ''),
            null,
            $operation
        );
    }

    /** Caracterul de stare folosit de ifx_error(): spațiu, W, E sau N. */
    public function status() {
        if ($this->sqlCode < 0) {
            return 'E';
        }
        if ($this->sqlCode === 100) {
            return 'N';
        }
        if ($this->sqlCode > 0 && $this->sqlCode < 100) {
            return 'W';
        }

        return ' ';
    }

    /**
     * Reproduce exact formatul ifx_error() din ext/informix:
     *   E [SQLSTATE=42 S02  SQLCODE=-206]
     */
    public function ifxError() {
        $state = self::normalizeState($this->sqlState);

        return sprintf(
            '%s [SQLSTATE=%s %s  SQLCODE=%d]',
            $this->status(),
            substr($state, 0, 2),
            substr($state, 2, 3),
            $this->sqlCode
        );
    }

    public function __toString() {
        return $this->ifxError();
    }

    private static function normalizeState(string $state) {
        $state = strtoupper(trim($state));
        if ($state === '') {
            $state = '00000';
        }
        if (strlen($state) < 5) {
            $state = str_pad($state, 5, '0');
        }

        return substr($state, 0, 5);
    }
}
