<?php
declare(strict_types=1);
namespace Maoxtrem\TranslationClientKit\Service;
use Doctrine\ORM\EntityManagerInterface;
use Maoxtrem\TranslationClientKit\Entity\TraduccionLocal;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class DownloadService {
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly EntityManagerInterface $entityManager,
        private readonly CacheItemPoolInterface $cacheTranslations,
        private readonly string $projectDir
    ) {}

    public function download(): array {
        $serviceName = strtolower(trim($_ENV['APP_NAME'] ?? 'translations'));
        $baseUrl = rtrim($_ENV['TRADUCCIONES_URL'], '/');
        $url = $baseUrl . '/api/v1/translations/package/' . rawurlencode($serviceName);

        $response = $this->httpClient->request('GET', $url);
        $payload = $response->toArray();

        $this->entityManager->createQueryBuilder()->delete(TraduccionLocal::class, 't')->getQuery()->execute();
        $inserted = 0; $locales = [];

        foreach ($payload as $row) {
            $entity = (new TraduccionLocal())
                ->setKeyName($row['key_name'])->setLocale($row['locale'])->setContent($row['content']);
            $this->entityManager->persist($entity);
            $locales[$row['locale']] = true; $inserted++;
        }
        $this->entityManager->flush();
        $this->cacheTranslations->clear();
        return ['count' => $inserted, 'source' => $url];
    }
}