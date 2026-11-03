<?php

declare(strict_types=1);

namespace Informix;

/**
 * Un BLOB/TEXT "id-result" ca în extensia veche: conținutul poate sta în
 * memorie (mode 0) sau într-un fișier temporar (mode 1). În mode 1,
 * ifx_get_blob() întoarce numele fișierului, exact ca vechea extensie.
 */
final class BlobHandle
{
    const TYPE_BYTE = 0;
    const TYPE_TEXT = 1;

    const MODE_MEMORY = 0;
    const MODE_FILE = 1;

    private $content = '';

    private $tempFile = null;

    private $freed = false;

    private $type;

    private $mode;

    public function __construct(
        int $type,
        int $mode,
        string $content = '',
        $sourceFile = null
    ) {
        $this->type = $type === self::TYPE_TEXT ? self::TYPE_TEXT : self::TYPE_BYTE;
        // ca în extensia veche: orice mod nenul înseamnă fișier
        $this->mode = $mode !== self::MODE_MEMORY ? self::MODE_FILE : self::MODE_MEMORY;

        if ($this->mode === self::MODE_FILE) {
            if ($sourceFile !== null && $sourceFile !== '' && is_file($sourceFile)) {
                $read = @file_get_contents($sourceFile);
                $this->content = $read === false ? '' : $read;
            } else {
                $this->content = $content;
            }
            $this->tempFile = self::makeTempFile($this->content);
        } else {
            $this->content = $content;
        }
    }

    public static function fromContent(string $content, bool $text, $mode = null) {
        if ($mode === null) { $mode = (int) Config::get('blobinfile'); }

        return new self($text ? self::TYPE_TEXT : self::TYPE_BYTE, $mode, $content);
    }

    public function type() {
        return $this->type;
    }

    public function mode() {
        return $this->mode;
    }

    public function isText() {
        return $this->type === self::TYPE_TEXT;
    }

    public function isByte() {
        return $this->type === self::TYPE_BYTE;
    }

    public function isFreed() {
        return $this->freed;
    }

    /** Conținutul efectiv, indiferent de mod. */
    public function content() {
        if ($this->freed) {
            return '';
        }
        if ($this->mode === self::MODE_FILE && $this->tempFile !== null && is_file($this->tempFile)) {
            $read = @file_get_contents($this->tempFile);

            return $read === false ? '' : $read;
        }

        return $this->content;
    }

    /** Ce întoarce ifx_get_blob(): conținut sau numele fișierului temporar. */
    public function payload() {
        if ($this->mode === self::MODE_FILE && $this->tempFile !== null) {
            return $this->tempFile;
        }

        return $this->content();
    }

    public function update(string $content) {
        if ($this->freed) {
            return false;
        }
        $this->content = $content;
        if ($this->mode === self::MODE_FILE) {
            if ($this->tempFile === null) {
                $this->tempFile = self::makeTempFile($content);
            } else {
                @file_put_contents($this->tempFile, $content);
            }
        }

        return true;
    }

    public function duplicate() {
        if ($this->freed) {
            return null;
        }

        return new self($this->type, $this->mode, $this->content());
    }

    public function free() {
        if ($this->freed) {
            return;
        }
        $this->freed = true;
        if ($this->tempFile !== null) {
            if (is_file($this->tempFile)) {
                @unlink($this->tempFile);
            }
            $this->tempFile = null;
        }
        $this->content = '';
    }

    public function __destruct()
    {
        $this->free();
    }

    private static function makeTempFile(string $content) {
        $file = tempnam(sys_get_temp_dir(), 'ifx');
        if ($file === false) {
            throw new \RuntimeException('Nu pot crea fișierul temporar pentru BLOB');
        }
        @file_put_contents($file, $content);

        return $file;
    }
}
