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

        [$status, $payload] = $this->requestSync([
            // Backward compatible field names for servers that still parse project_code.
            'service' => $serviceName,
            'project_code' => $serviceName,
            'keys' => $data['keys'],
        ]);

        return [
            'status' => $status,
            'count' => count($data['keys']),
            'keys' => $data['keys'],
            'remote' => $payload,
            'message' => 'Sincronizacion enviada con exito.',
        ];
    }

    public function pushLegacy(): array
    {
        $serviceName = $this->getServiceName();
        $seeds = $this->scanner->extractLegacyTranslations();

        if ($seeds === []) {
            return [
                'status' => 200,
                'count' => 0,
                'message' => 'No se encontraron traducciones legado para migrar.',
                'remote' => [],
            ];
        }

        $keys = array_values(array_unique(array_column($seeds, 'key')));

        [$status, $payload] = $this->requestSync([
            'service' => $serviceName,
            'project_code' => $serviceName,
            'keys' => $keys,
            'seeds' => $seeds,
        ]);

        return [
            'status' => $status,
            'count' => count($seeds),
            'keys' => $keys,
            'remote' => $payload,
            'message' => 'Migracion de legado enviada con exito.',
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

        $response = $this->httpClient->request('POST', $url, ['json' => $json]);
        $status = $response->getStatusCode();
        $content = $response->getContent(false);
        $payload = $this->decodeJsonObject($content);

        if ($status < 200 || $status >= 300) {
            $error = (string) ($payload['error'] ?? $payload['message'] ?? '');
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
