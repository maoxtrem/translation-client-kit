<?php
declare(strict_types=1);
namespace Maoxtrem\TranslationClientKit\Controller;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Twig\Environment;

final class EndpointsDocsController {
    public function __construct(private readonly Environment $twig) {}
    #[Route('/api/v2/client-kit/docs', name: 'api_v2_client_kit_docs', methods: ['GET'])]
    public function __invoke(): Response {
        $content = @file_get_contents(dirname(__DIR__).'/Resources/docs/api.md');
        return new Response($this->twig->render('@TranslationClient/docs/index.html.twig', [
            'markdown_content' => $content ?: '# Error cargando docs'
        ]));
    }
}