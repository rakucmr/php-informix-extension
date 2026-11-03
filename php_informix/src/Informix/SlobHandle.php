<?php

declare(strict_types=1);

namespace Informix;

/**
 * SLOB ("smart large object") în memoria procesului.
 *
 * Extensia veche lucra cu ifx_lo_* din ESQL/C, adică obiecte reale pe server.
 * pdo_informix nu expune API-ul de smart LOB, așa că aici SLOB-ul este un
 * buffer cu poziție de citire/scriere. Este suficient pentru utilizarea
 * tipică a ifxus_* (asamblarea unui conținut înainte de a-l trimite ca
 * parametru), dar nu creează un obiect pe server.
 */
final class SlobHandle
{
    const RDONLY = 0x4;
    const WRONLY = 0x2;
    const APPEND = 0x1;
    const RDWR = 0x8;
    const BUFFER = 0x200;
    const NOBUFFER = 0x400;

    private $buffer = '';

    private $position = 0;

    private $open = true;

    private $freed = false;

    private $mode;

    public function __construct(int $mode = 0)
    {
        $this->mode = $mode;
        if ($this->mode === 0) {
            $this->mode = self::RDWR;
        }
        if (($this->mode & self::APPEND) !== 0) {
            $this->position = 0;
        }
    }

    public function mode() {
        return $this->mode;
    }

    public function isOpen() {
        return $this->open && !$this->freed;
    }

    public function isFreed() {
        return $this->freed;
    }

    public function read(int $bytes) {
        if (!$this->isOpen() || $bytes < 0) {
            return false;
        }
        if ($bytes === 0) {
            return '';
        }
        $chunk = substr($this->buffer, $this->position, $bytes);
        $this->position += strlen($chunk);

        return $chunk;
    }

    public function write(string $data) {
        if (!$this->isOpen() || !$this->canWrite()) {
            return false;
        }
        if (($this->mode & self::APPEND) !== 0) {
            $this->position = strlen($this->buffer);
        }
        $length = strlen($data);
        if ($length === 0) {
            return 0;
        }
        if ($this->position > strlen($this->buffer)) {
            $this->buffer = str_pad($this->buffer, $this->position, "\0");
        }
        $this->buffer = substr_replace($this->buffer, $data, $this->position, $length);
        $this->position += $length;

        return $length;
    }

    public function seek(int $offset, int $whence = 0) {
        if (!$this->isOpen()) {
            return false;
        }
        if ($whence === 1) {
            $base = $this->position;
        } elseif ($whence === 2) {
            $base = strlen($this->buffer);
        } else {
            $base = 0;
        }
        $position = $base + $offset;
        if ($position < 0) {
            return false;
        }
        $this->position = $position;

        return $this->position;
    }

    public function tell() {
        if (!$this->isOpen()) {
            return false;
        }

        return $this->position;
    }

    public function close() {
        if ($this->freed || !$this->open) {
            return false;
        }
        $this->open = false;

        return true;
    }

    /** @return int 0 la succes, ca în extensia veche (care întorcea 0, nu un id nou). */
    public function open(int $mode) {
        if ($this->freed || $this->open) {
            return -1;
        }
        $this->open = true;
        $this->mode = $mode === 0 ? self::RDWR : $mode;
        $this->position = ($this->mode & self::APPEND) !== 0 ? strlen($this->buffer) : 0;

        return 0;
    }

    public function free() {
        $this->freed = true;
        $this->open = false;
        $this->buffer = '';
        $this->position = 0;
    }

    public function contents() {
        return $this->buffer;
    }

    private function canWrite() {
        return ($this->mode & (self::WRONLY | self::RDWR | self::APPEND)) !== 0;
    }
}
