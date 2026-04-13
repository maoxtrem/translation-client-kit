<?php

declare(strict_types=1);

namespace Maoxtrem\TranslationClientKit\Service;

use Symfony\Contracts\HttpClient\HttpClientInterface;

final class PushService
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly TranslationScanner $scanner,
    ) {}

    public function push(): array
    {
        $data = $this->scanner->extract();
        $serviceName = $this->getServiceName();
        $allKeys = $data['keys'];
        
        // Dividimos las 3,000+ llaves en trozos de 200
        $chunks = array_chunk($allKeys, 200);
        
        $totalNuevas = 0;
        $totalPromovidas = 0;
        $lastStatus = 200;

        foreach ($chunks as $chunk) {
            [$status, $payload] = $this->requestSync([
                'service' => $serviceName,
                'project_code' => $serviceName,
                'keys' => $chunk,
            ]);

            $lastStatus = $status;
            $totalNuevas += (int) ($payload['nuevas'] ?? 0);
            $totalPromovidas += (int) ($payload['promovidas'] ?? 0);
        }

        return [
            'status' => $lastStatus,
            'count' => count($allKeys),
            'keys' => $allKeys,
            'remote' => [
                'nuevas' => $totalNuevas,
                'promovidas' => $totalPromovidas,
                'paquetes' => count($chunks)
            ],
            'message' => sprintf('Sincronización completada en %d paquetes de 200.', count($chunks)),
        ];
    }

    public function pushLegacy(): array
    {
        $serviceName = $this->getServiceName();
        $allSeeds = $this->scanner->extractLegacyTranslations();

        if ($allSeeds === []) {
            return [
                'status' => 200,
                'count' => 0,
                'message' => 'No se encontraron traducciones legado para migrar.',
                'remote' => [],
            ];
        }

        // El legado pesa más, 200 es un número seguro para evitar Timeouts
        $chunks = array_chunk($allSeeds, 200);
        
        $totalNuevas = 0;
        $totalPromovidas = 0;
        $lastStatus = 200;

        foreach ($chunks as $chunk) {
            $chunkKeys = array_values(array_unique(array_column($chunk, 'key')));
            
            [$status, $payload] = $this->requestSync([
                'service' => $serviceName,
                'project_code' => $serviceName,
                'keys' => $chunkKeys,
                'seeds' => $chunk,
            ]);

            $lastStatus = $status;
            $totalNuevas += (int) ($payload['nuevas'] ?? 0);
            $totalPromovidas += (int) ($payload['promovidas'] ?? 0);
        }

        return [
            'status' => $lastStatus,
            'count' => count($allSeeds),
            'remote' => [
                'nuevas' => $totalNuevas,
                'promovidas' => $totalPromovidas,
                'paquetes' => count($chunks)
            ],
            'message' => sprintf('Migración de legado enviada con éxito en %d paquetes.', count($chunks)),
        ];
    }

    private function getServiceName(): string
    {
        return strtolower(trim((string) ($_ENV['APP_NAME'] ?? 'translations')));
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

            throw new \RuntimeException($error);
        }

        return [$status, $payload];
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