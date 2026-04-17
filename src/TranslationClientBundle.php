<?php

declare(strict_types=1);

namespace Maoxtrem\TranslationClientKit;

use Symfony\Component\HttpKernel\Bundle\Bundle;

final class TranslationClientBundle extends Bundle
{
    /**
     * Obligamos a Symfony a subir un nivel para encontrar 
     * las carpetas /config y /public.
     */
    public function getPath(): string
    {
        return \dirname(__DIR__);
    }
}