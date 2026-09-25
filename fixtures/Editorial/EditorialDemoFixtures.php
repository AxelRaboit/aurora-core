<?php

declare(strict_types=1);

namespace Aurora\Fixtures\Editorial;

use Aurora\Core\Locale\Enum\LocaleEnum;
use Aurora\Core\Sequence\SequencePrefixEnum;
use Aurora\Fixtures\Core\CoreDemoFixtures;
use Aurora\Fixtures\Ged\GedDemoFixtures;
use Aurora\Module\Configuration\Setting\Enum\ApplicationParameterEnum;
use Aurora\Module\Configuration\Setting\Service\SettingsService;
use Aurora\Module\Editorial\Comment\Entity\Comment;
use Aurora\Module\Editorial\Comment\Entity\CommentInterface;
use Aurora\Module\Editorial\Comment\Entity\CommentReaction;
use Aurora\Module\Editorial\Comment\Enum\CommentStatusEnum;
use Aurora\Module\Editorial\Comment\Enum\ReactionTypeEnum;
use Aurora\Module\Editorial\Form\Dto\FormFieldInput;
use Aurora\Module\Editorial\Form\Dto\FormInput;
use Aurora\Module\Editorial\Form\Entity\FormFieldInterface;
use Aurora\Module\Editorial\Form\Entity\FormInterface;
use Aurora\Module\Editorial\Form\Entity\FormTranslationInterface;
use Aurora\Module\Editorial\Form\Enum\FormFieldTypeEnum;
use Aurora\Module\Editorial\Form\Manager\FormManagerInterface;
use Aurora\Module\Editorial\Form\Repository\FormTranslationRepository;
use Aurora\Module\Editorial\Menu\Entity\MenuInterface;
use Aurora\Module\Editorial\Menu\Entity\MenuItem;
use Aurora\Module\Editorial\Menu\Enum\MenuItemTargetTypeEnum;
use Aurora\Module\Editorial\Menu\Repository\MenuItemRepository;
use Aurora\Module\Editorial\Menu\Repository\MenuRepository;
use Aurora\Module\Editorial\Post\Banner\BannerNormalizer;
use Aurora\Module\Editorial\Post\Entity\Post;
use Aurora\Module\Editorial\Post\Entity\PostInterface;
use Aurora\Module\Editorial\Post\Entity\PostRevision;
use Aurora\Module\Editorial\Post\Entity\PostTranslationInterface;
use Aurora\Module\Editorial\Post\Enum\PostStatusEnum;
use Aurora\Module\Editorial\Post\Enum\ThumbnailFitEnum;
use Aurora\Module\Editorial\Post\Gallery\GalleryNormalizer;
use Aurora\Module\Editorial\Post\Grid\GridNormalizer;
use Aurora\Module\Editorial\Post\Repository\PostRevisionRepository;
use Aurora\Module\Editorial\Post\Service\EditorBlocks;
use Aurora\Module\Editorial\Post\Service\PostSnapshot;
use Aurora\Module\Editorial\Post\Service\PostTextExtractor;
use Aurora\Module\Editorial\PostType\Entity\PostType;
use Aurora\Module\Editorial\PostType\Entity\PostTypeField;
use Aurora\Module\Editorial\PostType\Entity\PostTypeInterface;
use Aurora\Module\Editorial\PostType\Repository\PostTypeRepository;
use Aurora\Module\Editorial\Taxonomy\Entity\TaxonomyInterface;
use Aurora\Module\Editorial\Taxonomy\Entity\TaxonomyTerm;
use Aurora\Module\Editorial\Taxonomy\Entity\TaxonomyTermInterface;
use Aurora\Module\Editorial\Taxonomy\Repository\TaxonomyRepository;
use Aurora\Module\Ged\Document\Entity\Document;
use Aurora\Module\Platform\User\Entity\User;
use Aurora\Module\Platform\User\Enum\UserTypeEnum;
use Aurora\Module\Platform\User\Repository\UserRepository;
use DateTimeImmutable;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Bundle\FixturesBundle\FixtureGroupInterface;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ObjectManager;
use RuntimeException;

use function assert;

/**
 * Demo content: a handful of posts across the statuses, terms to file them
 * under, and a primary menu that points at them.
 *
 * Builds on what `aurora:install` already created rather than creating it
 * again - the post types and the taxonomies are the product's floor, not
 * demo data, and a fixture that made its own would give the site two
 * "Articles" types the day someone ran both. It looks them up and fails
 * loudly if they are absent, which means the install step was skipped.
 *
 * Dev/test only, `demo` group.
 */
class EditorialDemoFixtures extends Fixture implements DependentFixtureInterface, FixtureGroupInterface
{
    public function __construct(
        private readonly PostTypeRepository $postTypeRepository,
        private readonly TaxonomyRepository $taxonomyRepository,
        private readonly MenuRepository $menuRepository,
        private readonly MenuItemRepository $menuItemRepository,
        private readonly PostTextExtractor $textExtractor,
        private readonly GridNormalizer $gridNormalizer,
        private readonly GalleryNormalizer $galleryNormalizer,
        private readonly BannerNormalizer $bannerNormalizer,
        private readonly SettingsService $settingsManager,
        private readonly FormManagerInterface $forms,
        private readonly FormTranslationRepository $formTranslationRepository,
        private readonly PostSnapshot $snapshot,
        private readonly PostRevisionRepository $revisionRepository,
        private readonly UserRepository $userRepository,
    ) {}

    public static function getGroups(): array
    {
        return ['demo'];
    }

    public function getDependencies(): array
    {
        // GED too, since the welcome page's grid points at a demo picture.
        return [CoreDemoFixtures::class, GedDemoFixtures::class];
    }

    public function load(ObjectManager $manager): void
    {
        assert($manager instanceof EntityManagerInterface);

        $article = $this->postTypeRepository->findOneBySlug('article');
        $page = $this->postTypeRepository->findOneBySlug('page');
        if (!$article instanceof PostTypeInterface || !$page instanceof PostTypeInterface) {
            // Better than a fatal further down with no clue why: the demo
            // stands on the install step, and saying so is the whole message.
            throw new RuntimeException('Run `aurora:install` before loading the demo fixtures - the built-in post types are missing.');
        }

        $this->createCustomFields($manager, $article);
        $this->bindTaxonomies($manager, $article);

        $types = $this->createDemoPostTypes($manager, ['article' => $article, 'page' => $page]);
        $terms = $this->createTerms($manager);
        $posts = $this->createPosts($manager, $types, $terms);

        $manager->flush();

        // After the flush on purpose: a grid points at a publication and a
        // document by *id*, and neither has one before it is written.
        $this->layOutWelcomePage($posts);
        $this->layOutFirstStepsArticle($posts);
        $this->layOutAboutPage($posts, $types);
        $this->layOutServicePages($posts);
        $this->layOutProjectPages($posts);
        $this->layOutShowcasePage($posts);
        $this->addGalleries($posts);
        $this->relatePosts($posts);

        $this->fillPrimaryMenu($manager, $posts, $types);

        // La page de contact pose le formulaire, donc elle attend qu'il
        // existe : une zone de formulaire nomme un identifiant, et un
        // formulaire qui n'est pas encore écrit n'en a pas.
        $this->layOutContactPage($posts, $this->createQuoteForm());

        $this->createComments($manager, $posts);
        $manager->flush();

        // Après le dernier `flush` : une révision photographie la publication
        // telle qu'elle est à cet instant, grille comprise, et la grille est
        // posée plus haut.
        $this->createRevisions($manager, $posts);

        $manager->flush();

        // The demo used to open on the list of its own two articles, which is
        // the fallback for a site that has not chosen a front page - so the
        // first screen anybody saw was the one screen nobody composes. The
        // welcome page is the composed one, banner included; naming it here is
        // what the parameter is for.
        $welcomeId = $posts['welcome']->getId();
        if (null !== $welcomeId) {
            $this->settingsManager->set(
                ApplicationParameterEnum::HomepagePostId->value,
                (string) $welcomeId,
            );
        }

        $manager->flush();
    }

    /**
     * Fields of its own on the Article type.
     *
     * The screen that lists a type's custom fields said "no custom field" on a
     * freshly loaded demo, which shows where the feature lives and nothing of
     * what it does. These four are the shapes that differ: a number, a link, a
     * closed list and a flag - and the first is marked translatable, since
     * whether a field follows the language is the choice that screen exists to
     * offer.
     */
    private function createCustomFields(EntityManagerInterface $em, PostTypeInterface $article): void
    {
        $defs = [
            ['reading_time', 'Temps de lecture (min)', 'number', false, false, []],
            ['source_url', 'Source', 'url', false, false, []],
            ['level', 'Niveau', 'select', false, true, ['Débutant', 'Intermédiaire', 'Avancé']],
            ['featured', 'Mettre en avant', 'checkbox', false, false, []],
        ];

        $repository = $em->getRepository(PostTypeField::class);

        foreach ($defs as $position => [$name, $label, $type, $required, $translatable, $options]) {
            // Reused by (type, machine name): the pair is what identifies a
            // field, and `make demo` runs twice.
            $field = $repository->findOneBy(['postType' => $article, 'name' => $name]) ?? new PostTypeField();

            $field->setPostType($article)
                ->setName($name)
                ->setLabel($label)
                ->setType($type)
                ->setRequired($required)
                ->setTranslatable($translatable)
                ->setOptions($options)
                ->setPosition($position);

            $em->persist($field);
        }
    }

    /**
     * A nested pair under `category` and two flat ones under `tag`, so both
     * taxonomy shapes have something in them to look at.
     *
     * @return array<string, TaxonomyTermInterface>
     */
    /**
     * Rattache les taxonomies au type Article.
     *
     * Sans ce rattachement, l'écran d'édition ne propose aucun terme et le
     * site public ne dessine ni sommaire ni page suivante : la démo n'avait
     * donc rien pour montrer une lecture en séquence, alors que c'est ce qui
     * distingue une documentation d'un blog. Une taxonomie hiérarchique et
     * une plate, parce que les deux ne servent pas à la même chose et que le
     * sommaire se dessine à partir de la première.
     */
    private function bindTaxonomies(EntityManagerInterface $em, PostTypeInterface $article): void
    {
        foreach (['category', 'tag'] as $slug) {
            $taxonomy = $this->taxonomyRepository->findOneBySlug($slug);

            if (!$taxonomy instanceof TaxonomyInterface) {
                continue;
            }

            if (!$article->getTaxonomies()->contains($taxonomy)) {
                $article->addTaxonomy($taxonomy);
            }
        }

        $em->flush();
    }

    private function createTerms(EntityManagerInterface $em): array
    {
        $category = $this->taxonomyRepository->findOneBySlug('category');
        $tag = $this->taxonomyRepository->findOneBySlug('tag');

        $terms = [];

        if ($category instanceof TaxonomyInterface) {
            $terms['guides'] = $this->term($em, $category, [
                'fr' => ['Guides', 'guides'],
                'en' => ['Guides', 'guides'],
                'es' => ['Guías', 'guias'],
            ], SequencePrefixEnum::TaxonomyTerm->value.'-DEMO-1');

            $terms['starters'] = $this->term($em, $category, [
                'fr' => ['Premiers pas', 'premiers-pas'],
                'en' => ['Getting started', 'getting-started'],
                'es' => ['Primeros pasos', 'primeros-pasos'],
            ], SequencePrefixEnum::TaxonomyTerm->value.'-DEMO-2', $terms['guides']);

            // Deux termes ne montrent pas une taxonomie : ils montrent une
            // liste de deux lignes, dont une indentée, sur un écran vide aux
            // deux tiers. Ce qui se voit ici, c'est l'imbrication, et il faut
            // un second parent et un second niveau pour qu'elle se lise comme
            // une arborescence plutôt que comme un accident.
            $terms['layout'] = $this->term($em, $category, [
                'fr' => ['Mise en page', 'mise-en-page'],
                'en' => ['Layout', 'layout'],
                'es' => ['Maquetación', 'maquetacion'],
            ], SequencePrefixEnum::TaxonomyTerm->value.'-DEMO-5', $terms['guides']);

            $terms['cases'] = $this->term($em, $category, [
                'fr' => ['Études de cas', 'etudes-de-cas'],
                'en' => ['Case studies', 'case-studies'],
                'es' => ['Casos prácticos', 'casos-practicos'],
            ], SequencePrefixEnum::TaxonomyTerm->value.'-DEMO-6');

            $terms['showcase'] = $this->term($em, $category, [
                'fr' => ['Sites vitrines', 'sites-vitrines'],
                'en' => ['Showcase sites', 'showcase-sites'],
                'es' => ['Sitios escaparate', 'sitios-escaparate'],
            ], SequencePrefixEnum::TaxonomyTerm->value.'-DEMO-7', $terms['cases']);

            $terms['shops'] = $this->term($em, $category, [
                'fr' => ['Boutiques en ligne', 'boutiques-en-ligne'],
                'en' => ['Online shops', 'online-shops'],
                'es' => ['Tiendas en línea', 'tiendas-en-linea'],
            ], SequencePrefixEnum::TaxonomyTerm->value.'-DEMO-8', $terms['cases']);
        }

        if ($tag instanceof TaxonomyInterface) {
            $terms['editorial'] = $this->term($em, $tag, [
                'fr' => ['Éditorial', 'editorial'],
                'en' => ['Editorial', 'editorial'],
                'es' => ['Editorial', 'editorial-es'],
            ], SequencePrefixEnum::TaxonomyTerm->value.'-DEMO-3');

            $terms['release'] = $this->term($em, $tag, [
                'fr' => ['Nouveautés', 'nouveautes'],
                'en' => ['Releases', 'releases'],
                'es' => ['Novedades', 'novedades'],
            ], SequencePrefixEnum::TaxonomyTerm->value.'-DEMO-4');

            // Les étiquettes sont à plat par nature : ce qui manquait ici,
            // c'est seulement le nombre.
            $terms['media'] = $this->term($em, $tag, [
                'fr' => ['Médias', 'medias'],
                'en' => ['Media', 'media'],
                'es' => ['Medios', 'medios'],
            ], SequencePrefixEnum::TaxonomyTerm->value.'-DEMO-9');

            $terms['forms'] = $this->term($em, $tag, [
                'fr' => ['Formulaires', 'formulaires'],
                'en' => ['Forms', 'forms'],
                'es' => ['Formularios', 'formularios'],
            ], SequencePrefixEnum::TaxonomyTerm->value.'-DEMO-10');

            $terms['a11y'] = $this->term($em, $tag, [
                'fr' => ['Accessibilité', 'accessibilite'],
                'en' => ['Accessibility', 'accessibility'],
                'es' => ['Accesibilidad', 'accesibilidad'],
            ], SequencePrefixEnum::TaxonomyTerm->value.'-DEMO-11');
        }

        return $terms;
    }

    /**
     * @param array<string, array{0: string, 1: string}> $translations locale → [name, slug]
     */
    private function term(
        EntityManagerInterface $em,
        TaxonomyInterface $taxonomy,
        array $translations,
        string $reference,
        ?TaxonomyTermInterface $parent = null,
    ): TaxonomyTermInterface {
        // Reused when the reference is already taken, for the same reason as
        // the publications below: `make demo` should refresh a database that
        // already has demo data rather than dying halfway through it.
        $term = $em->getRepository(TaxonomyTerm::class)->findOneBy(['reference' => $reference])
            ?? new TaxonomyTerm();

        $term
            ->setTaxonomy($taxonomy)
            ->setParent($parent)
            ->setReference($reference)
            ->setPosition(0);

        foreach ($translations as $locale => [$name, $slug]) {
            $term->translate($locale)->setName($name)->setSlug($slug);
        }

        $em->persist($term);

        return $term;
    }

    /**
     * One post per status, so every filter on the list has something to
     * show and the dashboard bars are not a single full-width block.
     *
     * @param array<string, PostTypeInterface>     $types keyed by slug
     * @param array<string, TaxonomyTermInterface> $terms
     *
     * @return array<string, PostInterface>
     */
    private function createPosts(
        EntityManagerInterface $em,
        array $types,
        array $terms,
    ): array {
        // Doctrine keys references by concrete class, so this asks for what
        // CoreDemoFixtures actually stored rather than for the interface.
        $author = $this->getReference(CoreDemoFixtures::userRef(0), User::class);
        $now = new DateTimeImmutable();

        $defs = [
            'welcome' => [
                'type' => $types['page'],
                'media' => 0,
                'status' => PostStatusEnum::Published,
                'publishedAt' => $now->modify('-30 days'),
                'terms' => [],
                'fr' => ['Bienvenue', 'bienvenue', 'La page d\'accueil de ce site de démonstration.'],
                'en' => ['Welcome', 'welcome', 'The landing page of this demo site.'],
                'es' => ['Bienvenida', 'bienvenida', 'La página de inicio de este sitio de demostración.'],
                // Un onglet « Moteurs de recherche » vide ne montre pas que
                // l'onglet existe : il montre un formulaire. La page d'accueil
                // et le premier article le remplissent donc, dans les trois
                // langues, avec ce qu'un auteur y mettrait vraiment.
                'seo' => [
                    'fr' => ['Bienvenue sur le site de démonstration Aurora', 'Découvrez Aurora en conditions réelles : publications, médias, formulaires et espaces clients, sur un site complet.', 'démonstration aurora'],
                    'en' => ['Welcome to the Aurora demo site', 'See Aurora for real: posts, media, forms and client spaces, on a complete working site.', 'aurora demo'],
                    'es' => ['Bienvenido al sitio de demostración de Aurora', 'Descubra Aurora en condiciones reales: publicaciones, medios, formularios y espacios de cliente.', 'demostración aurora'],
                ],
            ],
            'first-steps' => [
                'type' => $types['article'],
                'media' => 3,
                'status' => PostStatusEnum::Published,
                'publishedAt' => $now->modify('-12 days'),
                'terms' => ['starters', 'editorial', 'forms'],
                'fr' => ['Écrire son premier article', 'ecrire-premier-article', 'Du brouillon à la mise en ligne, en cinq minutes.'],
                'en' => ['Writing your first post', 'writing-your-first-post', 'From draft to published, in five minutes.'],
                'es' => ['Escribir su primer artículo', 'escribir-primer-articulo', 'Del borrador a la publicación, en cinco minutos.'],
                'seo' => [
                    'fr' => ['Écrire son premier article avec Aurora', 'Un guide en cinq minutes : créer un brouillon, composer la grille, relire, puis publier ou programmer la mise en ligne.', 'écrire un article'],
                    'en' => ['Writing your first post with Aurora', 'A five-minute guide: create a draft, lay out the grid, review, then publish or schedule it.', 'writing a post'],
                    'es' => ['Escribir su primer artículo con Aurora', 'Una guía de cinco minutos: crear un borrador, componer la cuadrícula, revisar y publicar o programar.', 'escribir un artículo'],
                ],
            ],
            'blocks' => [
                'type' => $types['article'],
                'media' => 2,
                'status' => PostStatusEnum::Published,
                'publishedAt' => $now->modify('-3 days'),
                'terms' => ['guides', 'layout', 'media'],
                'fr' => ['Composer avec les blocs', 'composer-avec-les-blocs', 'Titres, listes, encadrés : ce que l\'éditeur sait faire.'],
                'en' => ['Composing with blocks', 'composing-with-blocks', 'Headings, lists, callouts: what the editor can do.'],
                'es' => ['Componer con bloques', 'componer-con-bloques', 'Títulos, listas, destacados: lo que sabe hacer el editor.'],
            ],
            'roadmap' => [
                'type' => $types['article'],
                'media' => 1,
                'status' => PostStatusEnum::Draft,
                'publishedAt' => null,
                'terms' => ['release'],
                'fr' => ['Ce qui arrive ensuite', 'ce-qui-arrive-ensuite', 'Un brouillon, visible seulement en administration.'],
                'en' => ['What comes next', 'what-comes-next', 'A draft, visible in the backend only.'],
                'es' => ['Lo que viene después', 'lo-que-viene-despues', 'Un borrador, visible solo en la administración.'],
            ],
            // Les deux statuts que la démo n'avait pas. La page de documentation
            // sur le cycle de vie en annonce cinq, et la liste n'en montrait
            // que trois : une capture qui contredit son propre texte.
            'review' => [
                'type' => $types['article'],
                'media' => 1,
                'status' => PostStatusEnum::PendingReview,
                'publishedAt' => null,
                'terms' => ['guides', 'a11y'],
                'fr' => ['Relire avant de publier', 'relire-avant-de-publier', 'Envoyée en relecture : elle attend un avis.'],
                'en' => ['Review before publishing', 'review-before-publishing', 'Sent for review: it is waiting for an opinion.'],
                'es' => ['Revisar antes de publicar', 'revisar-antes-de-publicar', 'Enviada a revisión: espera una opinión.'],
            ],
            'retired' => [
                'type' => $types['article'],
                'media' => 0,
                'status' => PostStatusEnum::Archived,
                'publishedAt' => $now->modify('-120 days'),
                'terms' => ['release'],
                'fr' => ["Les tarifs de l'an dernier", 'les-tarifs-de-l-an-dernier', 'Archivée : retirée du site, gardée en base.'],
                'en' => ["Last year's prices", 'last-years-prices', 'Archived: off the site, kept in the database.'],
                'es' => ['Las tarifas del año pasado', 'las-tarifas-del-ano-pasado', 'Archivada: fuera del sitio, guardada en base.'],
            ],
            'announcement' => [
                'type' => $types['article'],
                'media' => 0,
                'status' => PostStatusEnum::Scheduled,
                'publishedAt' => null,
                'scheduledAt' => $now->modify('+7 days'),
                'terms' => ['release'],
                'fr' => ['Annonce à venir', 'annonce-a-venir', 'Programmée : elle se publiera toute seule.'],
                'en' => ['Upcoming announcement', 'upcoming-announcement', 'Scheduled: it will publish itself.'],
                'es' => ['Anuncio previsto', 'anuncio-previsto', 'Programado: se publicará solo.'],
            ],
            // Ce que la production a et que la démo n'avait pas : des pages
            // institutionnelles, deux types de contenu maison et des
            // réalisations - c'est-à-dire la forme d'un vrai site plutôt que
            // celle d'un bac à sable. Les titres sont vrais, parce qu'un menu
            // qui dit « Lorem ipsum » n'apprend rien ; tout ce qui se lit
            // dessous est du faux latin, pour que personne ne prenne une page
            // de démonstration pour une page de client.
            'about' => [
                'type' => $types['page'],
                'media' => 1,
                'status' => PostStatusEnum::Published,
                'publishedAt' => $now->modify('-28 days'),
                'terms' => [],
                'fr' => ['À propos', 'a-propos', $this->lorem(1)],
                'en' => ['About', 'about', $this->lorem(1)],
                'es' => ['Acerca de', 'acerca-de', $this->lorem(1)],
            ],
            'contact' => [
                'type' => $types['page'],
                'media' => 3,
                'status' => PostStatusEnum::Published,
                'publishedAt' => $now->modify('-28 days'),
                'terms' => [],
                'fr' => ['Contact', 'contact', 'Une question, un projet, un devis : écrivez-nous, on répond sous 48 heures.'],
                'en' => ['Contact', 'contact', 'A question, a project, a quote: write to us and we answer within 48 hours.'],
                'es' => ['Contacto', 'contacto', 'Una pregunta, un proyecto, un presupuesto: escríbanos, respondemos en 48 horas.'],
            ],
            'legal' => [
                'type' => $types['page'],
                'media' => 2,
                'status' => PostStatusEnum::Published,
                'publishedAt' => $now->modify('-28 days'),
                'terms' => [],
                'fr' => ['Mentions légales', 'mentions-legales', $this->lorem(1, 2)],
                'en' => ['Legal notice', 'legal-notice', $this->lorem(1, 2)],
                'es' => ['Aviso legal', 'aviso-legal', $this->lorem(1, 2)],
            ],
            'service-web' => [
                'type' => $types['services'],
                'media' => 0,
                'status' => PostStatusEnum::Published,
                'publishedAt' => $now->modify('-25 days'),
                'terms' => [],
                'fr' => ['Développement web', 'developpement-web', $this->lorem(1, 3)],
                'en' => ['Web development', 'web-development', $this->lorem(1, 3)],
                'es' => ['Desarrollo web', 'desarrollo-web', $this->lorem(1, 3)],
            ],
            'service-photo' => [
                'type' => $types['services'],
                'media' => 1,
                'status' => PostStatusEnum::Published,
                'publishedAt' => $now->modify('-24 days'),
                'terms' => [],
                'fr' => ['Photographie', 'photographie', $this->lorem(1, 4)],
                'en' => ['Photography', 'photography', $this->lorem(1, 4)],
                'es' => ['Fotografía', 'fotografia', $this->lorem(1, 4)],
            ],
            'service-social' => [
                'type' => $types['services'],
                'media' => 2,
                'status' => PostStatusEnum::Published,
                'publishedAt' => $now->modify('-23 days'),
                'terms' => [],
                'fr' => ['Réseaux sociaux', 'reseaux-sociaux', $this->lorem(1, 5)],
                'en' => ['Social media', 'social-media', $this->lorem(1, 5)],
                'es' => ['Redes sociales', 'redes-sociales', $this->lorem(1, 5)],
            ],
            'project-lumen' => [
                'type' => $types['projets'],
                'media' => 3,
                'status' => PostStatusEnum::Published,
                'publishedAt' => $now->modify('-18 days'),
                'terms' => [],
                'fr' => ['Projet Lumen', 'projet-lumen', $this->lorem(1, 6)],
                'en' => ['Lumen project', 'lumen-project', $this->lorem(1, 6)],
                'es' => ['Proyecto Lumen', 'proyecto-lumen', $this->lorem(1, 6)],
            ],
            'project-atlas' => [
                'type' => $types['projets'],
                'media' => 0,
                'status' => PostStatusEnum::Published,
                'publishedAt' => $now->modify('-9 days'),
                'terms' => [],
                'fr' => ['Projet Atlas', 'projet-atlas', $this->lorem(1, 7)],
                'en' => ['Atlas project', 'atlas-project', $this->lorem(1, 7)],
                'es' => ['Proyecto Atlas', 'proyecto-atlas', $this->lorem(1, 7)],
            ],
            // Last, so that adding it moves no other publication's reference.
            'showcase' => [
                'type' => $types['page'],
                'media' => 2,
                'status' => PostStatusEnum::Published,
                'publishedAt' => $now->modify('-1 day'),
                'terms' => [],
                'fr' => ['Nouveaux blocs', 'nouveaux-blocs', 'Disponibilité, horaires, compte à rebours, carte de visite, publication, carrousel, écran, terminal et index.'],
                'en' => ['New blocks', 'new-blocks', 'Availability, opening hours, countdown, business card, social post, carousel, screen, terminal and index.'],
                'es' => ['Nuevos bloques', 'nuevos-bloques', 'Disponibilidad, horarios, cuenta atrás, tarjeta de visita, publicación, carrusel, pantalla, terminal e índice.'],
            ],
        ];

        $posts = [];
        $index = 0;
        $repository = $em->getRepository(Post::class);

        foreach ($defs as $key => $def) {
            ++$index;

            $reference = sprintf('%s-DEMO-%d', SequencePrefixEnum::Post->value, $index);

            // Reused when it is already there, so a second `make demo`
            // refreshes the demo rather than dying on the unique reference.
            // Everything below is a setter, and `addTerm` already refuses a
            // term it holds - so one path serves both cases and there is no
            // second one to keep in step.
            $post = $repository->findOneBy(['reference' => $reference]) ?? new Post();

            $post
                ->setPostType($def['type'])
                ->setAuthor($author)
                // Every publication carries one: a demo where some cards have
                // a picture and some do not shows the empty layout as often as
                // the real one, and reads as unfinished rather than as a
                // choice.
                ->setThumbnail($this->getReference(GedDemoFixtures::mediaRef($def['media']), Document::class))
                // Stated rather than left to the default. It is the same value,
                // and a demo that relies on a default cannot show that the
                // control exists.
                ->setThumbnailFit(ThumbnailFitEnum::Cover)
                ->setStatus($def['status'])
                ->setPublishedAt($def['publishedAt'])
                ->setScheduledAt($def['scheduledAt'] ?? null)
                ->setReference($reference);

            foreach ($def['terms'] as $termKey) {
                if (isset($terms[$termKey])) {
                    $post->addTerm($terms[$termKey]);
                }
            }

            // A body is a grid of one full-width text zone. It used to be the
            // `blocks` column, which is the same thing said the old way - the
            // migration that moved every publication over does not run again on
            // a freshly loaded fixture set, so the fixtures have to speak the
            // new shape themselves or the demo pages come up empty.
            $post->setGridLayout($this->gridNormalizer->normalizeLayout([
                'enabled' => true,
                'snap' => 4,
                'zones' => [[
                    'id' => 'body',
                    'type' => GridNormalizer::ZONE_TEXT,
                    'span' => ['base' => 48, 'md' => null, 'lg' => 48],
                ]],
            ]));

            foreach (LocaleEnum::values() as $locale) {
                [$title, $slug, $description] = $def[$locale];

                $translation = $post->translate($locale)
                    ->setTitle($title)
                    ->setSlug($slug)
                    ->setDescription($description);

                if (isset($def['seo'][$locale])) {
                    [$metaTitle, $metaDescription, $focusKeyword] = $def['seo'][$locale];

                    $translation
                        ->setMetaTitle($metaTitle)
                        ->setMetaDescription($metaDescription)
                        ->setFocusKeyword($focusKeyword);
                }

                $translation->setGrid($this->gridNormalizer->normalizeContent([
                    'zones' => ['body' => ['blocks' => $this->blocks($title, $description, $locale)]],
                ], $post->getGridLayout()));

                $this->indexForSearch($translation);
            }

            // persist() on an entity Doctrine already manages is a no-op, so
            // the reused branch needs no guard of its own.
            $em->persist($post);
            $posts[$key] = $post;
        }

        return $posts;
    }

    /**
     * The welcome page shows what a content grid can do: every zone type,
     * several widths, and a stack - the one arrangement the grid could not
     * make while zones only ever flowed along a row.
     *
     * Widths are all multiples of four, so every one of them is reachable at
     * the default snap - a demo an author cannot reproduce with the controls
     * in front of them teaches the wrong thing.
     *
     * The arrangement is written once and both languages share it; only what
     * fills the zones is translated. That is the feature, so the fixture has
     * to be built that way rather than duplicating a layout per locale.
     *
     * @param array<string, PostInterface> $posts
     */
    private function layOutWelcomePage(array $posts): void
    {
        $welcome = $posts['welcome'];
        $linked = $posts['first-steps'];
        $picture = $this->getReference(GedDemoFixtures::mediaRef(1), Document::class);
        // mediaRef(2), not (4): the references are numbered from one and the
        // fourth is the demo video, which a media zone would render as a
        // broken `<img>`. A portrait suits the lower half of a stack anyway.
        $stacked = $this->getReference(GedDemoFixtures::mediaRef(2), Document::class);

        $zones = [
            ['id' => 'intro', 'type' => GridNormalizer::ZONE_TEXT, 'span' => ['base' => 48, 'md' => null, 'lg' => 48]],
            // A tall picture beside a stack of two, which is the one shape the
            // grid could not make until stacks existed: a zone cannot occupy
            // two rows, so a third zone would have wrapped and landed *under*
            // this one rather than beside it.
            //
            // The portrait ratio is what gives the left zone a height that was
            // decided rather than inherited from its content - without it
            // "taller than its neighbours" is not something a picture can be
            // asked for.
            // Les effets d'apparition se démontrent ici, et l'héritage avec :
            // la page dit « depuis le bas » une fois pour toutes (plus bas,
            // à la racine), et deux zones seulement en décident autrement.
            // La photo et la pile arrivent l'une par la gauche et l'autre par
            // la droite, deux moitiés qui se rejoignent - c'est l'arrangement
            // que cette paire existe pour écrire, et le seul qu'un effet par
            // zone permet de composer.
            ['id' => 'picture', 'type' => GridNormalizer::ZONE_MEDIA, 'span' => ['base' => 48, 'md' => null, 'lg' => 24], 'ratio' => '3x4', 'reveal' => 'left', 'mediaId' => $picture->getId()],
            [
                'id' => 'column',
                'type' => GridNormalizer::ZONE_STACK,
                'span' => ['base' => 48, 'md' => null, 'lg' => 24],
                'reveal' => 'right',
                // Halves that sum to 48, so the editor's fraction row reads
                // "1/2" on each and is telling the truth. The stack takes its
                // height from the row, which the picture beside it sets.
                'children' => [
                    ['id' => 'beside', 'type' => GridNormalizer::ZONE_TEXT, 'span' => ['base' => 48, 'md' => null, 'lg' => 24]],
                    // `fill`, not a ratio and not a half. The text above is
                    // three lines and its share was 403px, so an even split
                    // left 275px of nothing between the two - the gap this
                    // shape exists to close. The paragraph now takes what it
                    // says and the picture has the rest, which is what an
                    // author means by "a picture beside a short text".
                    ['id' => 'under', 'type' => GridNormalizer::ZONE_MEDIA, 'span' => ['base' => 48, 'md' => null, 'lg' => 24], 'ratio' => GridNormalizer::RATIO_FILL, 'mediaId' => $stacked->getId()],
                ],
            ],
            // A third of the row, alone on it: a card is a card at any width,
            // and stretching it across the page to fill the space would say
            // the grid cannot do anything else.
            ['id' => 'linked', 'type' => GridNormalizer::ZONE_POST, 'span' => ['base' => 48, 'md' => null, 'lg' => 16], 'postId' => $linked->getId()],
            ['id' => 'outro', 'type' => GridNormalizer::ZONE_TEXT, 'span' => ['base' => 48, 'md' => null, 'lg' => 48]],
        ];

        $welcome->setGridLayout($this->gridNormalizer->normalizeLayout([
            'enabled' => true,
            'snap' => 4,
            // Posé une fois pour la page. Les zones qui ne disent rien le
            // suivent, y compris celles ajoutées plus tard : c'est ce que le
            // réglage sert à démontrer, plus encore que l'effet lui-même.
            'reveal' => 'up',
            'zones' => $zones,
        ]));

        // A header, because the demo home page had none and a site whose first
        // screen is a list of two cards shows the editor and hides everything
        // the banner can do. Full width, a gradient in the accent of the
        // shipped palette, and the foot dissolved into the page - the three
        // choices somebody would actually make, rather than a grey box proving
        // the field exists.
        $welcome->setBannerLayout($this->bannerNormalizer->normalizeLayout([
            'enabled' => true,
            'height' => 'lg',
            'width' => 'full_aligned',
            'verticalAlign' => 'center',
            'fadeOut' => true,
            // Les couleurs de la maison, pas l'émeraude pleine.
            //
            // `#059669` est l'accent d'Aurora à pleine saturation : posé sur
            // toute une entête, il pèse une luminance de 116 là où le site
            // public se tient entre 28 et 50, et il détonne au milieu des
            // huit autres entêtes. Le même vert en version sourde, avec la
            // même inclinaison que la production, met la démonstration dans
            // la même famille visuelle que ce qu'elle sert à montrer.
            'background' => [
                'type' => 'gradient',
                'gradientFrom' => '#054634',
                'gradientTo' => '#03261d',
                'gradientAngle' => 160,
            ],
            'items' => [
                [
                    'id' => 'banner-text',
                    'type' => 'text',
                    'span' => ['base' => 48, 'md' => null, 'lg' => 30],
                    'titleColor' => '#ffffff',
                    'descriptionColor' => '#e5e7eb',
                    'align' => 'start',
                    'titleSize' => 'lg',
                ],
            ],
        ]));

        $content = [
            'fr' => [
                'intro' => [EditorBlocks::header('Une page composée par zones'),
                    EditorBlocks::paragraph('Chaque bloc ci-dessous est une zone posée sur une grille de 48 colonnes. Leur largeur se règle indépendamment, et ce qui les remplit se traduit - la disposition, elle, est écrite une seule fois.')],
                'beside' => [EditorBlocks::header('Une zone haute, deux zones à côté', 3),
                    EditorBlocks::paragraph('À gauche une image en portrait ; à droite une pile, qui prend la hauteur de la ligne et la partage entre ce texte et l\'image du dessous. Aucune hauteur n\'est réglée nulle part.')],
                'outro' => [EditorBlocks::paragraph("Une zone pleine largeur pour refermer. Modifiez tout ceci depuis l'administration, onglet Contenu.")],
            ],
            'en' => [
                'intro' => [EditorBlocks::header('A page laid out in zones'),
                    EditorBlocks::paragraph('Every block below is a zone on a 48-column grid. Widths are set independently, and what fills them is translated - the arrangement is written once.')],
                'beside' => [EditorBlocks::header('One tall zone, two beside it', 3),
                    EditorBlocks::paragraph('A portrait picture on the left; on the right a stack, which takes the height of the row and splits it between this text and the picture under it. No height is set anywhere.')],
                'outro' => [EditorBlocks::paragraph('A full-width zone to close. Change any of this from the backend, under Content.')],
            ],
            'es' => [
                'intro' => [EditorBlocks::header('Una página compuesta por zonas'),
                    EditorBlocks::paragraph('Cada bloque de abajo es una zona colocada sobre una cuadrícula de 48 columnas. Su anchura se ajusta por separado, y lo que las llena se traduce: la disposición, en cambio, se escribe una sola vez.')],
                'beside' => [EditorBlocks::header('Una zona alta, dos zonas al lado', 3),
                    EditorBlocks::paragraph('A la izquierda una imagen en vertical; a la derecha una pila, que toma la altura de la fila y la reparte entre este texto y la imagen de abajo. No hay ninguna altura fijada en ninguna parte.')],
                'outro' => [EditorBlocks::paragraph('Una zona a todo lo ancho para cerrar. Modifique todo esto desde la administración, pestaña Contenido.')],
            ],
        ];

        $captions = [
            'fr' => ['alt' => 'Un paysage de démonstration', 'caption' => 'Une image, avec sa légende - les deux se traduisent, l\'image non.'],
            'en' => ['alt' => 'A demo landscape', 'caption' => 'A picture and its caption - both translated, the picture itself is not.'],
            'es' => ['alt' => 'Un paisaje de demostración', 'caption' => 'Una imagen, con su pie de foto: los dos se traducen, la imagen no.'],
        ];

        $bannerTexts = [
            'fr' => [
                'title' => 'Bienvenue sur Aurora',
                'description' => "Un en-tête composé dans l'éditeur : hauteur, largeur, dégradé, fondu et boutons.",
            ],
            'en' => [
                'title' => 'Welcome to Aurora',
                'description' => 'A header composed in the editor: height, width, gradient, fade and buttons.',
            ],
            'es' => [
                'title' => 'Bienvenido a Aurora',
                'description' => 'Un encabezado compuesto en el editor: altura, anchura, degradado, difuminado y botones.',
            ],
        ];

        $stackedCaptions = [
            'fr' => ['alt' => 'Un bureau de démonstration', 'caption' => 'La seconde moitié de la pile.'],
            'en' => ['alt' => 'A demo desk', 'caption' => 'The second half of the stack.'],
            'es' => ['alt' => 'Un escritorio de demostración', 'caption' => 'La segunda mitad de la pila.'],
        ];

        foreach (LocaleEnum::values() as $locale) {
            $translation = $welcome->translate($locale);

            $translation->setBanner($this->bannerNormalizer->normalizeTexts([
                'items' => ['banner-text' => $bannerTexts[$locale]],
            ], $welcome->getBannerLayout()));

            $translation->setGrid($this->gridNormalizer->normalizeContent([
                'zones' => [
                    'intro' => ['blocks' => $content[$locale]['intro']],
                    'picture' => $captions[$locale],
                    'beside' => ['blocks' => $content[$locale]['beside']],
                    'under' => $stackedCaptions[$locale],
                    'outro' => ['blocks' => $content[$locale]['outro']],
                ],
            ], $welcome->getGridLayout()));

            $this->indexForSearch($translation);
        }
    }

    /**
     * The article the welcome page links to gets a grid of its own, arranged
     * differently: a picture at a third beside its explanation at two thirds,
     * then two cards sharing a row - a "read next" strip, which is the shape
     * the linked-publication zone was added for.
     *
     * Different on purpose. Two demo pages laid out identically show one
     * arrangement twice and teach that the grid has a house style.
     *
     * @param array<string, PostInterface> $posts
     */
    /**
     * A gallery on two demo pages, one per layout.
     *
     * Both, on purpose: the two are different mechanisms rather than two sets of
     * classes - a grid crops every tile to one ratio and reads across a row,
     * masonry keeps each picture's proportions and reads down a column - and a
     * demo showing one of them teaches that the other is theoretical.
     *
     * On pages that already have a grid, because the point of the feature is
     * where it lands: under the content, without being asked. A gallery on an
     * otherwise empty page would show it working and not show it fitting.
     *
     * The library holds four images, so both galleries use the same four. The
     * normalizer refuses a picture twice **within** one gallery; across two posts
     * there is nothing to refuse.
     *
     * @param array<string, PostInterface> $posts
     */
    private function addGalleries(array $posts): void
    {
        $media = array_map(
            fn (int $index): Document => $this->getReference(GedDemoFixtures::mediaRef($index), Document::class),
            [0, 1, 2, 3],
        );

        $galleries = [
            // Portraits and a landscape at their own proportions: the case
            // masonry exists for, and the one where a fixed ratio would crop
            // the tall picture to nothing.
            'first-steps' => [
                'layout' => GalleryNormalizer::LAYOUT_MASONRY,
                'columns' => 3,
                'ratio' => GalleryNormalizer::RATIO_NATURAL,
                'words' => [
                    'fr' => [
                        ['Une bannière', 'Chaque image garde ses proportions.'],
                        ['Un paysage', 'Les colonnes se remplissent indépendamment.'],
                        ['Un portrait', 'Une image haute reste haute.'],
                        ['Un poste de travail', 'La lecture se fait colonne par colonne.'],
                    ],
                    'en' => [
                        ['A banner', 'Every picture keeps its proportions.'],
                        ['A landscape', 'The columns fill independently.'],
                        ['A portrait', 'A tall picture stays tall.'],
                        ['A workstation', 'This reads down a column, not across a row.'],
                    ],
                    'es' => [
                        ['Un banner', 'Cada imagen conserva sus proporciones.'],
                        ['Un paisaje', 'Las columnas se llenan de forma independiente.'],
                        ['Un retrato', 'Una imagen alta sigue siendo alta.'],
                        ['Un puesto de trabajo', 'La lectura va columna por columna, no de fila en fila.'],
                    ],
                ],
            ],
            // The same four, cropped square in four columns: uniform tiles, read
            // in the order they were written.
            'welcome' => [
                'layout' => GalleryNormalizer::LAYOUT_GRID,
                'columns' => 4,
                'ratio' => '1x1',
                'words' => [
                    'fr' => [
                        ['Une bannière', ''],
                        ['Un paysage', ''],
                        ['Un portrait', 'Recadrée au carré comme les autres.'],
                        ['Un poste de travail', ''],
                    ],
                    'en' => [
                        ['A banner', ''],
                        ['A landscape', ''],
                        ['A portrait', 'Cropped square like the rest.'],
                        ['A workstation', ''],
                    ],
                    'es' => [
                        ['Un banner', ''],
                        ['Un paisaje', ''],
                        ['Un retrato', 'Recortada en cuadrado como las demás.'],
                        ['Un puesto de trabajo', ''],
                    ],
                ],
            ],
        ];

        foreach ($galleries as $key => $definition) {
            $post = $posts[$key] ?? null;
            if (!$post instanceof PostInterface) {
                continue;
            }

            $items = [];
            foreach ($media as $index => $document) {
                $items[] = ['id' => sprintf('shot-%d', $index + 1), 'mediaId' => $document->getId()];
            }

            $layout = $this->galleryNormalizer->normalizeLayout([
                'enabled' => true,
                'layout' => $definition['layout'],
                'columns' => $definition['columns'],
                'ratio' => $definition['ratio'],
                'items' => $items,
            ]);
            $post->setGalleryLayout($layout);

            foreach (LocaleEnum::values() as $locale) {
                $words = [];
                foreach ($definition['words'][$locale] as $index => [$alt, $caption]) {
                    $words[sprintf('shot-%d', $index + 1)] = ['alt' => $alt, 'caption' => $caption];
                }

                $post->translate($locale)->setGallery(
                    $this->galleryNormalizer->normalizeContent(['items' => $words], $layout),
                );
            }
        }
    }

    private function layOutFirstStepsArticle(array $posts): void
    {
        $article = $posts['first-steps'];
        $shot = $this->getReference(GedDemoFixtures::mediaRef(3), Document::class);

        $article->setGridLayout($this->gridNormalizer->normalizeLayout([
            'enabled' => true,
            'snap' => 4,
            'zones' => [
                ['id' => 'lede', 'type' => GridNormalizer::ZONE_TEXT, 'span' => ['base' => 48, 'md' => null, 'lg' => 48]],
                ['id' => 'shot', 'type' => GridNormalizer::ZONE_MEDIA, 'span' => ['base' => 48, 'md' => null, 'lg' => 16], 'mediaId' => $shot->getId()],
                ['id' => 'explain', 'type' => GridNormalizer::ZONE_TEXT, 'span' => ['base' => 48, 'md' => null, 'lg' => 32]],
                ['id' => 'next-blocks', 'type' => GridNormalizer::ZONE_POST, 'span' => ['base' => 48, 'md' => null, 'lg' => 24], 'postId' => $posts['blocks']->getId()],
                ['id' => 'next-welcome', 'type' => GridNormalizer::ZONE_POST, 'span' => ['base' => 48, 'md' => null, 'lg' => 24], 'postId' => $posts['welcome']->getId()],
            ],
        ]));

        $content = [
            'fr' => [
                'lede' => [
                    EditorBlocks::header('Du brouillon à la mise en ligne'),
                    EditorBlocks::paragraph('Un article se compose de la même façon qu\'une page : des zones, une largeur chacune, et du contenu de nature différente dans chacune.'),
                ],
                'explain' => [
                    EditorBlocks::header('Une image au tiers, le texte aux deux tiers', 3),
                    EditorBlocks::paragraph('16 colonnes sur 48 pour la photo, 32 pour ce paragraphe. La page d\'accueil utilise l\'inverse - rien n\'impose une seule façon de découper une ligne.'),
                    EditorBlocks::list(['Ajoutez une zone', 'Réglez sa largeur au curseur', 'Remplissez-la']),
                ],
                'shot' => ['alt' => 'Un poste de travail', 'caption' => 'Une photo au tiers de la largeur.'],
            ],
            'en' => [
                'lede' => [
                    EditorBlocks::header('From draft to published'),
                    EditorBlocks::paragraph('An article is composed the same way a page is: zones, a width each, and content of a different kind in every one.'),
                ],
                'explain' => [
                    EditorBlocks::header('A picture at a third, the text at two thirds', 3),
                    EditorBlocks::paragraph('16 of 48 columns for the photo, 32 for this paragraph. The welcome page uses the reverse - nothing forces one way of splitting a row.'),
                    EditorBlocks::list(['Add a zone', 'Set its width with the slider', 'Fill it']),
                ],
                'shot' => ['alt' => 'A workstation', 'caption' => 'A photo at a third of the width.'],
            ],
            'es' => [
                'lede' => [
                    EditorBlocks::header('Del borrador a la publicación'),
                    EditorBlocks::paragraph('Un artículo se compone igual que una página: zonas, una anchura para cada una, y contenido de distinta naturaleza en cada una de ellas.'),
                ],
                'explain' => [
                    EditorBlocks::header('Una imagen a un tercio, el texto a dos tercios', 3),
                    EditorBlocks::paragraph('16 columnas de 48 para la foto, 32 para este párrafo. La página de inicio usa lo contrario: nada obliga a repartir una fila de una sola manera.'),
                    EditorBlocks::list(['Añada una zona', 'Ajuste su anchura con el cursor', 'Rellénela']),
                ],
                'shot' => ['alt' => 'Un puesto de trabajo', 'caption' => 'Una foto a un tercio de la anchura.'],
            ],
        ];

        foreach (LocaleEnum::values() as $locale) {
            $translation = $article->translate($locale);

            $translation->setGrid($this->gridNormalizer->normalizeContent([
                'zones' => [
                    'lede' => ['blocks' => $content[$locale]['lede']],
                    'shot' => $content[$locale]['shot'],
                    'explain' => ['blocks' => $content[$locale]['explain']],
                ],
            ], $article->getGridLayout()));

            $this->indexForSearch($translation);
        }
    }

    /**
     * Une page qui pose, l'un sous l'autre, les blocs arrivés ensemble : c'est
     * elle que les captures du tour photographient, et c'est là qu'un auteur
     * voit à quoi chacun ressemble rempli plutôt que vide.
     *
     * @param array<string, PostInterface> $posts
     */
    private function layOutShowcasePage(array $posts): void
    {
        $page = $posts['showcase'];
        $media = fn (int $index): ?int => $this->getReference(GedDemoFixtures::mediaRef($index), Document::class)->getId();
        $full = ['base' => 48, 'md' => null, 'lg' => 48];
        $half = ['base' => 48, 'md' => null, 'lg' => 24];

        $page->setGridLayout($this->gridNormalizer->normalizeLayout([
            'enabled' => true,
            'snap' => 4,
            'zones' => [
                ['id' => 'available', 'type' => GridNormalizer::ZONE_AVAILABILITY, 'span' => $half, 'options' => ['availability' => 'soon', 'availableFrom' => new DateTimeImmutable('first day of next month')->format('Y-m-d')]],
                ['id' => 'launch', 'type' => GridNormalizer::ZONE_COUNTDOWN, 'span' => $half, 'options' => ['countdownAt' => new DateTimeImmutable('+12 days')->format('Y-m-d').'T18:30']],
                ['id' => 'hours', 'type' => GridNormalizer::ZONE_OPENING_HOURS, 'span' => $half, 'options' => ['hours' => [
                    'mon' => [], 'tue' => [['09:00', '12:30'], ['14:00', '19:00']], 'wed' => [['09:00', '12:30'], ['14:00', '19:00']],
                    'thu' => [['09:00', '12:30'], ['14:00', '19:00']], 'fri' => [['09:00', '19:00']], 'sat' => [['09:00', '17:00']], 'sun' => [],
                ], 'closedDates' => [new DateTimeImmutable('+20 days')->format('Y-m-d')]]],
                ['id' => 'card', 'type' => GridNormalizer::ZONE_CONTACT_CARD, 'span' => $half, 'mediaId' => $media(1), 'options' => ['contactName' => 'Camille Laurent', 'contactPhone' => '+33 6 12 34 56 78', 'contactEmail' => 'camille@studio-lumen.fr', 'contactWebsite' => 'https://studio-lumen.fr']],
                ['id' => 'post', 'type' => GridNormalizer::ZONE_SOCIAL_POST, 'span' => $half, 'mediaId' => $media(3), 'options' => ['socialNetwork' => 'instagram', 'socialName' => 'Studio Lumen', 'socialHandle' => 'studiolumen', 'socialAvatarId' => $media(1), 'socialLikes' => 1284, 'socialComments' => 46, 'socialDate' => new DateTimeImmutable('-3 days')->format('Y-m-d')]],
                ['id' => 'screen', 'type' => GridNormalizer::ZONE_MEDIA, 'span' => $half, 'mediaId' => $media(2), 'options' => ['frame' => 'laptop']],
                ['id' => 'slides', 'type' => GridNormalizer::ZONE_GALLERY, 'span' => $full, 'mediaIds' => [$media(0), $media(1), $media(2), $media(3)], 'ratio' => '16x9', 'options' => ['galleryLayout' => 'carousel']],
                ['id' => 'band', 'type' => GridNormalizer::ZONE_MEDIA, 'span' => $full, 'fullBleed' => true, 'mediaId' => $media(0), 'options' => ['parallax' => true]],
                ['id' => 'shell', 'type' => GridNormalizer::ZONE_CODE, 'span' => $half, 'options' => ['codeStyle' => 'terminal']],
                ['id' => 'change', 'type' => GridNormalizer::ZONE_CODE, 'span' => $half, 'language' => 'javascript', 'options' => ['codeStyle' => 'diff']],
                ['id' => 'qr', 'type' => GridNormalizer::ZONE_QR_CODE, 'span' => $half, 'size' => 'md', 'options' => ['qrLogoId' => $media(1)]],
                ['id' => 'index', 'type' => GridNormalizer::ZONE_POST_LIST, 'span' => $full, 'options' => ['listLayout' => 'index']],
            ],
        ]));

        $words = [
            'fr' => ['card' => 'Photographe', 'post' => "Lumière du matin sur le port, sans retouche.\nMerci à l'équipe du studio.", 'band' => 'Une image qui défile plus lentement que la page.', 'launch' => 'Ouverture de la boutique', 'after' => 'La boutique est ouverte.', 'hours' => 'Fermé les jours fériés.', 'qr' => 'Scannez pour ouvrir le site.'],
            'en' => ['card' => 'Photographer', 'post' => "Morning light over the harbour, straight out of camera.\nThanks to the studio team.", 'band' => 'A picture that scrolls slower than the page.', 'launch' => 'The shop opens', 'after' => 'The shop is open.', 'hours' => 'Closed on public holidays.', 'qr' => 'Scan to open the site.'],
            'es' => ['card' => 'Fotógrafa', 'post' => "Luz de la mañana sobre el puerto, sin retoques.\nGracias al equipo del estudio.", 'band' => 'Una imagen que se desplaza más despacio que la página.', 'launch' => 'Apertura de la tienda', 'after' => 'La tienda está abierta.', 'hours' => 'Cerrado los festivos.', 'qr' => 'Escanee para abrir el sitio.'],
        ];

        foreach (LocaleEnum::values() as $locale) {
            $translation = $page->translate($locale);
            $said = $words[$locale];

            $translation->setGrid($this->gridNormalizer->normalizeContent([
                'zones' => [
                    'card' => ['caption' => $said['card']],
                    'post' => ['caption' => $said['post']],
                    'band' => ['caption' => $said['band'], 'alt' => ''],
                    'launch' => ['label' => $said['launch'], 'caption' => $said['after']],
                    'hours' => ['caption' => $said['hours']],
                    'qr' => ['url' => '/'.$locale, 'label' => $said['qr']],
                    'shell' => ['code' => "$ make demo\nDemo data loaded\n$ make ft\nAll green"],
                    'change' => ['code' => "-const THRESHOLD = 0.08;\n+const THRESHOLD = 0;\n const MARGIN = \"0px 0px -8% 0px\";"],
                ],
            ], $page->getGridLayout()));

            $this->indexForSearch($translation);
        }
    }

    /**
     * Deux types de contenu à la démo, en plus des deux de l'installation.
     *
     * Les types livrés sont le plancher du produit, pas un exemple : un site
     * réel se fait de pages, d'articles, de services et de réalisations, et
     * une démo qui n'a que les deux premiers ne montre jamais à quoi
     * ressemble un type créé à la main - l'écran qui sert à en créer un se
     * lisait sur une liste où tout était coché « intégré ».
     *
     * Chacun a son archive, parce que c'est l'adresse que le menu vise :
     * `/fr/services` et `/fr/projets` n'existent pas autrement.
     *
     * @param array<string, PostTypeInterface> $builtIn les types de l'installation, keyed by slug
     *
     * @return array<string, PostTypeInterface> keyed by slug
     */
    private function createDemoPostTypes(EntityManagerInterface $em, array $builtIn): array
    {
        $definitions = [
            ['services', 'Services', 'briefcase'],
            ['projets', 'Projets', 'layout-grid'],
        ];

        $types = $builtIn;

        foreach ($definitions as [$slug, $label, $icon]) {
            // Réutilisé quand il est déjà là, comme les publications plus
            // bas : un second `make demo` rafraîchit la démo, il ne la double
            // pas - et le slug est unique.
            $type = $this->postTypeRepository->findOneBySlug($slug) ?? new PostType();

            $type
                ->setSlug($slug)
                ->setLabel($label)
                ->setIcon($icon)
                ->setHasArchive(true)
                ->setIsBuiltIn(false);

            $em->persist($type);
            $types[$slug] = $type;
        }

        return $types;
    }

    /**
     * Du faux latin, par phrases entières.
     *
     * Les pages ajoutées à la démo ont la forme de la production et le
     * contenu de rien du tout, ce qui est voulu : une démo qui porte de vrais
     * textes finit citée comme si elle disait quelque chose, et une démo dont
     * les titres sont du latin ne montre pas à quoi sert un menu. Les titres
     * sont donc vrais et tout le reste vient d'ici.
     *
     * Le décalage sert à ce que deux paragraphes voisins ne sortent pas
     * identiques : une page où le même bloc est recopié quatre fois se lit
     * comme un gabarit non rempli.
     */
    private function lorem(int $sentences, int $from = 0): string
    {
        $pool = [
            'Lorem ipsum dolor sit amet, consectetur adipiscing elit, sed do eiusmod tempor incididunt ut labore et dolore magna aliqua.',
            'Ut enim ad minim veniam, quis nostrud exercitation ullamco laboris nisi ut aliquip ex ea commodo consequat.',
            'Duis aute irure dolor in reprehenderit in voluptate velit esse cillum dolore eu fugiat nulla pariatur.',
            'Excepteur sint occaecat cupidatat non proident, sunt in culpa qui officia deserunt mollit anim id est laborum.',
            'Sed ut perspiciatis unde omnis iste natus error sit voluptatem accusantium doloremque laudantium.',
            'Totam rem aperiam, eaque ipsa quae ab illo inventore veritatis et quasi architecto beatae vitae dicta sunt explicabo.',
            'Nemo enim ipsam voluptatem quia voluptas sit aspernatur aut odit aut fugit, sed quia consequuntur magni dolores.',
            'Neque porro quisquam est qui dolorem ipsum quia dolor sit amet, consectetur, adipisci velit.',
            'At vero eos et accusamus et iusto odio dignissimos ducimus qui blanditiis praesentium voluptatum deleniti.',
            'Et harum quidem rerum facilis est et expedita distinctio, nam libero tempore cum soluta nobis est eligendi optio.',
        ];

        $sentences = max(1, $sentences);
        $out = [];

        for ($i = 0; $i < $sentences; ++$i) {
            $out[] = $pool[($from + $i) % count($pool)];
        }

        return implode(' ', $out);
    }

    /**
     * La page « À propos » : du texte, une liste d'étapes, puis les services.
     *
     * La zone d'étapes et la liste automatique sont les deux qu'une page
     * institutionnelle porte toujours et que la démo n'avait nulle part - la
     * seconde surtout, qui est la différence entre une page à retoucher à
     * chaque publication et une page qui se tient à jour toute seule.
     *
     * @param array<string, PostInterface>     $posts
     * @param array<string, PostTypeInterface> $types
     */
    private function layOutAboutPage(array $posts, array $types): void
    {
        $about = $posts['about'] ?? null;
        if (!$about instanceof PostInterface) {
            return;
        }

        $steps = ['step-1', 'step-2', 'step-3'];

        $about->setGridLayout($this->gridNormalizer->normalizeLayout([
            'enabled' => true,
            'snap' => 4,
            'zones' => [
                ['id' => 'intro', 'type' => GridNormalizer::ZONE_TEXT, 'span' => ['base' => 48, 'md' => null, 'lg' => 48]],
                [
                    'id' => 'steps',
                    'type' => GridNormalizer::ZONE_ITEMS,
                    'span' => ['base' => 48, 'md' => null, 'lg' => 48],
                    'display' => 'steps',
                    'columns' => 3,
                    'items' => array_map(static fn (string $id): array => ['id' => $id], $steps),
                ],
                ['id' => 'listing', 'type' => GridNormalizer::ZONE_TEXT, 'span' => ['base' => 48, 'md' => null, 'lg' => 48]],
                [
                    'id' => 'services',
                    'type' => GridNormalizer::ZONE_POST_LIST,
                    'span' => ['base' => 48, 'md' => null, 'lg' => 48],
                    'postTypeId' => $types['services']->getId(),
                    'limit' => 3,
                    'columns' => 3,
                    'cardVariant' => 'full',
                ],
            ],
        ]));

        $headings = [
            'fr' => ['Qui nous sommes', 'Nos services'],
            'en' => ['Who we are', 'Our services'],
            'es' => ['Quiénes somos', 'Nuestros servicios'],
        ];

        foreach (LocaleEnum::values() as $locale) {
            $translation = $about->translate($locale);
            [$heading, $listing] = $headings[$locale];

            $translation->setGrid($this->gridNormalizer->normalizeContent([
                'zones' => [
                    'intro' => ['blocks' => [
                        EditorBlocks::header($heading),
                        EditorBlocks::paragraph($this->lorem(3)),
                    ]],
                    'steps' => ['items' => [
                        'step-1' => ['title' => 'Lorem ipsum', 'description' => $this->lorem(1, 1)],
                        'step-2' => ['title' => 'Dolor sit amet', 'description' => $this->lorem(1, 2)],
                        'step-3' => ['title' => 'Consectetur elit', 'description' => $this->lorem(1, 3)],
                    ]],
                    'listing' => ['blocks' => [EditorBlocks::header($listing)]],
                ],
            ], $about->getGridLayout()));

            $this->indexForSearch($translation);
        }
    }

    /**
     * Les trois services, sur un gabarit image/texte alterné.
     *
     * Un gabarit, justement : les trois pages partagent la même disposition
     * et seul leur contenu change, ce qui est la façon dont un site range ses
     * offres - trois compositions différentes pour trois services se lisent
     * comme trois essais plutôt que comme une rubrique.
     *
     * L'image passe de gauche à droite d'une ligne à l'autre, ce qu'on obtient
     * ici sans réglage : les zones se suivent dans l'ordre écrit.
     *
     * @param array<string, PostInterface> $posts
     */
    private function layOutServicePages(array $posts): void
    {
        $pages = [
            'service-web' => [0, 2],
            'service-photo' => [1, 3],
            'service-social' => [2, 0],
        ];

        $words = [
            'fr' => ['Ce que nous faisons', 'Comment nous travaillons', ['Lorem ipsum dolor', 'Consectetur adipiscing', 'Sed do eiusmod tempor']],
            'en' => ['What we do', 'How we work', ['Lorem ipsum dolor', 'Consectetur adipiscing', 'Sed do eiusmod tempor']],
            'es' => ['Lo que hacemos', 'Cómo trabajamos', ['Lorem ipsum dolor', 'Consectetur adipiscing', 'Sed do eiusmod tempor']],
        ];

        $offset = 0;

        foreach ($pages as $key => [$first, $second]) {
            $post = $posts[$key] ?? null;
            if (!$post instanceof PostInterface) {
                continue;
            }

            $top = $this->getReference(GedDemoFixtures::mediaRef($first), Document::class);
            $bottom = $this->getReference(GedDemoFixtures::mediaRef($second), Document::class);

            $post->setGridLayout($this->gridNormalizer->normalizeLayout([
                'enabled' => true,
                'snap' => 4,
                'zones' => [
                    ['id' => 'lede', 'type' => GridNormalizer::ZONE_TEXT, 'span' => ['base' => 48, 'md' => null, 'lg' => 48], 'textSize' => 'lead'],
                    ['id' => 'shot-one', 'type' => GridNormalizer::ZONE_MEDIA, 'span' => ['base' => 48, 'md' => null, 'lg' => 24], 'ratio' => '4x3', 'mediaId' => $top->getId()],
                    ['id' => 'pitch', 'type' => GridNormalizer::ZONE_TEXT, 'span' => ['base' => 48, 'md' => null, 'lg' => 24]],
                    ['id' => 'method', 'type' => GridNormalizer::ZONE_TEXT, 'span' => ['base' => 48, 'md' => null, 'lg' => 24]],
                    ['id' => 'shot-two', 'type' => GridNormalizer::ZONE_MEDIA, 'span' => ['base' => 48, 'md' => null, 'lg' => 24], 'ratio' => '4x3', 'mediaId' => $bottom->getId()],
                ],
            ]));

            foreach (LocaleEnum::values() as $locale) {
                $translation = $post->translate($locale);
                [$doing, $working, $points] = $words[$locale];
                $title = $translation->getTitle();

                $translation->setGrid($this->gridNormalizer->normalizeContent([
                    'zones' => [
                        // Pas de titre ici : le gabarit imprime déjà celui
                        // de la publication, et un `h2` qui le répète se lit
                        // comme une erreur de saisie.
                        'lede' => ['blocks' => [EditorBlocks::paragraph($this->lorem(2, $offset))]],
                        'shot-one' => ['alt' => $title, 'caption' => ''],
                        'pitch' => ['blocks' => [
                            EditorBlocks::header($doing, 3),
                            EditorBlocks::paragraph($this->lorem(2, $offset + 2)),
                            EditorBlocks::list($points),
                        ]],
                        'method' => ['blocks' => [
                            EditorBlocks::header($working, 3),
                            EditorBlocks::paragraph($this->lorem(3, $offset + 4)),
                        ]],
                        'shot-two' => ['alt' => $title, 'caption' => ''],
                    ],
                ], $post->getGridLayout()));

                $this->indexForSearch($translation);
            }

            ++$offset;
        }
    }

    /**
     * Les deux réalisations : une image large, un récit, des chiffres.
     *
     * Les chiffres sont une liste d'entrées en costume « stats », qui est la
     * même zone que les étapes de la page « À propos » portée autrement -
     * deux démonstrations d'un seul mécanisme, ce qui est exactement ce que la
     * zone existe pour montrer.
     *
     * @param array<string, PostInterface> $posts
     */
    private function layOutProjectPages(array $posts): void
    {
        $pages = ['project-lumen' => 3, 'project-atlas' => 0];

        $words = [
            'fr' => ['Le projet', ['Projets livrés', 'Semaines', 'Personnes']],
            'en' => ['The project', ['Projects shipped', 'Weeks', 'People']],
            'es' => ['El proyecto', ['Proyectos entregados', 'Semanas', 'Personas']],
        ];

        $offset = 2;

        foreach ($pages as $key => $mediaIndex) {
            $post = $posts[$key] ?? null;
            if (!$post instanceof PostInterface) {
                continue;
            }

            $cover = $this->getReference(GedDemoFixtures::mediaRef($mediaIndex), Document::class);

            $post->setGridLayout($this->gridNormalizer->normalizeLayout([
                'enabled' => true,
                'snap' => 4,
                'zones' => [
                    ['id' => 'cover', 'type' => GridNormalizer::ZONE_MEDIA, 'span' => ['base' => 48, 'md' => null, 'lg' => 48], 'ratio' => '16x9', 'mediaId' => $cover->getId()],
                    ['id' => 'story', 'type' => GridNormalizer::ZONE_TEXT, 'span' => ['base' => 48, 'md' => null, 'lg' => 28]],
                    [
                        'id' => 'facts',
                        'type' => GridNormalizer::ZONE_ITEMS,
                        'span' => ['base' => 48, 'md' => null, 'lg' => 20],
                        'display' => 'stats',
                        'columns' => 3,
                        'items' => [['id' => 'fact-1'], ['id' => 'fact-2'], ['id' => 'fact-3']],
                    ],
                ],
            ]));

            foreach (LocaleEnum::values() as $locale) {
                $translation = $post->translate($locale);
                [$heading, $labels] = $words[$locale];
                $title = $translation->getTitle();

                $translation->setGrid($this->gridNormalizer->normalizeContent([
                    'zones' => [
                        'cover' => ['alt' => $title, 'caption' => ''],
                        'story' => ['blocks' => [
                            EditorBlocks::header($heading),
                            EditorBlocks::paragraph($this->lorem(3, $offset)),
                            EditorBlocks::quote($this->lorem(1, $offset + 3), 'Lorem Ipsum'),
                        ]],
                        'facts' => ['items' => [
                            'fact-1' => ['title' => '24', 'description' => $labels[0]],
                            'fact-2' => ['title' => '12', 'description' => $labels[1]],
                            'fact-3' => ['title' => '4', 'description' => $labels[2]],
                        ]],
                    ],
                ], $post->getGridLayout()));

                $this->indexForSearch($translation);
            }

            $offset += 4;
        }
    }

    /**
     * La page de contact : deux mots, puis le formulaire lui-même.
     *
     * Le formulaire a déjà une page à lui, et c'est justement ce que cette
     * zone évite - quelqu'un qui vient de lire ce que vous faites remplit un
     * formulaire posé là, pas un lien qui lui demande d'aller ailleurs.
     *
     * @param array<string, PostInterface> $posts
     */
    private function layOutContactPage(array $posts, FormInterface $form): void
    {
        $contact = $posts['contact'] ?? null;
        if (!$contact instanceof PostInterface) {
            return;
        }

        $contact->setGridLayout($this->gridNormalizer->normalizeLayout([
            'enabled' => true,
            'snap' => 4,
            'zones' => [
                ['id' => 'intro', 'type' => GridNormalizer::ZONE_TEXT, 'span' => ['base' => 48, 'md' => null, 'lg' => 32]],
                ['id' => 'reach', 'type' => GridNormalizer::ZONE_TEXT, 'span' => ['base' => 48, 'md' => null, 'lg' => 16]],
                ['id' => 'form', 'type' => GridNormalizer::ZONE_FORM, 'span' => ['base' => 48, 'md' => null, 'lg' => 48], 'formId' => $form->getId()],
            ],
        ]));

        // Du vrai texte, et pas du lorem.
        //
        // Cette page illustre le site public sur le tour d'Aurora, et une
        // capture où le premier paragraphe commence par « Totam rem aperiam »
        // ne se lit pas comme une démonstration : elle se lit comme un site
        // qu'on n'a pas fini. Le lorem reste bon pour une page de remplissage
        // dont personne ne photographie le contenu.
        $headings = [
            'fr' => [
                'Nous écrire',
                'Décrivez votre projet en quelques lignes : ce que vous faites, ce dont vous avez besoin, et sous quel délai. Un devis chiffré suit sous 48 heures, sans engagement.',
                'Nous joindre',
                ['contact@example.com', '+33 1 23 45 67 89', '12 rue des Lilas, 75011 Paris'],
            ],
            'en' => [
                'Write to us',
                'Describe your project in a few lines: what you do, what you need, and by when. A costed quote follows within 48 hours, with no commitment.',
                'Reach us',
                ['contact@example.com', '+33 1 23 45 67 89', '12 rue des Lilas, 75011 Paris'],
            ],
            'es' => [
                'Escríbanos',
                'Describa su proyecto en unas líneas: a qué se dedica, qué necesita y en qué plazo. Le enviamos un presupuesto en 48 horas, sin compromiso.',
                'Cómo localizarnos',
                ['contact@example.com', '+33 1 23 45 67 89', '12 rue des Lilas, 75011 Paris'],
            ],
        ];

        foreach (LocaleEnum::values() as $locale) {
            $translation = $contact->translate($locale);
            [$heading, $lead, $aside, $details] = $headings[$locale];

            $translation->setGrid($this->gridNormalizer->normalizeContent([
                'zones' => [
                    'intro' => ['blocks' => [
                        EditorBlocks::header($heading),
                        EditorBlocks::paragraph($lead),
                    ]],
                    'reach' => ['blocks' => [
                        EditorBlocks::header($aside, 3),
                        EditorBlocks::list($details),
                    ]],
                ],
            ], $contact->getGridLayout()));

            $this->indexForSearch($translation);
        }
    }

    /**
     * Des publications liées entre elles.
     *
     * Le champ existait et n'était rempli nulle part, si bien que l'écran qui
     * le règle et le bandeau « à lire ensuite » se lisaient tous les deux
     * comme des fonctionnalités mortes. Par paires, et dans les deux sens :
     * la relation n'est pas dirigée pour un lecteur, alors que la table, elle,
     * l'est.
     *
     * @param array<string, PostInterface> $posts
     */
    private function relatePosts(array $posts): void
    {
        $pairs = [
            ['project-lumen', 'project-atlas'],
            ['service-web', 'service-social'],
            ['first-steps', 'blocks'],
        ];

        foreach ($pairs as [$left, $right]) {
            $one = $posts[$left] ?? null;
            $other = $posts[$right] ?? null;
            if (!$one instanceof PostInterface) {
                continue;
            }

            if (!$other instanceof PostInterface) {
                continue;
            }

            $one->addRelatedPost($other);
            $other->addRelatedPost($one);
        }
    }

    /**
     * A body in the shape the *editor* expects - which is stricter than what
     * the renderer accepts, and the reason these go through EditorBlocks
     * rather than being written out by hand.
     *
     * @return array<int, array<string, mixed>>
     */
    private function blocks(string $title, string $description, string $locale): array
    {
        // Written per language. It used to be French whatever the locale, so
        // an English demo page carried an English title over a French
        // paragraph - which is precisely the mistake the multilingual demo
        // exists to show you avoiding.
        $copy = [
            'fr' => ['Ce contenu est une démonstration. Remplacez-le par le vôtre depuis l\'administration.', ['Un premier point', 'Un deuxième point']],
            'en' => ['This content is a demonstration. Replace it with your own from the backend.', ['A first point', 'A second point']],
            'es' => ['Este contenido es una demostración. Sustitúyalo por el suyo desde la administración.', ['Un primer punto', 'Un segundo punto']],
        ];

        [$paragraph, $points] = $copy[$locale] ?? $copy['fr'];

        return [
            EditorBlocks::paragraph($description),
            EditorBlocks::header($title),
            EditorBlocks::paragraph($paragraph),
            EditorBlocks::list($points),
        ];
    }

    /**
     * The same flattening the Manager does on save. Without it a demo post
     * exists but no search finds it, which reads as a broken search rather
     * than as an unindexed fixture.
     */
    private function indexForSearch(PostTranslationInterface $translation): void
    {
        $translation->setSearchContent($this->textExtractor->extract($translation));
    }

    /**
     * Hangs the demo pages off the primary menu the install created, rather
     * than making a second menu: a location holds one menu, and a demo that
     * needed its own would be demonstrating something the product cannot do.
     *
     * @param array<string, PostInterface>     $posts
     * @param array<string, PostTypeInterface> $types
     */
    private function fillPrimaryMenu(EntityManagerInterface $em, array $posts, array $types): void
    {
        $menu = $this->menuRepository->findOneByLocation('primary');
        if (!$menu instanceof MenuInterface) {
            return;
        }

        $entries = [
            ['post' => 'welcome', 'fr' => 'Bienvenue', 'en' => 'Welcome', 'es' => 'Bienvenida'],
            ['post' => 'about', 'fr' => 'À propos', 'en' => 'About', 'es' => 'Acerca de'],
            ['post' => 'first-steps', 'fr' => 'Premiers pas', 'en' => 'Getting started', 'es' => 'Primeros pasos'],
            ['post' => 'contact', 'fr' => 'Contact', 'en' => 'Contact', 'es' => 'Contacto'],
        ];

        // Les deux archives, qui sont l'autre chose qu'un menu vise : une
        // entrée qui pointe un type suit ses publications sans qu'on y
        // retouche, là où une entrée par service serait à refaire au suivant.
        $archives = [
            ['type' => 'services', 'fr' => 'Services', 'en' => 'Services', 'es' => 'Servicios'],
            ['type' => 'projets', 'fr' => 'Projets', 'en' => 'Projects', 'es' => 'Proyectos'],
        ];

        // The seeded "Home" entry sits at position 0; these follow it.
        $position = 1;

        foreach ($entries as $entry) {
            $post = $posts[$entry['post']] ?? null;
            if (!$post instanceof PostInterface) {
                continue;
            }

            // Reused when this menu already points at that publication.
            // Without it every `make demo` appended another entry, and the
            // topbar grew a copy of each link per run - which is exactly what
            // it did here before this line existed.
            $item = $this->menuItemRepository->findOneBy([
                'menu' => $menu,
                'targetType' => MenuItemTargetTypeEnum::Post,
                'targetId' => $post->getId(),
            ]) ?? new MenuItem();

            $item
                ->setTargetType(MenuItemTargetTypeEnum::Post)
                ->setTargetId($post->getId())
                ->setPosition($position++);

            foreach (LocaleEnum::values() as $locale) {
                $item->translate($locale)->setLabel($entry[$locale] ?? $entry['fr']);
            }

            $menu->addItem($item);
            $em->persist($item);
        }

        foreach ($archives as $entry) {
            $type = $types[$entry['type']] ?? null;
            if (!$type instanceof PostTypeInterface) {
                continue;
            }

            $item = $this->menuItemRepository->findOneBy([
                'menu' => $menu,
                'targetType' => MenuItemTargetTypeEnum::PostTypeArchive,
                'targetId' => $type->getId(),
            ]) ?? new MenuItem();

            $item
                ->setTargetType(MenuItemTargetTypeEnum::PostTypeArchive)
                ->setTargetId($type->getId())
                ->setPosition($position++);

            foreach (LocaleEnum::values() as $locale) {
                $item->translate($locale)->setLabel($entry[$locale] ?? $entry['fr']);
            }

            $menu->addItem($item);
            $em->persist($item);
        }
    }

    /**
     * A form with something in it.
     *
     * The builder, the field types, the conditional display and the list of
     * requests received all show the same screen on an empty demo: "no form".
     * This one is a quote request in two steps, and it carries one field of
     * every type the product offers, because the screen that lists the types
     * is only worth looking at when each of them is there to be seen.
     *
     * Idempotent on its French slug: `make demo` twice must not leave two.
     */
    private function createQuoteForm(): FormInterface
    {
        // Rendu plutôt que tu : la page de contact pose ce formulaire-là, et
        // au second passage il existe déjà - lui répondre `null` lui ferait
        // perdre sa zone à chaque `make demo`.
        $existing = $this->formTranslationRepository->findOneByLocaleAndSlug('fr', 'demande-de-devis');
        if ($existing instanceof FormTranslationInterface) {
            return $existing->getForm();
        }

        $form = $this->forms->create(new FormInput(
            translations: [
                'fr' => ['title' => 'Demande de devis', 'slug' => 'demande-de-devis', 'description' => 'Décrivez votre projet, nous revenons vers vous sous 48 heures.'],
                'en' => ['title' => 'Quote request', 'slug' => 'quote-request', 'description' => 'Tell us about your project, we answer within 48 hours.'],
                'es' => ['title' => 'Solicitud de presupuesto', 'slug' => 'solicitud-de-presupuesto', 'description' => 'Cuéntenos su proyecto, respondemos en 48 horas.'],
            ],
            notifyEmail: 'contact@example.com',
            steps: [
                ['title' => 'Vos coordonnées'],
                ['title' => 'Votre projet'],
            ],
        ));

        $fields = [];
        foreach ($this->quoteFormFields() as $key => $definition) {
            // The conditional field points at another field by id, so it is
            // written after the one it depends on - hence the loop rather
            // than one createField call per line.
            $conditions = [];
            if (isset($definition['showsWhen'])) {
                [$dependency, $value] = $definition['showsWhen'];
                $conditions = [['fieldId' => (int) $fields[$dependency]->getId(), 'value' => $value]];
            }

            $fields[$key] = $this->forms->createField($form, new FormFieldInput(
                translations: $definition['translations'],
                type: $definition['type'],
                required: $definition['required'] ?? false,
                conditions: $conditions,
                step: $definition['step'],
            ));
        }

        $this->submitQuoteForm($form, $fields);

        return $form;
    }

    /**
     * @return array<string, array{type: FormFieldTypeEnum, step: int, required?: bool, showsWhen?: array{string, string}, translations: array<string, array{label: string, placeholder: ?string, options: list<string>}>}>
     */
    private function quoteFormFields(): array
    {
        return [
            'name' => [
                'type' => FormFieldTypeEnum::Text,
                'step' => 1,
                'required' => true,
                'translations' => [
                    'fr' => ['label' => 'Nom complet', 'placeholder' => 'Camille Durand', 'options' => []],
                    'en' => ['label' => 'Full name', 'placeholder' => 'Camille Durand', 'options' => []],
                    'es' => ['label' => 'Nombre completo', 'placeholder' => 'Camille Durand', 'options' => []],
                ],
            ],
            'email' => [
                'type' => FormFieldTypeEnum::Email,
                'step' => 1,
                'required' => true,
                'translations' => [
                    'fr' => ['label' => 'Adresse e-mail', 'placeholder' => 'camille@exemple.fr', 'options' => []],
                    'en' => ['label' => 'Email address', 'placeholder' => 'camille@example.com', 'options' => []],
                    'es' => ['label' => 'Correo electrónico', 'placeholder' => 'camille@ejemplo.es', 'options' => []],
                ],
            ],
            'phone' => [
                'type' => FormFieldTypeEnum::Tel,
                'step' => 1,
                'translations' => [
                    'fr' => ['label' => 'Téléphone', 'placeholder' => '06 12 34 56 78', 'options' => []],
                    'en' => ['label' => 'Phone', 'placeholder' => '+33 6 12 34 56 78', 'options' => []],
                    'es' => ['label' => 'Teléfono', 'placeholder' => '+34 612 34 56 78', 'options' => []],
                ],
            ],
            'source' => [
                'type' => FormFieldTypeEnum::Radio,
                'step' => 1,
                'translations' => [
                    'fr' => ['label' => 'Comment nous avez-vous connus ?', 'placeholder' => null, 'options' => ['Recherche web', 'Recommandation', 'Réseaux sociaux']],
                    'en' => ['label' => 'How did you hear about us?', 'placeholder' => null, 'options' => ['Web search', 'Word of mouth', 'Social media']],
                    'es' => ['label' => '¿Cómo nos ha conocido?', 'placeholder' => null, 'options' => ['Búsqueda web', 'Recomendación', 'Redes sociales']],
                ],
            ],
            'project' => [
                'type' => FormFieldTypeEnum::Select,
                'step' => 2,
                'required' => true,
                'translations' => [
                    'fr' => ['label' => 'Type de projet', 'placeholder' => null, 'options' => ['Site vitrine', 'Boutique en ligne', 'Application métier']],
                    'en' => ['label' => 'Kind of project', 'placeholder' => null, 'options' => ['Showcase site', 'Online shop', 'Business application']],
                    'es' => ['label' => 'Tipo de proyecto', 'placeholder' => null, 'options' => ['Sitio de presentación', 'Tienda en línea', 'Aplicación de gestión']],
                ],
            ],
            'references' => [
                'type' => FormFieldTypeEnum::Number,
                'step' => 2,
                // The one field nobody sees unless they need it: a shop is
                // the only answer that makes a catalogue size worth asking.
                'showsWhen' => ['project', 'Boutique en ligne'],
                'translations' => [
                    'fr' => ['label' => 'Nombre de références au catalogue', 'placeholder' => '250', 'options' => []],
                    'en' => ['label' => 'Number of catalogue items', 'placeholder' => '250', 'options' => []],
                    'es' => ['label' => 'Número de referencias del catálogo', 'placeholder' => '250', 'options' => []],
                ],
            ],
            'deadline' => [
                'type' => FormFieldTypeEnum::Date,
                'step' => 2,
                'translations' => [
                    'fr' => ['label' => 'Mise en ligne souhaitée', 'placeholder' => null, 'options' => []],
                    'en' => ['label' => 'Preferred launch date', 'placeholder' => null, 'options' => []],
                    'es' => ['label' => 'Fecha de lanzamiento deseada', 'placeholder' => null, 'options' => []],
                ],
            ],
            'message' => [
                'type' => FormFieldTypeEnum::Textarea,
                'step' => 2,
                'required' => true,
                'translations' => [
                    'fr' => ['label' => 'Votre projet en quelques lignes', 'placeholder' => 'Ce que vous vendez, à qui, et ce qui existe déjà.', 'options' => []],
                    'en' => ['label' => 'Your project in a few lines', 'placeholder' => 'What you sell, to whom, and what already exists.', 'options' => []],
                    'es' => ['label' => 'Su proyecto en unas líneas', 'placeholder' => 'Qué vende, a quién y qué existe ya.', 'options' => []],
                ],
            ],
            // "Cases à cocher" is a list of choices, not a single flag: the
            // value it stores is the set of options ticked. A checkbox field
            // with no option renders as a label above nothing.
            'consent' => [
                'type' => FormFieldTypeEnum::Checkbox,
                'step' => 2,
                'required' => true,
                'translations' => [
                    'fr' => ['label' => 'Accord', 'placeholder' => null, 'options' => ["J'accepte d'être recontacté au sujet de cette demande"]],
                    'en' => ['label' => 'Consent', 'placeholder' => null, 'options' => ['I agree to be contacted about this request']],
                    'es' => ['label' => 'Consentimiento', 'placeholder' => null, 'options' => ['Acepto que me contacten sobre esta solicitud']],
                ],
            ],
        ];
    }

    /**
     * Three requests already received.
     *
     * Going through the manager rather than writing rows: a submission gets
     * its reference from the sequence, and a list of requests whose reference
     * column is empty shows a screen the product never produces.
     *
     * @param array<string, FormFieldInterface> $fields
     */
    private function submitQuoteForm(FormInterface $form, array $fields): void
    {
        $answers = [
            [
                'name' => 'Camille Durand',
                'email' => 'camille.durand@example.com',
                'phone' => '06 12 34 56 78',
                'source' => 'Recommandation',
                'project' => 'Boutique en ligne',
                'references' => '250',
                'deadline' => '2026-03-02',
                'message' => 'Nous vendons du matériel de randonnée dans deux boutiques et nous voulons ouvrir en ligne avant la saison.',
                'consent' => ["J'accepte d'être recontacté au sujet de cette demande"],
            ],
            [
                'name' => 'Yann Lefebvre',
                'email' => 'y.lefebvre@example.org',
                'phone' => '07 88 45 12 03',
                'source' => 'Recherche web',
                'project' => 'Site vitrine',
                'deadline' => '2026-01-15',
                'message' => 'Un cabinet de trois architectes, un site qui montre les chantiers livrés et rien de plus.',
                'consent' => ["J'accepte d'être recontacté au sujet de cette demande"],
            ],
            [
                'name' => 'Sofia Marchetti',
                'email' => 'sofia.marchetti@example.net',
                'source' => 'Réseaux sociaux',
                'project' => 'Application métier',
                'message' => "Le suivi des interventions se fait aujourd'hui sur un tableur partagé, et il ne tient plus.",
                'consent' => ["J'accepte d'être recontacté au sujet de cette demande"],
            ],
        ];

        foreach ($answers as $answer) {
            $data = [];
            foreach ($answer as $key => $value) {
                $data[(string) $fields[$key]->getId()] = $value;
            }

            $this->forms->submit($form, $data, 'fr', '203.0.113.42');
        }
    }

    /**
     * Des commentaires, dans les trois états, avec un fil et des réactions.
     *
     * L'écran de modération et la page publique d'un article s'ouvraient tous
     * les deux sur « Aucun commentaire » : on y voyait où la fonction se
     * trouve, et rien de ce qu'elle fait. Les trois statuts sont là parce que
     * c'est entre eux que l'écran sert à choisir, et le fil parce qu'une
     * réponse ne se range pas comme un commentaire de premier niveau.
     *
     * Idempotent sur la référence : `make demo` deux fois ne doit pas en
     * laisser six.
     *
     * @param array<string, PostInterface> $posts
     */
    /**
     * Deux révisions sur la page d'accueil, pour que l'historique montre
     * quelque chose.
     *
     * Le module en produit une à chaque enregistrement, mais les fixtures
     * écrivent leurs publications en direct : la modale « Historique des
     * versions » s'ouvrait donc sur un écran vide, alors que c'est une des
     * fonctions que la page publique du module met en avant.
     *
     * Le contenu de chaque révision est une **vraie** photographie de la
     * publication, prise par le même service que le gestionnaire ; seuls le
     * titre et le résumé sont remontés d'un cran, pour qu'une comparaison ait
     * une différence à montrer. Autrement dit : ce sont deux états par
     * lesquels cette page aurait pu passer, pas deux lignes inventées.
     *
     * Idempotente : une publication qui a déjà des révisions n'en reçoit pas
     * de nouvelles à chaque `make demo`.
     *
     * @param array<string, PostInterface> $posts
     */
    private function createRevisions(ObjectManager $manager, array $posts): void
    {
        $post = $posts['welcome'] ?? null;

        if (!$post instanceof PostInterface || [] !== $this->revisionRepository->findBy(['post' => $post])) {
            return;
        }

        $etapes = [
            ['jours' => 9, 'titre' => 'Accueil', 'resume' => "Page d'accueil."],
            ['jours' => 3, 'titre' => 'Bienvenue', 'resume' => "La page d'accueil du site."],
        ];

        // Une version signée plutôt qu'« Auteur inconnu » deux fois : dans un
        // historique, qui a enregistré fait partie de ce qu'on vient y lire.
        $auteur = $this->userRepository->findOneBy([
            'email' => 'dev@aurora.app',
            'type' => UserTypeEnum::Backend->value,
        ]);

        $revisions = [];

        foreach ($etapes as $rang => $etape) {
            $snapshot = $this->snapshot->build($post);

            foreach (array_keys($snapshot['translations']) as $locale) {
                if ('fr' === $locale) {
                    $snapshot['translations'][$locale]['title'] = $etape['titre'];
                    $snapshot['translations'][$locale]['description'] = $etape['resume'];
                }
            }

            $revision = new PostRevision();
            $revision
                ->setPost($post)
                ->setPostVersion($rang + 1)
                ->setStatus($post->getStatus())
                ->setSnapshot($snapshot)
                ->setAuthor($auteur);

            $manager->persist($revision);
            $revisions[] = [$revision, $etape['jours']];
        }

        $manager->flush();

        // Les dates sont reculées après coup, en SQL.
        //
        // `TimestampableTrait` pose `createdAt` sur `PrePersist` et n'offre
        // aucun accesseur : c'est voulu, une date de création qui se règle
        // n'en est plus une. Ici on veut justement mentir un peu, pour qu'un
        // historique de démonstration ne montre pas deux versions à la même
        // seconde - ce qui n'apprend rien sur ce à quoi sert un historique.
        // Le mensonge tient dans ces quatre lignes, visible, plutôt que dans
        // un setter que tout le monde pourrait appeler.
        // `ObjectManager` ne connaît pas `getConnection` : c'est l'interface
        // de persistance, pas celle de Doctrine ORM. Le reste du fichier
        // travaille déjà sur l'implémentation.
        if (!$manager instanceof EntityManagerInterface) {
            return;
        }

        $connection = $manager->getConnection();

        foreach ($revisions as [$revision, $jours]) {
            $connection->executeStatement(
                'UPDATE core_post_revisions SET created_at = :date, updated_at = :date WHERE id = :id',
                ['date' => new DateTimeImmutable(sprintf('-%d days', $jours))->format('Y-m-d H:i:s'), 'id' => $revision->getId()],
            );
        }
    }

    private function createComments(EntityManagerInterface $em, array $posts): void
    {
        $post = $posts['first-steps'] ?? null;

        if (!$post instanceof PostInterface) {
            return;
        }

        $repository = $em->getRepository(Comment::class);
        $now = new DateTimeImmutable();

        $entries = [
            'approved' => [
                'status' => CommentStatusEnum::Approved,
                'name' => 'Camille Durand',
                'email' => 'camille.durand@example.com',
                'content' => "Merci pour ce guide, la partie sur les zones m'a débloquée. Une question : peut-on réutiliser une disposition d'une page à l'autre ?",
                'at' => $now->modify('-6 days'),
                'reactions' => [ReactionTypeEnum::Like, ReactionTypeEnum::Like, ReactionTypeEnum::Love],
            ],
            'reply' => [
                'status' => CommentStatusEnum::Approved,
                'name' => 'Yann Lefebvre',
                'email' => 'y.lefebvre@example.org',
                'content' => "Oui : dupliquez la publication, videz les textes, et vous gardez la grille. C'est ce que je fais pour les fiches produit.",
                'at' => $now->modify('-5 days'),
                'parent' => 'approved',
                'reactions' => [ReactionTypeEnum::Like],
            ],
            'pending' => [
                'status' => CommentStatusEnum::Pending,
                'name' => 'Sofia Marchetti',
                'email' => 'sofia.marchetti@example.net',
                'content' => 'Est-ce que la mise en ligne programmée fonctionne aussi pour les pages, ou seulement pour les articles ?',
                'at' => $now->modify('-2 days'),
                'reactions' => [],
            ],
            'spam' => [
                'status' => CommentStatusEnum::Spam,
                'name' => 'Best SEO Offer',
                'email' => 'contact@example-spam.test',
                'content' => 'Boostez votre trafic maintenant, tarifs imbattables, contactez-nous vite !',
                'at' => $now->modify('-1 day'),
                'reactions' => [],
            ],
        ];

        $created = [];

        foreach ($entries as $key => $entry) {
            $reference = sprintf('%s-DEMO-%s', SequencePrefixEnum::Comment->value, mb_strtoupper($key));
            $comment = $repository->findOneBy(['reference' => $reference]) ?? new Comment();

            $comment
                ->setReference($reference)
                ->setPost($post)
                ->setAuthorName($entry['name'])
                ->setAuthorEmail($entry['email'])
                ->setContent($entry['content'])
                ->setStatus($entry['status']);

            if (isset($entry['parent'])) {
                $comment->setParent($created[$entry['parent']]);
            }

            $em->persist($comment);
            $created[$key] = $comment;

            // Une empreinte par réaction : c'est ce qui tient lieu d'identité
            // à un visiteur sans compte, et deux réactions du même visiteur
            // sur le même commentaire n'en font qu'une. La base le garantit
            // par un index unique, ce qui faisait échouer le deuxième
            // `make demo` - d'où la recherche avant l'insertion.
            $reactions = $em->getRepository(CommentReaction::class);

            foreach ($entry['reactions'] as $index => $type) {
                $fingerprint = sprintf('demo-%s-%d', $key, $index);

                if (null !== $reactions->findOneBy(['comment' => $comment, 'fingerprint' => $fingerprint])) {
                    continue;
                }

                $reaction = new CommentReaction();
                $reaction
                    ->setComment($comment)
                    ->setType($type)
                    ->setFingerprint($fingerprint);

                $em->persist($reaction);
            }
        }

        $em->flush();

        // Après le flush : la date de création est posée par le constructeur,
        // et une démo dont les quatre commentaires portent la même minute ne
        // montre pas un fil de discussion.
        foreach ($entries as $key => $entry) {
            $this->backdate($em, $created[$key], $entry['at']);
        }

        $em->flush();
    }

    /** Repositionne la date d'un commentaire, que l'entité ne laisse pas écrire. */
    private function backdate(EntityManagerInterface $em, CommentInterface $comment, DateTimeImmutable $at): void
    {
        $em->getConnection()->executeStatement(
            'UPDATE core_comments SET created_at = :at WHERE id = :id',
            ['at' => $at->format('Y-m-d H:i:s'), 'id' => $comment->getId()],
        );
    }
}
