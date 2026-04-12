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
        $path = $this->projectDir . '/translations';
        if (!is_dir($path)) {
            return [];
        }

        $finder = (new Finder())->files()->in($path)->name('*.es.yaml')->name('*.es.php');
        /** @var array<string, array{key: string, content: string, locale: string}> $seeds */
        $seeds = [];

        foreach ($finder as $file) {
            $realPath = $file->getRealPath();
            if ($realPath === false) {
                continue;
            }

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
                $normalizedKey = $this->normalizeLegacyKey((string) $key);
                if ($normalizedKey === '') {
                    continue;
                }

                $normalizedContent = trim((string) $content);
                if ($normalizedContent === '') {
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

        return array_values($seeds);
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

    private function normalizeLegacyKey(string $key): string
    {
        $normalized = strtolower(trim($key));
        if ($normalized === '') {
            return '';
        }

        $segments = array_values(array_filter(explode('.', $normalized), static fn (string $v): bool => $v !== ''));
        if (count($segments) < 1 || count($segments) > 3) {
            return '';
        }

        $normalized = implode('.', $segments);
        if (!preg_match('/^[a-z0-9_]+(\.[a-z0-9_]+){0,2}$/', $normalized)) {
            return '';
        }

        return $normalized;
    }
}
