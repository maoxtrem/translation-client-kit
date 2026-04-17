<?php
declare(strict_types=1);
namespace Maoxtrem\TranslationClientKit\Controller;
use Symfony\Component\HttpFoundation\{RedirectResponse, Request, RequestStack};
use Symfony\Component\Routing\Attribute\Route;

final class SwitchLocaleController {
    public function __construct(private readonly RequestStack $requestStack) {}
    #[Route('/switch-locale/{_locale}', name: 'kit_switch_locale', methods: ['GET'])]
    public function __invoke(Request $request, string $_locale): RedirectResponse {
        $this->requestStack->getSession()?->set('_locale', strtolower(trim($_locale)));
        return new RedirectResponse($request->headers->get('referer', '/'));
    }
}