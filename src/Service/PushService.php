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
        $serviceName = strtolower(trim((string) ($_ENV['APP_NAME'] ?? 'translations')));
        $baseUrl = rtrim((string) ($_ENV['TRADUCCIONES_URL'] ?? ''), '/');

        if ($baseUrl === '') {
            throw new \RuntimeException('Falta configurar TRADUCCIONES_URL.');
        }

        $url = $baseUrl . '/api/v1/sync';
        $data = $this->scanner->extract();

        $response = $this->httpClient->request('POST', $url, [
            'json' => [
                // Backward compatible field names for servers that still parse project_code.
                'service' => $serviceName,
                'project_code' => $serviceName,
                'keys' => $data['keys'],
            ],
        ]);

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

        return [
            'status' => $status,
            'count' => count($data['keys']),
            'keys' => $data['keys'],
            'remote' => $payload,
            'message' => 'Sincronizacion enviada con exito.',
        ];
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
