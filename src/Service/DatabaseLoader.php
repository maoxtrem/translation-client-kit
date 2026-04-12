<?php

declare(strict_types=1);

namespace Maoxtrem\TranslationClientKit\Service;

use Maoxtrem\TranslationClientKit\Repository\TraduccionLocalRepository;
use Symfony\Component\Translation\Loader\LoaderInterface;
use Symfony\Component\Translation\MessageCatalogue;

final class DatabaseLoader implements LoaderInterface
{
    public function __construct(
        private readonly TraduccionLocalRepository $repository
    ) {
    }

    public function load(mixed $resource, string $locale, string $domain = 'messages'): MessageCatalogue
    {
        // Buscamos todas las traducciones en la DB para este idioma
        $translations = $this->repository->findBy(['locale' => $locale]);

        $catalogue = new MessageCatalogue($locale);

        foreach ($translations as $translation) {
            // Llenamos el catálogo de Symfony con los datos de tu tabla
            $catalogue->set($translation->getKeyName(), $translation->getContent(), $domain);
        }

        return $catalogue;
    }
}