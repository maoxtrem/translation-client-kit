<?php

declare(strict_types=1);

namespace Maoxtrem\TranslationClientKit\Controller\Admin;

use Maoxtrem\TranslationClientKit\Service\DownloadService;
use Maoxtrem\TranslationClientKit\Service\PushService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\HttpClient\Exception\HttpExceptionInterface;

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

            // ... tu lógica de éxito actual ...
            return new JsonResponse([
                'success' => true,
                'message' => 'Éxito...',
            ]);
        } catch (HttpExceptionInterface $exception) {
            // ¡ESTA ES LA CLAVE! 
            // Obtenemos la respuesta que envió el servidor antes de morir
            $response = $exception->getResponse();

            // Convertimos el JSON del error en un array (usamos false para que no lance otra excepción)
            $errorData = $response->toArray(false);

            return new JsonResponse([
                'success' => false,
                'status' => $response->getStatusCode(),
                'error' => 'Error en el Cerebro: ' . ($errorData['mensaje_real'] ?? $errorData['error'] ?? 'Error desconocido'),
                'detalle' => $errorData, // Aquí verás todo el JSON chismoso que enviamos
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        } catch (\Throwable $exception) {
            // Errores locales del VPS (archivo no encontrado, problema de memoria, etc.)
            return new JsonResponse([
                'success' => false,
                'error' => 'Error Local en VPS: ' . $exception->getMessage(),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    #[Route('/migrate-legacy', name: 'kit_sync_legacy', methods: ['POST'])]
    public function migrateLegacy(PushService $pushService): JsonResponse
    {
        try {
            $result = $pushService->pushLegacy();
            $legacyScan = is_array($result['legacy_scan'] ?? null) ? $result['legacy_scan'] : [];
            $localFilter = is_array($result['local_filter'] ?? null) ? $result['local_filter'] : [];

            return new JsonResponse([
                'success' => true,
                'status' => (int) ($result['status'] ?? 200),
                'message' => sprintf('%s (%d semillas)', (string) ($result['message'] ?? 'Migracion ejecutada.'), (int) ($result['count'] ?? 0)),
                'sent_keys_count' => (int) ($localFilter['sent_keys_count'] ?? ($legacyScan['eligible'] ?? 0)),
                'not_sent_keys_count' => (int) ($localFilter['not_sent_keys_count'] ?? ($legacyScan['ineligible'] ?? 0)),
                'not_sent_keys' => array_values($localFilter['not_sent_keys'] ?? ($legacyScan['ineligible_keys'] ?? [])),
                'legacy_scan' => [
                    'found' => (int) ($legacyScan['found'] ?? 0),
                    'eligible' => (int) ($legacyScan['eligible'] ?? 0),
                    'ineligible' => (int) ($legacyScan['ineligible'] ?? 0),
                    'ineligible_keys' => array_values($legacyScan['ineligible_keys'] ?? []),
                ],
                'remote' => is_array($result['remote'] ?? null) ? $result['remote'] : [],
            ]);
        } catch (\Throwable $exception) {
            return new JsonResponse([
                'success' => false,
                'error' => $exception->getMessage(),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
}
