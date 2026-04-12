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
                'keys' => array_values($result['keys'] ?? []),
                'dump_files' => array_values($result['dump_files'] ?? []),
                'locales' => array_values($result['locales'] ?? []),
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

            $llavesList = implode(', ', $result['keys'] ?? []);
            $nuevas = (int) (($result['remote']['nuevas'] ?? 0));
            $promovidas = (int) (($result['remote']['promovidas'] ?? 0));

            return new JsonResponse([
                'success' => true,
                'status' => (int) ($result['status'] ?? 200),
                'message' => sprintf(
                    'Se enviaron %d llaves. Cerebro registro nuevas=%d, promovidas=%d. Llaves: [%s]',
                    (int) ($result['count'] ?? 0),
                    $nuevas,
                    $promovidas,
                    $llavesList
                ),
            ]);
        } catch (\Throwable $exception) {
            return new JsonResponse([
                'success' => false,
                'error' => $exception->getMessage(),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    #[Route('/migrate-legacy', name: 'kit_sync_legacy', methods: ['POST'])]
    public function migrateLegacy(PushService $pushService): JsonResponse
    {
        try {
            $result = $pushService->pushLegacy();

            return new JsonResponse([
                'success' => true,
                'status' => (int) ($result['status'] ?? 200),
                'message' => sprintf('%s (%d semillas)', (string) ($result['message'] ?? 'Migracion ejecutada.'), (int) ($result['count'] ?? 0)),
            ]);
        } catch (\Throwable $exception) {
            return new JsonResponse([
                'success' => false,
                'error' => $exception->getMessage(),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
}
