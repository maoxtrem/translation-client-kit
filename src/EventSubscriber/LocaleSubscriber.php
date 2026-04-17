<?php

declare(strict_types=1);

namespace Maoxtrem\TranslationClientKit\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

final class LocaleSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly string $defaultLocale = 'es',
    ) {
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        $request = $event->getRequest();

        // Si no hay sesión previa (ej. peticiones de API sin estado), no hacemos nada
        if (!$request->hasPreviousSession()) {
            return;
        }

        // Intentamos ver si el usuario ya eligió un idioma en el SwitchLocaleController
        if ($locale = $request->getSession()->get('_locale')) {
            $request->setLocale($locale);
        } else {
            // Si no hay nada en sesión, usamos el valor por defecto o el parámetro de la URL
            $request->setLocale($request->query->get('_locale', $this->defaultLocale));
        }
    }

    public static function getSubscribedEvents(): array
    {
        return [
            // Debe ejecutarse con una prioridad alta (20) para estar antes que el LocaleListener de Symfony
            KernelEvents::REQUEST => [['onKernelRequest', 20]],
        ];
    }
}