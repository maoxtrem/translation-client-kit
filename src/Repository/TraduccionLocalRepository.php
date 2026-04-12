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
}