<?php

declare(strict_types=1);

namespace Maoxtrem\TranslationClientKit\Service;

use Symfony\Component\Finder\Finder;
use Symfony\Component\Yaml\Yaml;

final class TranslationScanner
{
    private const EXCLUDED_DIRS = ['vendor', 'var', 'node_modules', 'migrations', 'tests', '.git'];
    private const FUNCTION_CALL_REGEX = '/(?:->\s*trans|\btrans|\$t|i18n\.t)\s*\(\s*[\'"]([a-zA-Z0-9_]+(?:\.[a-zA-Z0-9_]+)*)[\'"]\s*[,)]/i';
    private const TWIG_TRANS_FILTER_REGEX = '/[\'"]([a-zA-Z0-9_]+(?:\.[a-zA-Z0-9_]+)*)[\'"]\s*\|\s*trans\b/i';
    private const TWIG_TRANS_TAG_REGEX = '/{%\s*trans\s*%}\s*([a-zA-Z0-9_]+(?:\.[a-zA-Z0-9_]+)*)\s*{%\s*endtrans\s*%}/i';

    public function __construct(private readonly string $projectDir)
    {
    }

    /**
     * @return array{keys: array<int, string>}
     */
    public function extract(): array
    {
        $report = $this->extractRuntimeTranslationsReport();
        /** @var array<int, array{key: string, file: string}> $entries */
        $entries = array_values($report['entries'] ?? []);

        $keys = [];
        foreach ($entries as $entry) {
            $key = strtolower(trim((string) ($entry['key'] ?? '')));
            if ($key === '') {
                continue;
            }

            $keys[] = $key;
        }

        return ['keys' => array_values(array_unique($keys))];
    }

    /**
     * @return array{
     *   entries: array<int, array{key: string, file: string}>,
     *   stats: array{found: int, files: int}
     * }
     */
    public function extractRuntimeTranslationsReport(): array
    {
        $files = $this->collectRuntimeFiles();
        /** @var array<int, array{key: string, file: string}> $entries */
        $entries = [];

        foreach ($files as $absolutePath => $relativePath) {
            $content = @file_get_contents($absolutePath);
            if (!is_string($content) || $content === '') {
                continue;
            }

            foreach ($this->extractKeysFromContent($content) as $key) {
                $normalized = strtolower(trim($key));
                if ($normalized === '') {
                    continue;
                }

                $entries[] = [
                    'key' => $normalized,
                    'file' => $relativePath,
                ];
            }
        }

        return [
            'entries' => $entries,
            'stats' => [
                'found' => count($entries),
                'files' => count($files),
            ],
        ];
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

        $finder = (new Finder())->files()->in($path)->name('*.es.yaml')->name('*.es.yml')->name('*.es.php');
        /** @var array<string, array{key: string, content: string, locale: string}> $seeds */
        $seeds = [];
        /** @var array<string, string> $seedFiles */
        $seedFiles = [];
        /** @var array<string, bool> $found */
        $found = [];
        /** @var array<int, array{key: string, reason: string, file: string}> $ineligible */
        $ineligible = [];
        /** @var array<string, bool> $ineligibleIndex */
        $ineligibleIndex = [];
        /**
         * @var array<string, array{values: array<string, bool>, files: array<string, bool>}>
         */
        $conflictedKeys = [];

        foreach ($finder as $file) {
            $realPath = $file->getRealPath();
            if ($realPath === false) {
                continue;
            }
            $relativePath = $this->normalizePath($realPath);

            $data = [];

            if (in_array($file->getExtension(), ['yaml', 'yml'], true)) {
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

                if (isset($conflictedKeys[$normalizedKey])) {
                    $this->registerConflictValue($conflictedKeys, $normalizedKey, $normalizedContent, $relativePath);
                    continue;
                }

                if (!isset($seeds[$normalizedKey])) {
                    $seeds[$normalizedKey] = [
                        'key' => $normalizedKey,
                        'content' => $normalizedContent,
                        'locale' => 'es',
                    ];
                    $seedFiles[$normalizedKey] = $relativePath;
                    continue;
                }

                if ($seeds[$normalizedKey]['content'] === $normalizedContent) {
                    // Same key and same value across files: keep a single seed.
                    continue;
                }

                // Same key with different value across files: do not send this key.
                $this->registerConflictValue($conflictedKeys, $normalizedKey, $seeds[$normalizedKey]['content'], $seedFiles[$normalizedKey] ?? 'desconocido');
                $this->registerConflictValue($conflictedKeys, $normalizedKey, $normalizedContent, $relativePath);
                unset($seeds[$normalizedKey], $seedFiles[$normalizedKey]);
            }
        }

        foreach ($conflictedKeys as $key => $meta) {
            $files = array_keys($meta['files']);
            sort($files);

            $this->addIneligible(
                $ineligible,
                $ineligibleIndex,
                $key,
                'clave duplicada con valores diferentes',
                implode(', ', $files)
            );
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

    /**
     * @param array<string, array{values: array<string, bool>, files: array<string, bool>}> $conflicts
     */
    private function registerConflictValue(array &$conflicts, string $key, string $value, string $file): void
    {
        if (!isset($conflicts[$key])) {
            $conflicts[$key] = [
                'values' => [],
                'files' => [],
            ];
        }

        $conflicts[$key]['values'][$value] = true;
        $conflicts[$key]['files'][$file] = true;
    }

    /**
     * @return array<string, string>
     */
    private function collectRuntimeFiles(): array
    {
        /** @var array<string, string> $files */
        $files = [];

        $globalFinder = (new Finder())
            ->files()
            ->in($this->projectDir)
            ->exclude(self::EXCLUDED_DIRS)
            ->name('*.js')
            ->name('*.twig');
        $this->appendFinderFiles($files, $globalFinder);

        $srcPath = $this->projectDir . '/src';
        if (is_dir($srcPath)) {
            $srcFinder = (new Finder())
                ->files()
                ->in($srcPath)
                ->name('*.php')
                ->name('*.js')
                ->name('*.twig');
            $this->appendFinderFiles($files, $srcFinder);
        }

        return $files;
    }

    /**
     * @return array<int, string>
     */
    private function extractKeysFromContent(string $content): array
    {
        preg_match_all(self::FUNCTION_CALL_REGEX, $content, $m1);
        preg_match_all(self::TWIG_TRANS_FILTER_REGEX, $content, $m2);
        preg_match_all(self::TWIG_TRANS_TAG_REGEX, $content, $m3);

        /** @var array<int, string> $keys */
        $keys = [];
        foreach (array_merge($m1[1] ?? [], $m2[1] ?? [], $m3[1] ?? []) as $key) {
            $keys[] = (string) $key;
        }

        return $keys;
    }

    /**
     * @param array<string, string> $files
     */
    private function appendFinderFiles(array &$files, Finder $finder): void
    {
        foreach ($finder as $file) {
            $realPath = $file->getRealPath();
            if ($realPath === false) {
                continue;
            }

            $files[$realPath] = $this->normalizePath($realPath);
        }
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
