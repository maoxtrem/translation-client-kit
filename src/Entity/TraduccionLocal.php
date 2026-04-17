<?php
declare(strict_types=1);
namespace Maoxtrem\TranslationClientKit\Entity;
use Doctrine\ORM\Mapping as ORM;
use Maoxtrem\TranslationClientKit\Repository\TraduccionLocalRepository;

#[ORM\Entity(repositoryClass: TraduccionLocalRepository::class)]
#[ORM\Table(name: 'traduccion_local')]
#[ORM\UniqueConstraint(name: 'uniq_traduccion_local_key_locale', columns: ['key_name', 'locale'])]
#[ORM\HasLifecycleCallbacks]
class TraduccionLocal {
    #[ORM\Id] #[ORM\GeneratedValue] #[ORM\Column] private ?int $id = null;
    #[ORM\Column(length: 190)] private string $keyName;
    #[ORM\Column(length: 5)] private string $locale;
    #[ORM\Column(type: 'text')] private string $content;
    #[ORM\Column(type: 'datetime_immutable')] private \DateTimeImmutable $updatedAt;

    public function __construct() { $this->updatedAt = new \DateTimeImmutable(); }
    public function getId(): ?int { return $this->id; }
    public function getKeyName(): string { return $this->keyName; }
    public function setKeyName(string $keyName): static { $this->keyName = strtolower(trim($keyName)); return $this; }
    public function getLocale(): string { return $this->locale; }
    public function setLocale(string $locale): static { $this->locale = strtolower(trim($locale)); return $this; }
    public function getContent(): string { return $this->content; }
    public function setContent(string $content): static { $this->content = $content; return $this; }

    #[ORM\PrePersist] #[ORM\PreUpdate]
    public function touchUpdatedAt(): void { $this->updatedAt = new \DateTimeImmutable(); }
}