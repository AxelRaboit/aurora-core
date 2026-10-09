<?php

declare(strict_types=1);

namespace Aurora\Module\Notes\Markdown\Controller\Suite;

use Aurora\Core\Enum\HttpMethodEnum;
use Aurora\Core\Http\JsonResponseTrait;
use Aurora\Module\Notes\Markdown\Service\LinkTitleFetcher;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The title of a pasted address, for the editor (see `LinkTitleFetcher`).
 *
 * Its own controller: the notes one is long already, and this one fetches
 * the web rather than a note.
 */
#[IsGranted('notes.markdown.use')]
final class MarkdownNoteLinkTitleController extends AbstractController
{
    use JsonResponseTrait;

    public function __construct(private readonly LinkTitleFetcher $fetcher) {}

    #[Route('/suite/notes/markdown/link-title', name: 'suite_notes_markdown_link_title', methods: [HttpMethodEnum::Get->value])]
    public function title(Request $request): JsonResponse
    {
        $url = mb_trim((string) $request->query->get('url', ''));
        if ('' === $url || mb_strlen($url) > 2048 || !LinkTitleFetcher::isFetchable($url)) {
            return $this->jsonSuccess(['title' => null]);
        }

        return $this->jsonSuccess(['title' => $this->fetcher->titleOf($url)]);
    }
}
