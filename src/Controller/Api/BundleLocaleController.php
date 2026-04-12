<?php
declare(strict_types=1);
namespace Maoxtrem\TranslationClientKit\Controller\Api;
use Maoxtrem\TranslationClientKit\Repository\TraduccionLocalRepository;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

final class BundleLocaleController {
    public function __construct(private readonly TraduccionLocalRepository $repository) {}
    #[Route('/api/v2/client-kit/locales', name: 'api_v2_client_kit_locales', methods: ['GET'])]
    public function __invoke(): JsonResponse {
        return new JsonResponse(['status' => 'success', 'data' => $this->repository->findAvailableLocales()]);
    }
}