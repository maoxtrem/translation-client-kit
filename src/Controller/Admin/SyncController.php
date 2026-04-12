<?php

declare(strict_types=1);

namespace Maoxtrem\TranslationClientKit\Controller\Admin;

use Maoxtrem\TranslationClientKit\Service\DownloadService;
use Maoxtrem\TranslationClientKit\Service\PushService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/admin/translations')]
final class SyncController extends AbstractController
{
    #[Route('/sync', name: 'kit_sync_index', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('@TranslationClient/admin/sync.html.twig');
    }

    #[Route('/pull', name: 'kit_sync_pull', methods: ['POST'])]
    public function pull(DownloadService $downloadService): JsonResponse
    {
        try {
            $result = $downloadService->download();

            return new JsonResponse([
                'success' => true,
                'message' => sprintf('Descargadas %d etiquetas.', (int) ($result['count'] ?? 0)),
            ]);
        } catch (\Throwable $exception) {
            return new JsonResponse([
                'success' => false,
                'error' => $exception->getMessage(),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    #[Route('/push', name: 'kit_sync_push', methods: ['POST'])]
    public function push(PushService $pushService): JsonResponse
    {
        try {
            $result = $pushService->push();

            return new JsonResponse([
                'success' => true,
                'message' => sprintf('Se enviaron %d llaves al Cerebro.', (int) ($result['count'] ?? 0)),
            ]);
        } catch (\Throwable $exception) {
            return new JsonResponse([
                'success' => false,
                'error' => $exception->getMessage(),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
}
