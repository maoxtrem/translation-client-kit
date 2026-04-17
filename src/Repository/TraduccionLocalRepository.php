<?php
declare(strict_types=1);
namespace Maoxtrem\TranslationClientKit\Repository;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Maoxtrem\TranslationClientKit\Entity\TraduccionLocal;

final class TraduccionLocalRepository extends ServiceEntityRepository {
    public function __construct(ManagerRegistry $registry) { parent::__construct($registry, TraduccionLocal::class); }
    public function findAvailableLocales(): array {
        $rows = $this->createQueryBuilder('t')
            ->select('DISTINCT t.locale AS locale')
            ->where('t.locale IS NOT NULL AND t.locale != :empty')
            ->setParameter('empty', '')
            ->orderBy('t.locale', 'ASC')
            ->getQuery()->getArrayResult();
        return array_values(array_map(fn($r) => (string)$r['locale'], $rows));
    }

    /**
     * @param array<int, string> $keys
     *
     * @return array<string, string>
     */
    public function findContentByLocaleAndKeys(string $locale, array $keys): array
    {
        $normalizedLocale = strtolower(trim($locale));
        $normalizedKeys = array_values(array_filter(array_map(static fn (string $key): string => strtolower(trim($key)), $keys), static fn (string $key): bool => $key !== ''));
        if ($normalizedLocale === '' || $normalizedKeys === []) {
            return [];
        }

        $result = [];
        foreach (array_chunk($normalizedKeys, 500) as $keysChunk) {
            $rows = $this->createQueryBuilder('t')
                ->select('t.keyName AS key_name', 't.content AS content')
                ->where('t.locale = :locale')
                ->andWhere('t.keyName IN (:keys)')
                ->setParameter('locale', $normalizedLocale)
                ->setParameter('keys', $keysChunk)
                ->getQuery()
                ->getArrayResult();

            foreach ($rows as $row) {
                $key = strtolower(trim((string) ($row['key_name'] ?? '')));
                if ($key === '') {
                    continue;
                }
                $result[$key] = (string) ($row['content'] ?? '');
            }
        }

        return $result;
    }

    /**
     * @param array<int, string> $keys
     *
     * @return array<string, bool>
     */
    public function findExistingKeys(array $keys): array
    {
        $normalizedKeys = array_values(array_filter(array_map(static fn (string $key): string => strtolower(trim($key)), $keys), static fn (string $key): bool => $key !== ''));
        if ($normalizedKeys === []) {
            return [];
        }

        $existing = [];
        foreach (array_chunk($normalizedKeys, 500) as $keysChunk) {
            $rows = $this->createQueryBuilder('t')
                ->select('DISTINCT t.keyName AS key_name')
                ->where('t.keyName IN (:keys)')
                ->setParameter('keys', $keysChunk)
                ->getQuery()
                ->getArrayResult();

            foreach ($rows as $row) {
                $key = strtolower(trim((string) ($row['key_name'] ?? '')));
                if ($key === '') {
                    continue;
                }

                $existing[$key] = true;
            }
        }

        return $existing;
    }
}
