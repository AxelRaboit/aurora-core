<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\SpacePublication\View;

use Aurora\Core\Locale\Service\LocaleContextInterface;
use Aurora\Core\Routing\PathTemplateGenerator;
use Aurora\Module\Editorial\EditorialContext;
use Aurora\Module\Editorial\Post\Entity\PostInterface;
use Aurora\Module\Editorial\Post\Repository\PostRepository;
use Aurora\Module\Studio\CustomerSpace\Entity\CustomerSpaceInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

use function array_map;

use const DATE_ATOM;

/**
 * The documents written for one client: audits, strategies, anything built
 * with the publications' grid and delivered by link rather than on the site.
 *
 * They are publications, not a second kind of document. This view lists them
 * and starts new ones; writing them happens in the publications' own editor,
 * so every zone of the grid, the revisions, the trash and the three languages
 * come with them instead of being rebuilt here.
 */
final readonly class SpacePublicationsViewBuilder
{
    public function __construct(
        private PostRepository $posts,
        private EditorialContext $editorialContext,
        private LocaleContextInterface $localeContext,
        private UrlGeneratorInterface $urlGenerator,
        private PathTemplateGenerator $pathTemplates,
        private Security $security,
    ) {}

    /** @return array<string, mixed> */
    public function view(CustomerSpaceInterface $space): array
    {
        return [
            // Off with the module: an entry leading to an editor that answers
            // 404 is an entry somebody opens once.
            'publicationsEnabled' => $this->editorialContext->isPostsEnabled(),
            'publications' => $this->publications($space),
            'canCreatePublications' => $this->security->isGranted('studio.spaces.edit')
                && $this->security->isGranted('editorial.posts.create'),
            'publicationCreatePath' => $this->urlGenerator->generate('workspace_space_publications_create', ['id' => $space->getId()]),
        ];
    }

    /** @return list<array<string, mixed>> */
    public function publications(CustomerSpaceInterface $space): array
    {
        if (!$this->editorialContext->isPostsEnabled()) {
            return [];
        }

        $editPath = $this->pathTemplates->generate('backend_editorial_posts_edit', ['id' => '__id__']);
        $default = $this->localeContext->getDefaultLocale();

        return array_map(
            static function (PostInterface $post) use ($editPath, $default): array {
                $translation = $post->getTranslation($default) ?? ($post->getTranslations()->first() ?: null);

                return [
                    'id' => $post->getId(),
                    'title' => $translation?->getTitle(),
                    'status' => $post->getStatus()->value,
                    'updatedAt' => $post->getUpdatedAt()->format(DATE_ATOM),
                    'editPath' => str_replace('__id__', (string) $post->getId(), $editPath),
                ];
            },
            $this->posts->findForCustomerSpace((int) $space->getId()),
        );
    }
}
