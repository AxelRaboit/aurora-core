<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial;

use Aurora\Core\Bootstrap\BootstrapProviderInterface;
use Aurora\Core\Locale\Repository\LocaleRepository;
use Aurora\Module\Editorial\Menu\Contract\DefaultMenuItem;
use Aurora\Module\Editorial\Menu\Contract\MenuLocation;
use Aurora\Module\Editorial\Menu\Entity\Menu;
use Aurora\Module\Editorial\Menu\Entity\MenuInterface;
use Aurora\Module\Editorial\Menu\Entity\MenuItem;
use Aurora\Module\Editorial\Menu\Entity\MenuItemInterface;
use Aurora\Module\Editorial\Menu\Repository\MenuItemRepository;
use Aurora\Module\Editorial\Menu\Repository\MenuRepository;
use Aurora\Module\Editorial\Menu\Service\MenuLocationRegistry;
use Aurora\Module\Editorial\PostType\Entity\PostType;
use Aurora\Module\Editorial\PostType\Entity\PostTypeInterface;
use Aurora\Module\Editorial\PostType\Repository\PostTypeRepository;
use Aurora\Module\Editorial\Taxonomy\Entity\Taxonomy;
use Aurora\Module\Editorial\Taxonomy\Entity\TaxonomyInterface;
use Aurora\Module\Editorial\Taxonomy\Repository\TaxonomyRepository;
use Doctrine\Common\Collections\Order;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Editorial's floor: the two post types every site starts from, the two
 * taxonomies they classify with, and a menu per declared location.
 *
 * Without a post type nothing can be written at all, so this is not demo
 * data - it is the difference between an installed site and an unusable one.
 * Runs at priority 50, below core's 100, so the locales it translates into
 * already exist.
 */
final readonly class EditorialBootstrapProvider implements BootstrapProviderInterface
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private PostTypeRepository $postTypeRepository,
        private TaxonomyRepository $taxonomyRepository,
        private MenuRepository $menuRepository,
        private MenuItemRepository $menuItemRepository,
        private MenuLocationRegistry $locationRegistry,
        private LocaleRepository $localeRepository,
        private TranslatorInterface $translator,
    ) {}

    public function getPriority(): int
    {
        return 50;
    }

    public function bootstrap(): iterable
    {
        yield from $this->seedPostTypes();
        yield from $this->seedTaxonomies();

        // Flushed before the menus so the post types exist as rows; nothing
        // in the menu defaults points at them today, but the ordering is the
        // one a default that did would need.
        $this->entityManager->flush();

        yield from $this->seedMenus();

        $this->entityManager->flush();
    }

    /**
     * `page` for standalone content, `article` for a dated stream. Only
     * `article` gets an archive: a list of every page on the site is not a
     * page anyone wants, whereas a blog index is the whole point.
     *
     * @return iterable<string>
     */
    private function seedPostTypes(): iterable
    {
        $definitions = [
            ['page', 'backend.editorial.bootstrap.post_types.page', 'file-text', false],
            ['article', 'backend.editorial.bootstrap.post_types.article', 'newspaper', true],
        ];

        foreach ($definitions as [$slug, $labelKey, $icon, $hasArchive]) {
            if ($this->postTypeRepository->findOneBySlug($slug) instanceof PostTypeInterface) {
                continue;
            }

            $this->entityManager->persist(
                new PostType()
                    ->setSlug($slug)
                    ->setLabel($this->trans($labelKey))
                    ->setIcon($icon)
                    ->setHasArchive($hasArchive)
                    ->setIsBuiltIn(true),
            );

            yield sprintf('type de contenu %s', $slug);
        }
    }

    /**
     * Categories nest, tags do not - the distinction the whole taxonomy
     * screen is built around, seeded as one example of each. Both attach to
     * `article` only: pages are not usually classified.
     *
     * @return iterable<string>
     */
    private function seedTaxonomies(): iterable
    {
        $definitions = [
            ['category', 'backend.editorial.bootstrap.taxonomies.category', true],
            ['tag', 'backend.editorial.bootstrap.taxonomies.tag', false],
        ];

        $article = $this->postTypeRepository->findOneBySlug('article');

        foreach ($definitions as [$slug, $labelKey, $hierarchical]) {
            if ($this->taxonomyRepository->findOneBySlug($slug) instanceof TaxonomyInterface) {
                continue;
            }

            $taxonomy = new Taxonomy()
                ->setSlug($slug)
                ->setHierarchical($hierarchical)
                ->setIsBuiltIn(true);

            foreach ($this->localeCodes() as $code) {
                $taxonomy->translate($code)->setLabel($this->trans($labelKey, $code));
            }

            if ($article instanceof PostTypeInterface) {
                $article->addTaxonomy($taxonomy);
            }

            $this->entityManager->persist($taxonomy);

            yield sprintf('taxonomie %s', $slug);
        }
    }

    /**
     * One menu per declared location, filled with that location's defaults.
     *
     * Both halves are matched, never overwritten: an existing menu keeps the
     * name an admin gave it, and a default entry is recognised by its
     * reference, so re-running this does not re-add an entry someone deleted
     * on purpose - nor duplicate one they moved.
     *
     * @return iterable<string>
     */
    private function seedMenus(): iterable
    {
        foreach ($this->locationRegistry->all() as $location) {
            $menu = $this->menuRepository->findOneByLocation($location->key);

            if (!$menu instanceof MenuInterface) {
                $menu = new Menu()
                    ->setLocation($location->key)
                    ->setName($this->trans($location->labelKey))
                    ->setDescription(null !== $location->descriptionKey ? $this->trans($location->descriptionKey) : null);

                $this->entityManager->persist($menu);

                yield sprintf('menu %s', $location->key);
            }

            yield from $this->seedItems($menu, $location->defaultItems, null, $location);
        }
    }

    /**
     * @param list<DefaultMenuItem> $defaults
     *
     * @return iterable<string>
     */
    private function seedItems(MenuInterface $menu, array $defaults, ?MenuItemInterface $parent, MenuLocation $location): iterable
    {
        $position = 0;

        foreach ($defaults as $default) {
            $item = $this->menuItemRepository->findOneByReference($default->reference);

            if (!$item instanceof MenuItemInterface) {
                $item = new MenuItem()
                    ->setReference($default->reference)
                    ->setTargetType($default->targetType)
                    ->setCustomUrl($default->customUrl)
                    ->setVisibility($default->visibility)
                    ->setParent($parent)
                    ->setPosition($position);

                foreach ($this->localeCodes() as $code) {
                    $item->translate($code)->setLabel($this->trans($default->labelKey, $code));
                }

                $menu->addItem($item);
                $this->entityManager->persist($item);

                yield sprintf('entrée de menu %s', $default->reference);
            }

            ++$position;

            yield from $this->seedItems($menu, $default->children, $item, $location);
        }
    }

    /**
     * Every locale the install knows, not only the active ones: activating a
     * locale later should not leave the menus unlabelled in it.
     *
     * @return list<string>
     */
    private function localeCodes(): array
    {
        $codes = [];
        foreach ($this->localeRepository->findBy([], ['position' => Order::Ascending->value]) as $locale) {
            $codes[] = $locale->getCode();
        }

        return $codes;
    }

    private function trans(string $key, ?string $locale = null): string
    {
        return $this->translator->trans($key, [], null, $locale);
    }
}
