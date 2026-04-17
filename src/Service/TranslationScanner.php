<?php

declare(strict_types=1);

namespace Maoxtrem\TranslationClientKit\Service;

use Symfony\Component\Finder\Finder;
use Symfony\Component\Yaml\Yaml;

final class TranslationScanner
{
    private const FUNCTION_CALL_REGEX = '/(?:->\s*)?trans\(\s*[\'"]([a-zA-Z0-9_]+(?:\.[a-zA-Z0-9_]+)*)[\'"]\s*[,)]/i';
    private const TWIG_TRANS_FILTER_REGEX = '/[\'"]([a-zA-Z0-9_]+(?:\.[a-zA-Z0-9_]+)*)[\'"]\s*\|\s*trans\b/i';

    public function __construct(private readonly string $projectDir)
    {
    }

    /**
     * @return array{keys: array<int, string>}
     */
    public function extract(): array
    {
        $finder = (new Finder())->files()->in($this->projectDir)
            ->exclude(['vendor', 'var', 'node_modules', 'migrations', 'tests', '.git'])
            ->name('*.php')
            ->name('*.twig');

        $keys = [];
        foreach ($finder as $file) {
            $content = $file->getContents();
            preg_match_all(self::FUNCTION_CALL_REGEX, $content, $m1);
            preg_match_all(self::TWIG_TRANS_FILTER_REGEX, $content, $m2);

            foreach (array_merge($m1[1] ?? [], $m2[1] ?? []) as $k) {
                $keys[] = strtolower(trim($k));
            }
        }

        return ['keys' => array_values(array_unique($keys))];
    }

    /**
     * @return array<int, array{key: string, content: string, locale: string}>
     */
    public function extractLegacyTranslations(): array
    {
        $report = $this->extractLegacyTranslationsReport();

        return $report['seeds'];
    }

    /**
     * @return array{
     *   seeds: array<int, array{key: string, content: string, locale: string}>,
     *   stats: array{found: int, eligible: int, ineligible: int},
     *   ineligible_keys: array<int, array{key: string, reason: string, file: string}>
     * }
     */
    public function extractLegacyTranslationsReport(): array
    {
        $path = $this->projectDir . '/translations';
        if (!is_dir($path)) {
            return [
                'seeds' => [],
                'stats' => [
                    'found' => 0,
                    'eligible' => 0,
                    'ineligible' => 0,
                ],
                'ineligible_keys' => [],
            ];
        }

        $finder = (new Finder())->files()->in($path)->name('*.es.yaml')->name('*.es.php');
        /** @var array<string, array{key: string, content: string, locale: string}> $seeds */
        $seeds = [];
        /** @var array<string, bool> $found */
        $found = [];
        /** @var array<int, array{key: string, reason: string, file: string}> $ineligible */
        $ineligible = [];
        /** @var array<string, bool> $ineligibleIndex */
        $ineligibleIndex = [];

        foreach ($finder as $file) {
            $realPath = $file->getRealPath();
            if ($realPath === false) {
                continue;
            }
            $relativePath = $this->normalizePath($realPath);

            $data = [];

            if ($file->getExtension() === 'yaml') {
                $parsed = Yaml::parseFile($realPath);
                if (is_array($parsed)) {
                    $data = $parsed;
                }
            } elseif ($file->getExtension() === 'php') {
                $parsed = include $realPath;
                if (is_array($parsed)) {
                    $data = $parsed;
                }
            }

            foreach ($this->flattenArray($data) as $key => $content) {
                [$normalizedKey, $invalidReason, $rawKey] = $this->analyzeLegacyKey((string) $key);
                if ($rawKey !== '') {
                    $found[$rawKey] = true;
                }

                if ($invalidReason !== null) {
                    $this->addIneligible($ineligible, $ineligibleIndex, $rawKey !== '' ? $rawKey : '(vacia)', $invalidReason, $relativePath);
                    continue;
                }

                $normalizedContent = trim((string) $content);
                if ($normalizedContent === '') {
                    $this->addIneligible($ineligible, $ineligibleIndex, $normalizedKey, 'contenido vacio', $relativePath);
                    continue;
                }

                // Keep last value per key if duplicates exist across files/domains.
                $seeds[$normalizedKey] = [
                    'key' => $normalizedKey,
                    'content' => $normalizedContent,
                    'locale' => 'es',
                ];
            }
        }

        return [
            'seeds' => array_values($seeds),
            'stats' => [
                'found' => count($found),
                'eligible' => count($seeds),
                'ineligible' => count($ineligible),
            ],
            'ineligible_keys' => $ineligible,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function flattenArray(array $array, string $prefix = ''): array
    {
        $result = [];

        foreach ($array as $key => $value) {
            $segment = trim((string) $key);
            if ($segment === '') {
                continue;
            }

            $newKey = $prefix === '' ? $segment : $prefix . '.' . $segment;
            if (is_array($value)) {
                $result = array_merge($result, $this->flattenArray($value, $newKey));
                continue;
            }

            $result[$newKey] = $value;
        }

        return $result;
    }

    private function analyzeLegacyKey(string $key): array
    {
        $rawKey = strtolower(trim($key));
        if ($rawKey === '') {
            return ['', 'clave vacia', ''];
        }

        $segments = array_values(array_filter(explode('.', $rawKey), static fn (string $v): bool => $v !== ''));
        if (count($segments) < 1 || count($segments) > 3) {
            return ['', 'formato invalido (maximo 3 niveles separados por punto)', $rawKey];
        }

        $normalized = implode('.', $segments);
        if (!preg_match('/^[a-z0-9_]+(\.[a-z0-9_]+){0,2}$/', $normalized)) {
            return ['', 'formato invalido (solo letras, numeros, guion bajo y puntos)', $rawKey];
        }

        return [$normalized, null, $rawKey];
    }

    /**
     * @param array<int, array{key: string, reason: string, file: string}> $ineligible
     * @param array<string, bool>                                     $index
     */
    private function addIneligible(array &$ineligible, array &$index, string $key, string $reason, string $file): void
    {
        $id = $key . '|' . $reason;
        if (isset($index[$id])) {
            return;
        }

        $index[$id] = true;
        $ineligible[] = [
            'key' => $key,
            'reason' => $reason,
            'file' => $file,
        ];
    }

    private function normalizePath(string $path): string
    {
        $projectPath = rtrim($this->projectDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        if (str_starts_with($path, $projectPath)) {
            return str_replace(DIRECTORY_SEPARATOR, '/', substr($path, strlen($projectPath)));
        }

        return str_replace(DIRECTORY_SEPARATOR, '/', $path);
    }
}
