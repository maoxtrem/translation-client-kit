<?php

declare(strict_types=1);

namespace Maoxtrem\TranslationClientKit\Service;

use Maoxtrem\TranslationClientKit\Repository\TraduccionLocalRepository;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class PushService
{
    private const ENV_SYNC_CHUNK_SIZE = 'TRADUCCIONES_SYNC_CHUNK_SIZE';
    private const DEFAULT_SYNC_CHUNK_SIZE = 200;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly TranslationScanner $scanner,
        private readonly TraduccionLocalRepository $traduccionLocalRepository,
    ) {}

    public function push(): array
    {
        $prepared = $this->buildPushPreparedData();
        $serviceName = (string) $prepared['service'];
        $chunkSize = (int) $prepared['chunk_size'];
        $sendableKeys = array_values($prepared['sendable_keys']);
        $notSentKeys = array_values($prepared['not_sent_keys']);
        $sentKeysCount = (int) $prepared['sent_keys_count'];
        $notSentKeysCount = (int) $prepared['not_sent_keys_count'];
        $notSentLocalExistingCount = (int) $prepared['not_sent_local_existing_count'];
        $notSentErrorCount = (int) $prepared['not_sent_error_count'];
        $scanStats = is_array($prepared['scan_stats']) ? $prepared['scan_stats'] : [];

        if ($sendableKeys === []) {
            return [
                'status' => 200,
                'count' => 0,
                'service' => $serviceName,
                'keys' => [],
                'message' => 'Fase 1 completada. No hay llaves aptas para enviar al Cerebro.',
                'local_filter' => [
                    'sent_keys_count' => 0,
                    'not_sent_keys_count' => $notSentKeysCount,
                    'not_sent_local_existing_count' => $notSentLocalExistingCount,
                    'not_sent_error_count' => $notSentErrorCount,
                    'not_sent_keys' => $notSentKeys,
                ],
                'push_scan' => [
                    'files' => (int) ($scanStats['files'] ?? 0),
                    'found' => (int) ($scanStats['found'] ?? 0),
                    'unique' => (int) ($scanStats['unique'] ?? 0),
                    'duplicated_keys_count' => (int) ($scanStats['duplicated_keys_count'] ?? 0),
                    'deduplicated_occurrences' => (int) ($scanStats['deduplicated_occurrences'] ?? 0),
                    'eligible' => (int) ($scanStats['eligible'] ?? 0),
                    'ineligible' => (int) ($scanStats['ineligible'] ?? 0),
                    'ineligible_keys' => $notSentKeys,
                ],
                'phase_1' => [
                    'message' => sprintf(
                        'Fase 1 (cliente): se enviaran 0 llaves y no se enviaran %d.',
                        $notSentKeysCount
                    ),
                    'sent_keys_count' => 0,
                    'not_sent_keys_count' => $notSentKeysCount,
                    'not_sent_local_existing_count' => $notSentLocalExistingCount,
                    'not_sent_error_count' => $notSentErrorCount,
                    'not_sent_keys' => $notSentKeys,
                ],
                'phase_2' => [
                    'attempted' => false,
                    'chunks' => 0,
                    'chunk_size' => $chunkSize,
                    'chunks_ok' => 0,
                    'chunks_error' => 0,
                    'message' => 'Fase 2 (cerebro): no se ejecuta porque no hay llaves aptas.',
                    'remote' => [],
                    'chunk_reports' => [],
                ],
                'remote' => [],
            ];
        }

        $chunks = array_chunk($sendableKeys, $chunkSize);
        $totalNuevas = 0;
        $totalPromovidas = 0;
        $lastStatus = 200;
        $successChunks = 0;
        $errorChunks = 0;
        $sentSoFar = 0;
        $chunkReports = [];

        foreach ($chunks as $index => $chunk) {
            $chunkKeys = array_values(array_unique($chunk));
            $attempt = $this->requestSyncSafe([
                'service' => $serviceName,
                'project_code' => $serviceName,
                'keys' => $chunkKeys,
            ]);
            $status = (int) ($attempt['status'] ?? 0);
            $payload = is_array($attempt['payload'] ?? null) ? $attempt['payload'] : [];
            $chunkKeysCount = count($chunkKeys);
            $sentSoFar += $chunkKeysCount;

            if ((bool) ($attempt['ok'] ?? false)) {
                ++$successChunks;
                $lastStatus = $status;
                $totalNuevas += (int) ($payload['nuevas'] ?? 0);
                $totalPromovidas += (int) ($payload['promovidas'] ?? 0);
                $chunkReports[] = [
                    'chunk' => $index + 1,
                    'total_chunks' => count($chunks),
                    'keys_in_chunk' => $chunkKeysCount,
                    'sent_so_far' => $sentSoFar,
                    'status' => $status,
                    'ok' => true,
                    'error' => null,
                    'nuevas' => (int) ($payload['nuevas'] ?? 0),
                    'promovidas' => (int) ($payload['promovidas'] ?? 0),
                    'message' => sprintf(
                        'Paquete %d/%d enviado (%d llaves). Acumulado enviado: %d.',
                        $index + 1,
                        count($chunks),
                        $chunkKeysCount,
                        $sentSoFar
                    ),
                ];
                continue;
            }

            ++$errorChunks;
            $errorMessage = (string) ($attempt['error'] ?? 'Error desconocido al enviar paquete.');
            $chunkReports[] = [
                'chunk' => $index + 1,
                'total_chunks' => count($chunks),
                'keys_in_chunk' => $chunkKeysCount,
                'sent_so_far' => $sentSoFar,
                'status' => $status,
                'ok' => false,
                'error' => $errorMessage,
                'nuevas' => 0,
                'promovidas' => 0,
                'message' => sprintf(
                    'Paquete %d/%d con error (%d llaves). Se continua con el siguiente. Error: %s',
                    $index + 1,
                    count($chunks),
                    $chunkKeysCount,
                    $errorMessage
                ),
            ];
        }

        return [
            'status' => $lastStatus,
            'count' => count($sendableKeys),
            'service' => $serviceName,
            'keys' => $sendableKeys,
            'remote' => [
                'nuevas' => $totalNuevas,
                'promovidas' => $totalPromovidas,
                'paquetes' => count($chunks),
                'paquetes_exitosos' => $successChunks,
                'paquetes_con_error' => $errorChunks,
                'chunk_size' => $chunkSize,
            ],
            'local_filter' => [
                'sent_keys_count' => $sentKeysCount,
                'not_sent_keys_count' => $notSentKeysCount,
                'not_sent_local_existing_count' => $notSentLocalExistingCount,
                'not_sent_error_count' => $notSentErrorCount,
                'not_sent_keys' => $notSentKeys,
            ],
            'push_scan' => [
                'files' => (int) ($scanStats['files'] ?? 0),
                'found' => (int) ($scanStats['found'] ?? 0),
                'unique' => (int) ($scanStats['unique'] ?? 0),
                'duplicated_keys_count' => (int) ($scanStats['duplicated_keys_count'] ?? 0),
                'deduplicated_occurrences' => (int) ($scanStats['deduplicated_occurrences'] ?? 0),
                'eligible' => (int) ($scanStats['eligible'] ?? 0),
                'ineligible' => (int) ($scanStats['ineligible'] ?? 0),
                'ineligible_keys' => $notSentKeys,
            ],
            'phase_1' => [
                'message' => sprintf(
                    'Fase 1 (cliente): se enviaran %d llaves y no se enviaran %d.',
                    $sentKeysCount,
                    $notSentKeysCount
                ),
                'sent_keys_count' => $sentKeysCount,
                'not_sent_keys_count' => $notSentKeysCount,
                'not_sent_local_existing_count' => $notSentLocalExistingCount,
                'not_sent_error_count' => $notSentErrorCount,
                'not_sent_keys' => $notSentKeys,
            ],
            'phase_2' => [
                'attempted' => true,
                'chunks' => count($chunks),
                'chunk_size' => $chunkSize,
                'chunks_ok' => $successChunks,
                'chunks_error' => $errorChunks,
                'message' => sprintf(
                    'Fase 2 (cerebro): se intentaron enviar %d llaves en %d paquetes de %d. Exitosos: %d, con error: %d.',
                    $sentKeysCount,
                    count($chunks),
                    $chunkSize,
                    $successChunks,
                    $errorChunks
                ),
                'remote' => [
                    'nuevas' => $totalNuevas,
                    'promovidas' => $totalPromovidas,
                    'paquetes' => count($chunks),
                    'paquetes_exitosos' => $successChunks,
                    'paquetes_con_error' => $errorChunks,
                    'chunk_size' => $chunkSize,
                ],
                'chunk_reports' => $chunkReports,
            ],
            'message' => sprintf(
                'Push terminado. Paquetes: %d, exitosos: %d, con error: %d.',
                count($chunks),
                $successChunks,
                $errorChunks
            ),
        ];
    }

    public function pushLegacy(): array
    {
        return $this->pushLegacySend();
    }

    public function pushLegacyScan(): array
    {
        $prepared = $this->buildLegacyPreparedData();
        $serviceName = (string) $prepared['service'];
        $chunkSize = (int) $prepared['chunk_size'];
        $sendableSeeds = array_values($prepared['sendable_seeds']);
        $notSentKeys = array_values($prepared['not_sent_keys']);
        $sentKeysCount = (int) $prepared['sent_keys_count'];
        $notSentKeysCount = (int) $prepared['not_sent_keys_count'];
        $notSentLocalExistingCount = (int) $prepared['not_sent_local_existing_count'];
        $notSentErrorCount = (int) $prepared['not_sent_error_count'];
        $scanStats = is_array($prepared['scan_stats']) ? $prepared['scan_stats'] : ['found' => 0, 'eligible' => 0, 'ineligible' => 0];
        $totalChunks = $sentKeysCount > 0 ? count(array_chunk($sendableSeeds, $chunkSize)) : 0;

        return [
            'status' => 200,
            'count' => $sentKeysCount,
            'service' => $serviceName,
            'message' => sprintf(
                'Fase 1 completada. %d llaves aptas para envio y %d no aptas.',
                $sentKeysCount,
                $notSentKeysCount
            ),
            'local_filter' => [
                'sent_keys_count' => $sentKeysCount,
                'not_sent_keys_count' => $notSentKeysCount,
                'not_sent_local_existing_count' => $notSentLocalExistingCount,
                'not_sent_error_count' => $notSentErrorCount,
                'not_sent_keys' => $notSentKeys,
            ],
            'legacy_scan' => [
                'found' => (int) ($scanStats['found'] ?? 0),
                'eligible' => (int) ($scanStats['eligible'] ?? 0),
                'ineligible' => (int) ($scanStats['ineligible'] ?? 0),
                'ineligible_keys' => $notSentKeys,
            ],
            'phase_1' => [
                'message' => sprintf(
                    'Fase 1 (cliente): se enviaran %d llaves y no se enviaran %d por no cumplir requisitos.',
                    $sentKeysCount,
                    $notSentKeysCount
                ),
                'sent_keys_count' => $sentKeysCount,
                'not_sent_keys_count' => $notSentKeysCount,
                'not_sent_local_existing_count' => $notSentLocalExistingCount,
                'not_sent_error_count' => $notSentErrorCount,
                'not_sent_keys' => $notSentKeys,
            ],
            'phase_2' => [
                'attempted' => false,
                'chunks' => $totalChunks,
                'chunk_size' => $chunkSize,
                'message' => 'Fase 2 (cerebro): pendiente de envio.',
                'remote' => [],
                'chunk_reports' => [],
            ],
            'remote' => [],
        ];
    }

    public function pushLegacySendChunk(int $chunkNumber, ?int $requestedChunkSize = null): array
    {
        $prepared = $this->buildLegacyPreparedData($requestedChunkSize);
        $serviceName = (string) $prepared['service'];
        $chunkSize = (int) $prepared['chunk_size'];
        $allSeeds = array_values($prepared['sendable_seeds']);
        $notSentKeys = array_values($prepared['not_sent_keys']);
        $sentKeysCount = (int) $prepared['sent_keys_count'];
        $notSentKeysCount = (int) $prepared['not_sent_keys_count'];
        $notSentLocalExistingCount = (int) $prepared['not_sent_local_existing_count'];
        $notSentErrorCount = (int) $prepared['not_sent_error_count'];
        $scanStats = is_array($prepared['scan_stats']) ? $prepared['scan_stats'] : ['found' => 0, 'eligible' => 0, 'ineligible' => 0];

        $chunks = $sentKeysCount > 0 ? array_chunk($allSeeds, $chunkSize) : [];
        $totalChunks = count($chunks);

        if ($totalChunks === 0) {
            return [
                'status' => 200,
                'count' => 0,
                'service' => $serviceName,
                'message' => 'No hay llaves validas para enviar.',
                'local_filter' => [
                    'sent_keys_count' => 0,
                    'not_sent_keys_count' => $notSentKeysCount,
                    'not_sent_local_existing_count' => $notSentLocalExistingCount,
                    'not_sent_error_count' => $notSentErrorCount,
                    'not_sent_keys' => $notSentKeys,
                ],
                'legacy_scan' => [
                    'found' => (int) ($scanStats['found'] ?? 0),
                    'eligible' => (int) ($scanStats['eligible'] ?? 0),
                    'ineligible' => (int) ($scanStats['ineligible'] ?? 0),
                    'ineligible_keys' => $notSentKeys,
                ],
                'phase_1' => [
                    'message' => sprintf(
                        'Fase 1 (cliente): se enviaran 0 llaves y no se enviaran %d por no cumplir requisitos.',
                        $notSentKeysCount
                    ),
                    'sent_keys_count' => 0,
                    'not_sent_keys_count' => $notSentKeysCount,
                    'not_sent_local_existing_count' => $notSentLocalExistingCount,
                    'not_sent_error_count' => $notSentErrorCount,
                    'not_sent_keys' => $notSentKeys,
                ],
                'phase_2' => [
                    'attempted' => false,
                    'chunks' => 0,
                    'chunk_size' => $chunkSize,
                    'current_chunk' => 0,
                    'chunks_ok' => 0,
                    'chunks_error' => 0,
                    'message' => 'Fase 2 (cerebro): no se ejecuta porque no hay llaves aptas.',
                    'remote' => [],
                    'chunk_report' => null,
                    'chunk_reports' => [],
                ],
                'remote' => [],
            ];
        }

        if ($chunkNumber < 1 || $chunkNumber > $totalChunks) {
            $error = sprintf('Paquete fuera de rango. Debe estar entre 1 y %d.', $totalChunks);
            $chunkReport = [
                'chunk' => $chunkNumber,
                'total_chunks' => $totalChunks,
                'keys_in_chunk' => 0,
                'sent_so_far' => 0,
                'status' => 0,
                'ok' => false,
                'error' => $error,
                'nuevas' => 0,
                'promovidas' => 0,
                'message' => $error,
            ];

            return [
                'status' => 200,
                'count' => $sentKeysCount,
                'service' => $serviceName,
                'message' => $error,
                'local_filter' => [
                    'sent_keys_count' => $sentKeysCount,
                    'not_sent_keys_count' => $notSentKeysCount,
                    'not_sent_local_existing_count' => $notSentLocalExistingCount,
                    'not_sent_error_count' => $notSentErrorCount,
                    'not_sent_keys' => $notSentKeys,
                ],
                'legacy_scan' => [
                    'found' => (int) ($scanStats['found'] ?? 0),
                    'eligible' => (int) ($scanStats['eligible'] ?? 0),
                    'ineligible' => (int) ($scanStats['ineligible'] ?? 0),
                    'ineligible_keys' => $notSentKeys,
                ],
                'phase_1' => [
                    'message' => sprintf(
                        'Fase 1 (cliente): se enviaran %d llaves y no se enviaran %d por no cumplir requisitos.',
                        $sentKeysCount,
                        $notSentKeysCount
                    ),
                    'sent_keys_count' => $sentKeysCount,
                    'not_sent_keys_count' => $notSentKeysCount,
                    'not_sent_local_existing_count' => $notSentLocalExistingCount,
                    'not_sent_error_count' => $notSentErrorCount,
                    'not_sent_keys' => $notSentKeys,
                ],
                'phase_2' => [
                    'attempted' => true,
                    'chunks' => $totalChunks,
                    'chunk_size' => $chunkSize,
                    'current_chunk' => $chunkNumber,
                    'chunks_ok' => 0,
                    'chunks_error' => 1,
                    'message' => $error,
                    'remote' => [
                        'nuevas' => 0,
                        'promovidas' => 0,
                        'paquetes' => $totalChunks,
                        'chunk_size' => $chunkSize,
                    ],
                    'chunk_report' => $chunkReport,
                    'chunk_reports' => [$chunkReport],
                ],
                'remote' => [
                    'nuevas' => 0,
                    'promovidas' => 0,
                    'paquetes' => $totalChunks,
                    'chunk_size' => $chunkSize,
                ],
            ];
        }

        /** @var array<int, array{key: string, content: string, locale: string}> $chunk */
        $chunk = $chunks[$chunkNumber - 1];
        $chunkKeys = array_values(array_unique(array_column($chunk, 'key')));
        $chunkKeysCount = count($chunkKeys);
        $sentSoFar = min($chunkNumber * $chunkSize, $sentKeysCount);

        $attempt = $this->requestSyncSafe([
            'service' => $serviceName,
            'project_code' => $serviceName,
            'keys' => $chunkKeys,
            'seeds' => $chunk,
        ]);
        $status = (int) ($attempt['status'] ?? 0);
        $payload = is_array($attempt['payload'] ?? null) ? $attempt['payload'] : [];
        $ok = (bool) ($attempt['ok'] ?? false);
        $nuevas = $ok ? (int) ($payload['nuevas'] ?? 0) : 0;
        $promovidas = $ok ? (int) ($payload['promovidas'] ?? 0) : 0;
        $error = $ok ? null : (string) ($attempt['error'] ?? 'Error desconocido al enviar paquete.');

        $chunkReport = [
            'chunk' => $chunkNumber,
            'total_chunks' => $totalChunks,
            'keys_in_chunk' => $chunkKeysCount,
            'sent_so_far' => $sentSoFar,
            'status' => $status,
            'ok' => $ok,
            'error' => $error,
            'nuevas' => $nuevas,
            'promovidas' => $promovidas,
            'message' => $ok
                ? sprintf('Paquete %d/%d enviado (%d llaves).', $chunkNumber, $totalChunks, $chunkKeysCount)
                : sprintf('Paquete %d/%d con error (%d llaves).', $chunkNumber, $totalChunks, $chunkKeysCount),
        ];

        return [
            'status' => $ok ? ($status > 0 ? $status : 200) : 200,
            'count' => $sentKeysCount,
            'service' => $serviceName,
            'message' => $ok
                ? sprintf('Paquete %d/%d procesado correctamente.', $chunkNumber, $totalChunks)
                : sprintf('Paquete %d/%d procesado con error; se puede continuar.', $chunkNumber, $totalChunks),
            'local_filter' => [
                'sent_keys_count' => $sentKeysCount,
                'not_sent_keys_count' => $notSentKeysCount,
                'not_sent_local_existing_count' => $notSentLocalExistingCount,
                'not_sent_error_count' => $notSentErrorCount,
                'not_sent_keys' => $notSentKeys,
            ],
            'legacy_scan' => [
                'found' => (int) ($scanStats['found'] ?? 0),
                'eligible' => (int) ($scanStats['eligible'] ?? 0),
                'ineligible' => (int) ($scanStats['ineligible'] ?? 0),
                'ineligible_keys' => $notSentKeys,
            ],
            'phase_1' => [
                'message' => sprintf(
                    'Fase 1 (cliente): se enviaran %d llaves y no se enviaran %d por no cumplir requisitos.',
                    $sentKeysCount,
                    $notSentKeysCount
                ),
                'sent_keys_count' => $sentKeysCount,
                'not_sent_keys_count' => $notSentKeysCount,
                'not_sent_local_existing_count' => $notSentLocalExistingCount,
                'not_sent_error_count' => $notSentErrorCount,
                'not_sent_keys' => $notSentKeys,
            ],
            'phase_2' => [
                'attempted' => true,
                'chunks' => $totalChunks,
                'chunk_size' => $chunkSize,
                'current_chunk' => $chunkNumber,
                'chunks_ok' => $ok ? 1 : 0,
                'chunks_error' => $ok ? 0 : 1,
                'message' => $chunkReport['message'],
                'remote' => [
                    'nuevas' => $nuevas,
                    'promovidas' => $promovidas,
                    'paquetes' => $totalChunks,
                    'chunk_size' => $chunkSize,
                ],
                'chunk_report' => $chunkReport,
                'chunk_reports' => [$chunkReport],
            ],
            'remote' => [
                'nuevas' => $nuevas,
                'promovidas' => $promovidas,
                'paquetes' => $totalChunks,
                'chunk_size' => $chunkSize,
            ],
        ];
    }

    public function pushLegacySend(): array
    {
        $prepared = $this->buildLegacyPreparedData();
        $serviceName = (string) $prepared['service'];
        $chunkSize = (int) $prepared['chunk_size'];
        $allSeeds = array_values($prepared['sendable_seeds']);
        $notSentKeys = array_values($prepared['not_sent_keys']);
        $sentKeysCount = (int) $prepared['sent_keys_count'];
        $notSentKeysCount = (int) $prepared['not_sent_keys_count'];
        $notSentLocalExistingCount = (int) $prepared['not_sent_local_existing_count'];
        $notSentErrorCount = (int) $prepared['not_sent_error_count'];
        $scanStats = is_array($prepared['scan_stats']) ? $prepared['scan_stats'] : ['found' => 0, 'eligible' => 0, 'ineligible' => 0];

        if ($allSeeds === []) {
            return [
                'status' => 200,
                'count' => 0,
                'service' => $serviceName,
                'message' => 'Fase 1 completada. No hay llaves aptas para enviar al Cerebro.',
                'local_filter' => [
                    'sent_keys_count' => 0,
                    'not_sent_keys_count' => $notSentKeysCount,
                    'not_sent_local_existing_count' => $notSentLocalExistingCount,
                    'not_sent_error_count' => $notSentErrorCount,
                    'not_sent_keys' => $notSentKeys,
                ],
                'legacy_scan' => [
                    'found' => (int) ($scanStats['found'] ?? 0),
                    'eligible' => (int) ($scanStats['eligible'] ?? 0),
                    'ineligible' => (int) ($scanStats['ineligible'] ?? 0),
                    'ineligible_keys' => $notSentKeys,
                ],
                'phase_1' => [
                    'message' => sprintf(
                        'Fase 1 (cliente): se enviaran 0 llaves y no se enviaran %d por no cumplir requisitos.',
                        $notSentKeysCount
                    ),
                    'sent_keys_count' => 0,
                    'not_sent_keys_count' => $notSentKeysCount,
                    'not_sent_local_existing_count' => $notSentLocalExistingCount,
                    'not_sent_error_count' => $notSentErrorCount,
                    'not_sent_keys' => $notSentKeys,
                ],
                'phase_2' => [
                    'attempted' => false,
                    'chunks' => 0,
                    'chunk_size' => $chunkSize,
                    'chunks_ok' => 0,
                    'chunks_error' => 0,
                    'message' => 'Fase 2 (cerebro): no se ejecuta porque no hay llaves aptas.',
                    'remote' => [],
                    'chunk_reports' => [],
                ],
                'remote' => [],
            ];
        }

        // El legado se divide por tamaño de paquete configurable
        $chunks = array_chunk($allSeeds, $chunkSize);
        
        $totalNuevas = 0;
        $totalPromovidas = 0;
        $lastStatus = 200;
        $successChunks = 0;
        $errorChunks = 0;
        $sentSoFar = 0;
        $chunkReports = [];

        foreach ($chunks as $index => $chunk) {
            $chunkKeys = array_values(array_unique(array_column($chunk, 'key')));
            $attempt = $this->requestSyncSafe([
                'service' => $serviceName,
                'project_code' => $serviceName,
                'keys' => $chunkKeys,
                'seeds' => $chunk,
            ]);
            $status = (int) ($attempt['status'] ?? 0);
            $payload = is_array($attempt['payload'] ?? null) ? $attempt['payload'] : [];
            $chunkKeysCount = count($chunkKeys);
            $sentSoFar += $chunkKeysCount;

            if ((bool) ($attempt['ok'] ?? false)) {
                ++$successChunks;
                $lastStatus = $status;
                $totalNuevas += (int) ($payload['nuevas'] ?? 0);
                $totalPromovidas += (int) ($payload['promovidas'] ?? 0);
                $chunkReports[] = [
                    'chunk' => $index + 1,
                    'total_chunks' => count($chunks),
                    'keys_in_chunk' => $chunkKeysCount,
                    'sent_so_far' => $sentSoFar,
                    'status' => $status,
                    'ok' => true,
                    'error' => null,
                    'nuevas' => (int) ($payload['nuevas'] ?? 0),
                    'promovidas' => (int) ($payload['promovidas'] ?? 0),
                    'message' => sprintf(
                        'Paquete %d/%d enviado (%d llaves). Acumulado enviado: %d.',
                        $index + 1,
                        count($chunks),
                        $chunkKeysCount,
                        $sentSoFar
                    ),
                ];
                continue;
            }

            ++$errorChunks;
            $errorMessage = (string) ($attempt['error'] ?? 'Error desconocido al enviar paquete.');
            $chunkReports[] = [
                'chunk' => $index + 1,
                'total_chunks' => count($chunks),
                'keys_in_chunk' => $chunkKeysCount,
                'sent_so_far' => $sentSoFar,
                'status' => $status,
                'ok' => false,
                'error' => $errorMessage,
                'nuevas' => 0,
                'promovidas' => 0,
                'message' => sprintf(
                    'Paquete %d/%d con error (%d llaves). Se continua con el siguiente. Error: %s',
                    $index + 1,
                    count($chunks),
                    $chunkKeysCount,
                    $errorMessage
                ),
            ];
        }

        return [
            'status' => $lastStatus,
            'count' => count($allSeeds),
            'service' => $serviceName,
            'remote' => [
                'nuevas' => $totalNuevas,
                'promovidas' => $totalPromovidas,
                'paquetes' => count($chunks),
                'paquetes_exitosos' => $successChunks,
                'paquetes_con_error' => $errorChunks,
                'chunk_size' => $chunkSize,
            ],
            'local_filter' => [
                'sent_keys_count' => $sentKeysCount,
                'not_sent_keys_count' => $notSentKeysCount,
                'not_sent_local_existing_count' => $notSentLocalExistingCount,
                'not_sent_error_count' => $notSentErrorCount,
                'not_sent_keys' => $notSentKeys,
            ],
            'legacy_scan' => [
                'found' => (int) ($scanStats['found'] ?? 0),
                'eligible' => (int) ($scanStats['eligible'] ?? 0),
                'ineligible' => (int) ($scanStats['ineligible'] ?? 0),
                'ineligible_keys' => $notSentKeys,
            ],
            'phase_1' => [
                'message' => sprintf(
                    'Fase 1 (cliente): se enviaran %d llaves y no se enviaran %d por no cumplir requisitos.',
                    $sentKeysCount,
                    $notSentKeysCount
                ),
                'sent_keys_count' => $sentKeysCount,
                'not_sent_keys_count' => $notSentKeysCount,
                'not_sent_local_existing_count' => $notSentLocalExistingCount,
                'not_sent_error_count' => $notSentErrorCount,
                'not_sent_keys' => $notSentKeys,
            ],
            'phase_2' => [
                'attempted' => true,
                'chunks' => count($chunks),
                'chunk_size' => $chunkSize,
                'chunks_ok' => $successChunks,
                'chunks_error' => $errorChunks,
                'message' => sprintf(
                    'Fase 2 (cerebro): se intentaron enviar %d llaves en %d paquetes de %d. Exitosos: %d, con error: %d.',
                    $sentKeysCount,
                    count($chunks),
                    $chunkSize,
                    $successChunks,
                    $errorChunks
                ),
                'remote' => [
                    'nuevas' => $totalNuevas,
                    'promovidas' => $totalPromovidas,
                    'paquetes' => count($chunks),
                    'paquetes_exitosos' => $successChunks,
                    'paquetes_con_error' => $errorChunks,
                    'chunk_size' => $chunkSize,
                ],
                'chunk_reports' => $chunkReports,
            ],
            'message' => sprintf(
                'Migración de legado terminada. Paquetes: %d, exitosos: %d, con error: %d.',
                count($chunks),
                $successChunks,
                $errorChunks
            ),
        ];
    }

    /**
     * @return array{
     *   service: string,
     *   chunk_size: int,
     *   sendable_keys: array<int, string>,
     *   not_sent_keys: array<int, array{key: string, reason: string, file: string}>,
     *   not_sent_local_existing_count: int,
     *   not_sent_error_count: int,
     *   sent_keys_count: int,
     *   not_sent_keys_count: int,
     *   scan_stats: array{
     *     files: int,
     *     found: int,
     *     unique: int,
     *     deduplicated_occurrences: int,
     *     duplicated_keys_count: int,
     *     eligible: int,
     *     ineligible: int
     *   }
     * }
     */
    private function buildPushPreparedData(?int $requestedChunkSize = null): array
    {
        $serviceName = $this->getServiceName();
        $chunkSize = $this->getSyncChunkSize($requestedChunkSize);

        $report = $this->scanner->extractRuntimeTranslationsReport();
        /** @var array<int, array{key: string, file: string}> $entries */
        $entries = array_values($report['entries'] ?? []);
        $rawStats = is_array($report['stats'] ?? null) ? $report['stats'] : [];

        /** @var array<string, bool> $candidateKeysIndex */
        $candidateKeysIndex = [];
        foreach ($entries as $entry) {
            [$normalizedKey, $invalidReason] = $this->analyzePushKey((string) ($entry['key'] ?? ''));
            if ($invalidReason !== null || $normalizedKey === '') {
                continue;
            }

            $candidateKeysIndex[$normalizedKey] = true;
        }
        $candidateKeys = array_keys($candidateKeysIndex);
        $existingKeys = $this->traduccionLocalRepository->findExistingKeys($candidateKeys);

        /** @var array<int, string> $sendableKeys */
        $sendableKeys = [];
        /** @var array<int, array{key: string, reason: string, file: string}> $notSentKeys */
        $notSentKeys = [];
        /** @var array<string, bool> $acceptedKeys */
        $acceptedKeys = [];
        /** @var array<string, bool> $duplicatedAcceptedKeys */
        $duplicatedAcceptedKeys = [];
        /** @var array<string, bool> $localCountedKeys */
        $localCountedKeys = [];
        $localNotSentCount = 0;
        $scannerNotSentCount = 0;
        $deduplicatedOccurrences = 0;

        foreach ($entries as $entry) {
            $rawKey = (string) ($entry['key'] ?? '');
            $file = trim((string) ($entry['file'] ?? ''));
            if ($file === '') {
                $file = 'desconocido';
            }

            [$normalizedKey, $invalidReason, $normalizedRawKey] = $this->analyzePushKey($rawKey);
            if ($invalidReason !== null) {
                ++$scannerNotSentCount;
                $notSentKeys[] = [
                    'key' => $normalizedRawKey !== '' ? $normalizedRawKey : '(vacia)',
                    'reason' => $invalidReason,
                    'file' => $file,
                ];
                continue;
            }

            if (isset($acceptedKeys[$normalizedKey])) {
                ++$deduplicatedOccurrences;
                $duplicatedAcceptedKeys[$normalizedKey] = true;
                continue;
            }

            if (isset($existingKeys[$normalizedKey])) {
                if (!isset($localCountedKeys[$normalizedKey])) {
                    $localCountedKeys[$normalizedKey] = true;
                    ++$localNotSentCount;
                }
                continue;
            }

            $acceptedKeys[$normalizedKey] = true;
            $sendableKeys[] = $normalizedKey;
        }

        $notSentTotalCount = $scannerNotSentCount + $localNotSentCount;

        $filesScanned = (int) ($rawStats['files'] ?? 0);
        $foundOccurrences = (int) ($rawStats['found'] ?? count($entries));
        $uniqueKeysCount = count($candidateKeysIndex);

        return [
            'service' => $serviceName,
            'chunk_size' => $chunkSize,
            'sendable_keys' => $sendableKeys,
            'not_sent_keys' => $notSentKeys,
            'not_sent_local_existing_count' => $localNotSentCount,
            'not_sent_error_count' => $scannerNotSentCount,
            'sent_keys_count' => count($sendableKeys),
            'not_sent_keys_count' => $notSentTotalCount,
            'scan_stats' => [
                'files' => $filesScanned,
                'found' => $foundOccurrences,
                'unique' => $uniqueKeysCount,
                'deduplicated_occurrences' => $deduplicatedOccurrences,
                'duplicated_keys_count' => count($duplicatedAcceptedKeys),
                'eligible' => count($sendableKeys),
                'ineligible' => $notSentTotalCount,
            ],
        ];
    }

    /**
     * @return array{0: string, 1: string|null, 2: string}
     */
    private function analyzePushKey(string $key): array
    {
        $rawKey = strtolower(trim($key));
        if ($rawKey === '') {
            return ['', 'clave vacia', ''];
        }

        $segments = array_values(array_filter(explode('.', $rawKey), static fn (string $v): bool => $v !== ''));
        if (count($segments) < 1 || count($segments) > 3) {
            return ['', 'formato invalido (maximo 3 niveles separados por punto)', $rawKey];
        }

        $normalized = implode('.', $segments);
        if (!preg_match('/^[a-z0-9_]+(\.[a-z0-9_]+){0,2}$/', $normalized)) {
            return ['', 'formato invalido (solo letras, numeros, guion bajo y puntos)', $rawKey];
        }

        return [$normalized, null, $rawKey];
    }

    private function getServiceName(): string
    {
        return strtolower(trim((string) ($_ENV['APP_NAME'] ?? 'translations')));
    }

    /**
     * @return array{
     *   service: string,
     *   chunk_size: int,
     *   sendable_seeds: array<int, array{key: string, content: string, locale: string}>,
     *   not_sent_keys: array<int, array{key: string, reason: string, file: string}>,
     *   not_sent_local_existing_count: int,
     *   not_sent_error_count: int,
     *   sent_keys_count: int,
     *   not_sent_keys_count: int,
     *   scan_stats: array{found: int, eligible: int, ineligible: int}
     * }
     */
    private function buildLegacyPreparedData(?int $requestedChunkSize = null): array
    {
        $serviceName = $this->getServiceName();
        $chunkSize = $this->getSyncChunkSize($requestedChunkSize);

        $report = $this->scanner->extractLegacyTranslationsReport();
        /** @var array<int, array{key: string, content: string, locale: string}> $allSeeds */
        $allSeeds = array_values($report['seeds'] ?? []);
        /** @var array<int, array{key: string, reason: string, file: string}> $scannerNotSent */
        $scannerNotSent = array_values($report['ineligible_keys'] ?? []);
        $rawStats = is_array($report['stats'] ?? null) ? $report['stats'] : [];

        /** @var array<string, array<string, bool>> $keysByLocale */
        $keysByLocale = [];
        foreach ($allSeeds as $seed) {
            $locale = strtolower(trim((string) ($seed['locale'] ?? '')));
            $key = strtolower(trim((string) ($seed['key'] ?? '')));
            if ($locale === '' || $key === '') {
                continue;
            }
            $keysByLocale[$locale][$key] = true;
        }

        /** @var array<string, array<string, string>> $existingByLocale */
        $existingByLocale = [];
        foreach ($keysByLocale as $locale => $keys) {
            $existingByLocale[$locale] = $this->traduccionLocalRepository->findContentByLocaleAndKeys($locale, array_keys($keys));
        }

        /** @var array<int, array{key: string, content: string, locale: string}> $sendableSeeds */
        $sendableSeeds = [];
        $localNotSentCount = 0;
        foreach ($allSeeds as $seed) {
            $locale = strtolower(trim((string) ($seed['locale'] ?? '')));
            $key = strtolower(trim((string) ($seed['key'] ?? '')));
            $content = (string) ($seed['content'] ?? '');
            if ($locale === '' || $key === '') {
                continue;
            }

            $localContent = $existingByLocale[$locale][$key] ?? null;
            if ($localContent !== null && $localContent === $content) {
                ++$localNotSentCount;
                continue;
            }

            $sendableSeeds[] = [
                'key' => $key,
                'content' => $content,
                'locale' => $locale,
            ];
        }

        $notSentKeys = $this->mergeNotSentKeys($scannerNotSent, []);
        $notSentErrorCount = count($notSentKeys);
        $notSentTotalCount = $notSentErrorCount + $localNotSentCount;

        return [
            'service' => $serviceName,
            'chunk_size' => $chunkSize,
            'sendable_seeds' => $sendableSeeds,
            'not_sent_keys' => $notSentKeys,
            'not_sent_local_existing_count' => $localNotSentCount,
            'not_sent_error_count' => $notSentErrorCount,
            'sent_keys_count' => count($sendableSeeds),
            'not_sent_keys_count' => $notSentTotalCount,
            'scan_stats' => [
                'found' => (int) ($rawStats['found'] ?? 0),
                'eligible' => count($sendableSeeds),
                'ineligible' => $notSentTotalCount,
            ],
        ];
    }

    /**
     * @param array<int, array{key: string, reason: string, file: string}> $first
     * @param array<int, array{key: string, reason: string, file: string}> $second
     *
     * @return array<int, array{key: string, reason: string, file: string}>
     */
    private function mergeNotSentKeys(array $first, array $second): array
    {
        $merged = [];
        $index = [];
        foreach (array_merge($first, $second) as $entry) {
            $key = strtolower(trim((string) ($entry['key'] ?? '')));
            $reason = trim((string) ($entry['reason'] ?? ''));
            $file = trim((string) ($entry['file'] ?? ''));
            if ($key === '' || $reason === '') {
                continue;
            }

            $id = $key . '|' . $reason . '|' . $file;
            if (isset($index[$id])) {
                continue;
            }

            $index[$id] = true;
            $merged[] = [
                'key' => $key,
                'reason' => $reason,
                'file' => $file,
            ];
        }

        return $merged;
    }

    private function getSyncChunkSize(?int $requestedSize = null): int
    {
        if ($requestedSize !== null && $requestedSize > 0) {
            return $requestedSize;
        }

        $raw = $_ENV[self::ENV_SYNC_CHUNK_SIZE] ?? getenv(self::ENV_SYNC_CHUNK_SIZE);
        $size = (int) trim((string) $raw);

        if ($size <= 0) {
            return self::DEFAULT_SYNC_CHUNK_SIZE;
        }

        return $size;
    }

    /**
     * @param array<string, mixed> $json
     *
     * @return array{0: int, 1: array<string, mixed>}
     */
    private function requestSync(array $json): array
    {
        $url = $this->getSyncUrl();

        $response = $this->httpClient->request('POST', $url, [
            'json' => $json,
            'timeout' => 60, // Un minuto de margen por paquete
        ]);
        
        $status = $response->getStatusCode();
        $content = $response->getContent(false);
        $payload = $this->decodeJsonObject($content);

        if ($status < 200 || $status >= 300) {
            $error = (string) ($payload['error'] ?? $payload['mensaje_real'] ?? $payload['message'] ?? '');
            if ($error === '') {
                $error = sprintf('Error remoto HTTP %d al sincronizar.', $status);
            }

            throw new \RuntimeException($error, $status);
        }

        return [$status, $payload];
    }

    /**
     * @param array<string, mixed> $json
     *
     * @return array{ok: bool, status: int, payload: array<string, mixed>, error: string|null}
     */
    private function requestSyncSafe(array $json): array
    {
        try {
            [$status, $payload] = $this->requestSync($json);

            return [
                'ok' => true,
                'status' => $status,
                'payload' => $payload,
                'error' => null,
            ];
        } catch (\Throwable $exception) {
            $status = (int) $exception->getCode();
            if ($status < 100 || $status > 599) {
                $status = 0;
            }

            return [
                'ok' => false,
                'status' => $status,
                'payload' => [],
                'error' => $exception->getMessage(),
            ];
        }
    }

    private function getSyncUrl(): string
    {
        $baseUrl = rtrim((string) ($_ENV['TRADUCCIONES_URL'] ?? ''), '/');
        if ($baseUrl === '') {
            throw new \RuntimeException('Falta configurar TRADUCCIONES_URL.');
        }

        return $baseUrl . '/api/v1/sync';
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeJsonObject(string $content): array
    {
        if ($content === '') {
            return [];
        }

        try {
            $decoded = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }

        return is_array($decoded) ? $decoded : [];
    }
}
