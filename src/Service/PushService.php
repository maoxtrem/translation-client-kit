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

        $url = $baseUrl . '/api/v1/push';
        $data = $this->scanner->extract();

        $response = $this->httpClient->request('POST', $url, [
            'json' => [
                'project_code' => $serviceName,
                'keys' => $data['keys'],
            ],
        ]);

        // src/Service/PushService.php - línea 40 aprox.
        return [
            'status' => $response->getStatusCode(),
            'count' => count($data['keys']),
            'debug_keys' => $data['keys'], // <--- Añade esto para ver la lista en el JSON
            'message' => 'Sincronizacion enviada con exito.',
        ];
    }
}
