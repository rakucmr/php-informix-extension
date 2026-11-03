<?php

declare(strict_types=1);

namespace Informix;

/**
 * Obiectul CHAR din extensia veche: un simplu container de string, cu id întreg.
 */
final class CharHandle
{
    private $freed = false;

    private $content;

    public function __construct(string $content)
    {
        $this->content = $content;
    }

    public function content() {
        return $this->freed ? '' : $this->content;
    }

    public function update(string $content) {
        if ($this->freed) {
            return false;
        }
        $this->content = $content;

        return true;
    }

    public function isFreed() {
        return $this->freed;
    }

    public function free() {
        $this->freed = true;
        $this->content = '';
    }
}
