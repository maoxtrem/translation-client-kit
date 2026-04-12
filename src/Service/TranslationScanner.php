<?php
declare(strict_types=1);
namespace Maoxtrem\TranslationClientKit\Service;
use Symfony\Component\Finder\Finder;

final class TranslationScanner {
    private const FUNCTION_CALL_REGEX = '/(?:->\s*)?trans\(\s*[\'"]([a-zA-Z0-9_]+(?:\.[a-zA-Z0-9_]+)*)[\'"]\s*[,)]/i';
    private const TWIG_TRANS_FILTER_REGEX = '/[\'"]([a-zA-Z0-9_]+(?:\.[a-zA-Z0-9_]+)*)[\'"]\s*\|\s*trans\b/i';
    public function __construct(private readonly string $projectDir) {}
    public function extract(): array {
        $finder = (new Finder())->files()->in($this->projectDir)
            ->exclude(['vendor', 'var', 'node_modules', 'migrations', 'tests', '.git'])
            ->name('*.php')->name('*.twig');
        $keys = [];
        foreach ($finder as $file) {
            $content = $file->getContents();
            preg_match_all(self::FUNCTION_CALL_REGEX, $content, $m1);
            preg_match_all(self::TWIG_TRANS_FILTER_REGEX, $content, $m2);
            foreach (array_merge($m1[1] ?? [], $m2[1] ?? []) as $k) { $keys[] = strtolower(trim($k)); }
        }
        return ['keys' => array_values(array_unique($keys))];
    }
}