<?php

declare(strict_types=1);

namespace Maoxtrem\TranslationClientKit\Controller\Admin;

use Maoxtrem\TranslationClientKit\Service\DownloadService;
use Maoxtrem\TranslationClientKit\Service\PushService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
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
            return $this->buildPushResponse($pushService->push());
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
            return $this->buildLegacyResponse($pushService->pushLegacyScan());
        } catch (\Throwable $exception) {
            return new JsonResponse([
                'success' => false,
                'error' => $exception->getMessage(),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    #[Route('/migrate-legacy/scan', name: 'kit_sync_legacy_scan', methods: ['POST'])]
    public function migrateLegacyScan(PushService $pushService): JsonResponse
    {
        try {
            return $this->buildLegacyResponse($pushService->pushLegacyScan());
        } catch (\Throwable $exception) {
            return new JsonResponse([
                'success' => false,
                'error' => $exception->getMessage(),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    #[Route('/migrate-legacy/send', name: 'kit_sync_legacy_send', methods: ['POST'])]
    public function migrateLegacySend(Request $request, PushService $pushService): JsonResponse
    {
        try {
            $payload = [];
            $raw = $request->getContent();
            if ($raw !== '') {
                $decoded = json_decode($raw, true);
                if (is_array($decoded)) {
                    $payload = $decoded;
                }
            }

            $chunk = (int) ($payload['chunk'] ?? 0);
            $chunkSize = isset($payload['chunk_size']) ? (int) $payload['chunk_size'] : null;
            if ($chunkSize !== null && $chunkSize <= 0) {
                $chunkSize = null;
            }

            return $this->buildLegacyResponse($pushService->pushLegacySendChunk($chunk, $chunkSize));
        } catch (\Throwable $exception) {
            return new JsonResponse([
                'success' => false,
                'error' => $exception->getMessage(),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    #[Route('/migrate-legacy/send-chunk', name: 'kit_sync_legacy_send_chunk', methods: ['POST'])]
    public function migrateLegacySendChunk(Request $request, PushService $pushService): JsonResponse
    {
        try {
            $payload = [];
            $raw = $request->getContent();
            if ($raw !== '') {
                $decoded = json_decode($raw, true);
                if (is_array($decoded)) {
                    $payload = $decoded;
                }
            }

            $chunk = (int) ($payload['chunk'] ?? 0);
            $chunkSize = isset($payload['chunk_size']) ? (int) $payload['chunk_size'] : null;
            if ($chunkSize !== null && $chunkSize <= 0) {
                $chunkSize = null;
            }

            return $this->buildLegacyResponse($pushService->pushLegacySendChunk($chunk, $chunkSize));
        } catch (\Throwable $exception) {
            return new JsonResponse([
                'success' => false,
                'error' => $exception->getMessage(),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * @param array<string, mixed> $result
     */
    private function buildPushResponse(array $result): JsonResponse
    {
        $pushScan = is_array($result['push_scan'] ?? null) ? $result['push_scan'] : [];
        $localFilter = is_array($result['local_filter'] ?? null) ? $result['local_filter'] : [];
        $phase1 = is_array($result['phase_1'] ?? null) ? $result['phase_1'] : [];
        $phase2 = is_array($result['phase_2'] ?? null) ? $result['phase_2'] : [];
        $count = (int) ($result['count'] ?? ($localFilter['sent_keys_count'] ?? 0));

        return new JsonResponse([
            'success' => true,
            'status' => (int) ($result['status'] ?? 200),
            'service' => (string) ($result['service'] ?? ''),
            'count' => $count,
            'keys' => array_values($result['keys'] ?? []),
            'message' => sprintf('%s (%d llaves)', (string) ($result['message'] ?? 'Push ejecutado.'), $count),
            'sent_keys_count' => (int) ($localFilter['sent_keys_count'] ?? $count),
            'not_sent_keys_count' => (int) ($localFilter['not_sent_keys_count'] ?? 0),
            'not_sent_local_existing_count' => (int) ($localFilter['not_sent_local_existing_count'] ?? 0),
            'not_sent_error_count' => (int) ($localFilter['not_sent_error_count'] ?? 0),
            'not_sent_keys' => array_values($localFilter['not_sent_keys'] ?? []),
            'push_scan' => [
                'files' => (int) ($pushScan['files'] ?? 0),
                'found' => (int) ($pushScan['found'] ?? 0),
                'unique' => (int) ($pushScan['unique'] ?? 0),
                'duplicated_keys_count' => (int) ($pushScan['duplicated_keys_count'] ?? 0),
                'deduplicated_occurrences' => (int) ($pushScan['deduplicated_occurrences'] ?? 0),
                'eligible' => (int) ($pushScan['eligible'] ?? ($localFilter['sent_keys_count'] ?? $count)),
                'ineligible' => (int) ($pushScan['ineligible'] ?? ($localFilter['not_sent_keys_count'] ?? 0)),
                'ineligible_keys' => array_values($pushScan['ineligible_keys'] ?? ($localFilter['not_sent_keys'] ?? [])),
            ],
            'phase_1' => [
                'message' => (string) ($phase1['message'] ?? ''),
                'sent_keys_count' => (int) ($phase1['sent_keys_count'] ?? ($localFilter['sent_keys_count'] ?? 0)),
                'not_sent_keys_count' => (int) ($phase1['not_sent_keys_count'] ?? ($localFilter['not_sent_keys_count'] ?? 0)),
                'not_sent_local_existing_count' => (int) ($phase1['not_sent_local_existing_count'] ?? ($localFilter['not_sent_local_existing_count'] ?? 0)),
                'not_sent_error_count' => (int) ($phase1['not_sent_error_count'] ?? ($localFilter['not_sent_error_count'] ?? 0)),
                'not_sent_keys' => array_values($phase1['not_sent_keys'] ?? ($localFilter['not_sent_keys'] ?? [])),
            ],
            'phase_2' => [
                'attempted' => (bool) ($phase2['attempted'] ?? false),
                'chunks' => (int) ($phase2['chunks'] ?? 0),
                'chunk_size' => (int) ($phase2['chunk_size'] ?? 0),
                'current_chunk' => (int) ($phase2['current_chunk'] ?? 0),
                'chunks_ok' => (int) ($phase2['chunks_ok'] ?? 0),
                'chunks_error' => (int) ($phase2['chunks_error'] ?? 0),
                'message' => (string) ($phase2['message'] ?? ''),
                'remote' => is_array($phase2['remote'] ?? null) ? $phase2['remote'] : [],
                'chunk_report' => is_array($phase2['chunk_report'] ?? null) ? $phase2['chunk_report'] : [],
                'chunk_reports' => array_values($phase2['chunk_reports'] ?? []),
            ],
            'remote' => is_array($result['remote'] ?? null) ? $result['remote'] : [],
        ]);
    }

    /**
     * @param array<string, mixed> $result
     */
    private function buildLegacyResponse(array $result): JsonResponse
    {
        $legacyScan = is_array($result['legacy_scan'] ?? null) ? $result['legacy_scan'] : [];
        $localFilter = is_array($result['local_filter'] ?? null) ? $result['local_filter'] : [];
        $phase1 = is_array($result['phase_1'] ?? null) ? $result['phase_1'] : [];
        $phase2 = is_array($result['phase_2'] ?? null) ? $result['phase_2'] : [];

        return new JsonResponse([
            'success' => true,
            'status' => (int) ($result['status'] ?? 200),
            'message' => sprintf('%s (%d semillas)', (string) ($result['message'] ?? 'Migracion ejecutada.'), (int) ($result['count'] ?? 0)),
            'sent_keys_count' => (int) ($localFilter['sent_keys_count'] ?? ($legacyScan['eligible'] ?? 0)),
            'not_sent_keys_count' => (int) ($localFilter['not_sent_keys_count'] ?? ($legacyScan['ineligible'] ?? 0)),
            'not_sent_local_existing_count' => (int) ($localFilter['not_sent_local_existing_count'] ?? 0),
            'not_sent_error_count' => (int) ($localFilter['not_sent_error_count'] ?? 0),
            'not_sent_keys' => array_values($localFilter['not_sent_keys'] ?? ($legacyScan['ineligible_keys'] ?? [])),
            'legacy_scan' => [
                'found' => (int) ($legacyScan['found'] ?? 0),
                'eligible' => (int) ($legacyScan['eligible'] ?? 0),
                'ineligible' => (int) ($legacyScan['ineligible'] ?? 0),
                'ineligible_keys' => array_values($legacyScan['ineligible_keys'] ?? []),
            ],
            'phase_1' => [
                'message' => (string) ($phase1['message'] ?? ''),
                'sent_keys_count' => (int) ($phase1['sent_keys_count'] ?? ($localFilter['sent_keys_count'] ?? 0)),
                'not_sent_keys_count' => (int) ($phase1['not_sent_keys_count'] ?? ($localFilter['not_sent_keys_count'] ?? 0)),
                'not_sent_local_existing_count' => (int) ($phase1['not_sent_local_existing_count'] ?? ($localFilter['not_sent_local_existing_count'] ?? 0)),
                'not_sent_error_count' => (int) ($phase1['not_sent_error_count'] ?? ($localFilter['not_sent_error_count'] ?? 0)),
                'not_sent_keys' => array_values($phase1['not_sent_keys'] ?? ($localFilter['not_sent_keys'] ?? [])),
            ],
            'phase_2' => [
                'attempted' => (bool) ($phase2['attempted'] ?? false),
                'chunks' => (int) ($phase2['chunks'] ?? 0),
                'chunk_size' => (int) ($phase2['chunk_size'] ?? 0),
                'current_chunk' => (int) ($phase2['current_chunk'] ?? 0),
                'chunks_ok' => (int) ($phase2['chunks_ok'] ?? 0),
                'chunks_error' => (int) ($phase2['chunks_error'] ?? 0),
                'message' => (string) ($phase2['message'] ?? ''),
                'remote' => is_array($phase2['remote'] ?? null) ? $phase2['remote'] : [],
                'chunk_report' => is_array($phase2['chunk_report'] ?? null) ? $phase2['chunk_report'] : [],
                'chunk_reports' => array_values($phase2['chunk_reports'] ?? []),
            ],
            'remote' => is_array($result['remote'] ?? null) ? $result['remote'] : [],
        ]);
    }
}
