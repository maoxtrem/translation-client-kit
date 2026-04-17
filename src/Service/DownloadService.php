<?php

declare(strict_types=1);

namespace Maoxtrem\TranslationClientKit\Service;

use Doctrine\ORM\EntityManagerInterface;
use Maoxtrem\TranslationClientKit\Entity\TraduccionLocal;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class DownloadService
{
    private const DB_MARKER_DOMAINS = ['messages', 'validators'];

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly EntityManagerInterface $entityManager,
        private readonly CacheItemPoolInterface $cacheTranslations,
        private readonly string $projectDir,
    ) {}

    /**
     * @return array{
     *   count: int,
     *   message: string,
     *   source: string,
     *   keys: array<int, string>,
     *   locales: array<int, string>,
     *   dump_files: array<int, string>
     * }
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
            /** @var array<string, bool> $keys */
            $keys = [];
            /** @var array<string, bool> $locales */
            $locales = [];
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
                $keys[strtolower($keyName)] = true;
                $locales[strtolower($locale)] = true;
            }

            $this->entityManager->flush();
            $connection->commit();
        } catch (\Throwable $exception) {
            $connection->rollBack();
            $this->entityManager->clear();

            throw $exception;
        }

        $keyList = array_keys($keys ?? []);
        sort($keyList);
        $localeList = array_keys($locales ?? []);
        sort($localeList);

        $dumpFiles = $this->writeDbMarkerFiles($localeList);
        $this->cacheTranslations->clear();
        $dbName = $this->entityManager->getConnection()->getDatabase();

        return [
            'count' => $inserted,
            'message' => 'Guardado en la base de datos: ' . $dbName,
            'source' => $url,
            'keys' => $keyList,
            'locales' => $localeList,
            'dump_files' => $dumpFiles,
        ];
    }

    /**
     * @param array<int, string> $locales
     *
     * @return array<int, string>
     */
    private function writeDbMarkerFiles(array $locales): array
    {
        $targetDir = $this->projectDir . '/translations/db';
        if (!is_dir($targetDir) && !mkdir($targetDir, 0775, true) && !is_dir($targetDir)) {
            throw new \RuntimeException(sprintf('No se pudo crear el directorio de dump: %s', $targetDir));
        }

        $written = [];
        foreach ($locales as $locale) {
            $normalizedLocale = strtolower(trim($locale));
            if ($normalizedLocale === '') {
                continue;
            }

            foreach (self::DB_MARKER_DOMAINS as $domain) {
                $filePath = $targetDir . '/' . $domain . '.' . $normalizedLocale . '.db';
                $content = "# TranslationClientKit DB marker\n# domain: {$domain}\n# locale: {$normalizedLocale}\n";
                if (file_put_contents($filePath, $content) === false) {
                    throw new \RuntimeException(sprintf('No se pudo escribir el archivo: %s', $filePath));
                }

                $written[] = $filePath;
            }
        }

        sort($written);

        return $written;
    }
}
