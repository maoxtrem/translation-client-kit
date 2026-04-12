<?php

declare(strict_types=1);

namespace Maoxtrem\TranslationClientKit\Service;

use Doctrine\ORM\EntityManagerInterface;
use Maoxtrem\TranslationClientKit\Entity\TraduccionLocal;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class DownloadService
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly EntityManagerInterface $entityManager,
        private readonly CacheItemPoolInterface $cacheTranslations,
        private readonly string $projectDir,
    ) {}

    /**
     * @return array{count: int, source: string}
     */
    public function download(): array
    {
        $serviceName = strtolower(trim((string) ($_ENV['APP_NAME'] ?? 'translations')));
        $baseUrl = rtrim((string) ($_ENV['TRADUCCIONES_URL'] ?? ''), '/');
        if ($baseUrl === '') {
            throw new \RuntimeException('Falta configurar TRADUCCIONES_URL.');
        }

        $url = $baseUrl . '/api/v1/translations/package/' . rawurlencode($serviceName);
        $response = $this->httpClient->request('GET', $url);
        $payload = $response->toArray();

        if (!is_array($payload)) {
            throw new \RuntimeException('Respuesta invalida del Cerebro: se esperaba un arreglo JSON.');
        }

        $connection = $this->entityManager->getConnection();
        $connection->beginTransaction();

        try {
            $this->entityManager->createQueryBuilder()
                ->delete(TraduccionLocal::class, 't')
                ->getQuery()
                ->execute();

            $inserted = 0;
            foreach ($payload as $index => $row) {
                if (!is_array($row)) {
                    throw new \RuntimeException(sprintf('Fila invalida en payload (indice %d).', $index));
                }

                $keyName = trim((string) ($row['key_name'] ?? ''));
                $locale = trim((string) ($row['locale'] ?? ''));
                $content = (string) ($row['content'] ?? '');

                if ($keyName === '' || $locale === '') {
                    throw new \RuntimeException(sprintf('Fila incompleta en payload (indice %d): key_name/locale requeridos.', $index));
                }

                $entity = (new TraduccionLocal())
                    ->setKeyName($keyName)
                    ->setLocale($locale)
                    ->setContent($content);

                $this->entityManager->persist($entity);
                ++$inserted;
            }

            $this->entityManager->flush();
            $connection->commit();
        } catch (\Throwable $exception) {
            $connection->rollBack();
            $this->entityManager->clear();

            throw $exception;
        }

        $this->cacheTranslations->clear();
        $dbName = $this->entityManager->getConnection()->getDatabase();
        return [
            'count' => $inserted,
            'message' => "Guardado en la base de datos: " . $dbName,
            'source' => $url,
        ];
    }
}
