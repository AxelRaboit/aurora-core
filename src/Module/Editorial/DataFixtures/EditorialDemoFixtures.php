<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial\DataFixtures;

use Aurora\Core\DataFixtures\AppFixtures;
use Aurora\Core\DataFixtures\CoreDemoFixtures;
use Aurora\Module\Crm\Contact\Entity\Contact;
use Aurora\Module\Editorial\Comment\Entity\Comment;
use Aurora\Module\Editorial\Comment\Enum\CommentStatusEnum;
use Aurora\Module\Editorial\Form\Entity\Form;
use Aurora\Module\Editorial\Form\Entity\FormField;
use Aurora\Module\Editorial\Form\Entity\FormFieldTranslation;
use Aurora\Module\Editorial\Form\Entity\FormSubmission;
use Aurora\Module\Editorial\Form\Entity\FormTranslation;
use Aurora\Module\Editorial\Form\Enum\FormFieldTypeEnum;
use Aurora\Module\Editorial\Menu\Entity\Menu;
use Aurora\Module\Editorial\Menu\Entity\MenuItem;
use Aurora\Module\Editorial\Menu\Entity\MenuItemTranslation;
use Aurora\Module\Editorial\Menu\Enum\MenuItemTargetTypeEnum;
use Aurora\Module\Editorial\Post\Entity\Post;
use Aurora\Module\Editorial\Post\Entity\PostTranslation;
use Aurora\Module\Editorial\Post\Enum\PostStatusEnum;
use Aurora\Module\Editorial\PostType\Entity\PostType;
use Aurora\Module\Editorial\Taxonomy\Entity\Taxonomy;
use Aurora\Module\Editorial\Taxonomy\Entity\TaxonomyTerm;
use Aurora\Module\Ged\DataFixtures\GedDemoFixtures;
use Aurora\Module\Ged\Document\Entity\Document;
use Aurora\Module\Ged\Document\Service\DocumentUrlGenerator;
use Aurora\Module\Platform\User\Entity\User;
use DateTimeImmutable;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Bundle\FixturesBundle\FixtureGroupInterface;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ObjectManager;

/**
 * Demo editorial content: built-in post types & taxonomies, sample posts,
 * comments, forms and navigation menus. Dev/test only.
 */
class EditorialDemoFixtures extends Fixture implements DependentFixtureInterface, FixtureGroupInterface
{
    private const string LOREM = 'Lorem ipsum dolor sit amet, consectetur adipiscing elit. Sed do eiusmod tempor incididunt ut labore et dolore magna aliqua. Ut enim ad minim veniam, quis nostrud exercitation ullamco laboris nisi ut aliquip ex ea commodo consequat. Duis aute irure dolor in reprehenderit in voluptate velit esse cillum dolore eu fugiat nulla pariatur. Excepteur sint occaecat cupidatat non proident, sunt in culpa qui officia deserunt mollit anim id est laborum.';

    public function __construct(
        private readonly DocumentUrlGenerator $documentUrlGenerator,
    ) {}

    public static function getGroups(): array
    {
        return ['demo'];
    }

    public function getDependencies(): array
    {
        return [CoreDemoFixtures::class, GedDemoFixtures::class, EditorialBootstrapFixtures::class];
    }

    public function load(ObjectManager $manager): void
    {
        assert($manager instanceof EntityManagerInterface);

        $users = [];
        for ($i = 0; $i < CoreDemoFixtures::USER_COUNT; ++$i) {
            $users[] = $this->getReference(CoreDemoFixtures::userRef($i), User::class);
        }

        $media = [];
        for ($i = 0; $this->hasReference(GedDemoFixtures::mediaRef($i), Document::class); ++$i) {
            $media[] = $this->getReference(GedDemoFixtures::mediaRef($i), Document::class);
        }

        $articleType = $this->getReference(EditorialBootstrapFixtures::articleTypeRef(), PostType::class);
        $terms = $this->createTaxonomies($manager, $articleType);
        $posts = $this->createEditorial($manager, $articleType, $media, $users, $terms);
        $this->createComments($manager, $posts);
        $this->createForms($manager);
        $this->createMenuItems($manager, $media);

        $manager->flush();
    }

    private function createTaxonomies(EntityManagerInterface $em, ?PostType $postType): array
    {
        $terms = [];

        $makeTerm = static function (Taxonomy $taxonomy, string $slug, array $labels) use ($em, &$terms): TaxonomyTerm {
            $term = new TaxonomyTerm();
            $term->setTaxonomy($taxonomy);
            foreach ($labels as $locale => $name) {
                $term->translate($locale)->setName($name)->setSlug($slug);
            }

            $em->persist($term);
            $terms[$slug] = $term;

            return $term;
        };

        // ── Tag taxonomy ──────────────────────────────────────────────────────
        $tagTaxonomy = $em->getRepository(Taxonomy::class)->findOneBy(['slug' => 'tag']);
        if ($tagTaxonomy instanceof Taxonomy) {
            // Retrieve initial terms seeded by AppFixtures (nouveaute, tutoriel)
            foreach ($tagTaxonomy->getTerms() as $existing) {
                $translation = $existing->translate('fr');
                if ('' !== $translation->getSlug()) {
                    $terms[$translation->getSlug()] = $existing;
                }
            }

            foreach ([
                'symfony' => ['fr' => 'Symfony',      'en' => 'Symfony'],
                'vue-js' => ['fr' => 'Vue.js',        'en' => 'Vue.js'],
                'php' => ['fr' => 'PHP',           'en' => 'PHP'],
                'tailwind-css' => ['fr' => 'Tailwind CSS',  'en' => 'Tailwind CSS'],
                'postgresql' => ['fr' => 'PostgreSQL',    'en' => 'PostgreSQL'],
                'devops' => ['fr' => 'DevOps',        'en' => 'DevOps'],
                'open-source' => ['fr' => 'Open Source',   'en' => 'Open Source'],
            ] as $slug => $labels) {
                $makeTerm($tagTaxonomy, $slug, $labels);
            }
        }

        // ── Category taxonomy ─────────────────────────────────────────────────
        $catTaxonomy = $em->getRepository(Taxonomy::class)->findOneBy(['slug' => 'category']);
        if ($catTaxonomy instanceof Taxonomy) {
            foreach ([
                'tutoriels' => ['fr' => 'Tutoriels',       'en' => 'Tutorials'],
                'actualites' => ['fr' => 'Actualités',      'en' => 'News'],
                'etudes-de-cas' => ['fr' => 'Études de cas',   'en' => 'Case Studies'],
                'produit' => ['fr' => 'Produit',         'en' => 'Product'],
            ] as $slug => $labels) {
                $makeTerm($catTaxonomy, $slug, $labels);
            }
        }

        // ── Ressource taxonomy (new) ──────────────────────────────────────────
        $resTaxonomy = new Taxonomy();
        $resTaxonomy->setSlug('ressource')->setHierarchical(false)->setIsBuiltIn(false);
        $resTaxonomy->translate('fr')->setLabel('Ressource');
        $resTaxonomy->translate('en')->setLabel('Resource');
        if ($postType instanceof PostType) {
            $resTaxonomy->getPostTypes()->add($postType);
        }

        $em->persist($resTaxonomy);

        foreach ([
            'documentation' => ['fr' => 'Documentation', 'en' => 'Documentation'],
            'video' => ['fr' => 'Vidéo',          'en' => 'Video'],
            'webinar' => ['fr' => 'Webinaire',      'en' => 'Webinar'],
            'template' => ['fr' => 'Template',       'en' => 'Template'],
        ] as $slug => $labels) {
            $makeTerm($resTaxonomy, $slug, $labels);
        }

        return $terms;
    }

    private function createEditorial(EntityManagerInterface $em, ?PostType $postType, array $media, array $users, array $terms = []): array
    {
        if (!$postType instanceof PostType) {
            return [];
        }

        $createdPosts = [];

        $u0 = isset($media[0]) ? $this->documentUrlGenerator->publicUrl($media[0]).'?v=0' : '';
        $u1 = isset($media[1]) ? $this->documentUrlGenerator->publicUrl($media[1]).'?v=0' : '';
        $u2 = isset($media[2]) ? $this->documentUrlGenerator->publicUrl($media[2]).'?v=0' : '';
        $u3 = isset($media[3]) ? $this->documentUrlGenerator->publicUrl($media[3]).'?v=0' : '';

        $tag = static function (Post $post, array $slugs, array $allTerms): void {
            foreach ($slugs as $slug) {
                if (isset($allTerms[$slug])) {
                    $post->addTerm($allTerms[$slug]);
                }
            }
        };

        /**
         * Bilingual posts (fr + en).
         * Each entry has: fr{title,slug,excerpt,blocks}, en{…}, media, terms[].
         */
        $bilingualDefs = [
            [
                'fr' => [
                    'title' => 'Bienvenue sur Aurora — La suite métier tout-en-un',
                    'slug' => 'bienvenue-sur-aurora',
                    'excerpt' => 'Découvrez Aurora, la plateforme qui unifie CRM, ERP, e-commerce, facturation et gestion documentaire.',
                    'blocks' => [
                        ['type' => 'header',    'data' => ['text' => 'Une plateforme pour tout gérer', 'level' => 2]],
                        ['type' => 'paragraph', 'data' => ['text' => "Aurora unifie CRM, ERP, e-commerce, facturation, GED et photographie dans un seul espace d'administration. ".self::LOREM]],
                        ['type' => 'image',     'data' => ['file' => ['url' => $u0, 'width' => 1280, 'height' => 853], 'caption' => "L'interface Aurora — tableau de bord principal", 'withBorder' => false, 'withBackground' => false, 'stretched' => true]],
                        ['type' => 'header',    'data' => ['text' => 'CRM & Gestion commerciale', 'level' => 3]],
                        ['type' => 'paragraph', 'data' => ['text' => 'Gérez vos contacts, entreprises et opportunités depuis une interface unifiée. Suivez chaque deal de la prospection à la signature. '.self::LOREM]],
                        ['type' => 'header',    'data' => ['text' => 'E-commerce intégré', 'level' => 3]],
                        ['type' => 'paragraph', 'data' => ['text' => 'Publiez votre catalogue, gérez les commandes et les paiements Stripe sans quitter votre espace admin. '.self::LOREM]],
                        ['type' => 'image',     'data' => ['file' => ['url' => $u1, 'width' => 1280, 'height' => 720], 'caption' => 'Module e-commerce Aurora — gestion des listings', 'withBorder' => false, 'withBackground' => false, 'stretched' => false]],
                        ['type' => 'header',    'data' => ['text' => 'GED & Facturation', 'level' => 3]],
                        ['type' => 'paragraph', 'data' => ['text' => self::LOREM]],
                    ],
                ],
                'en' => [
                    'title' => 'Welcome to Aurora — The All-in-One Business Suite',
                    'slug' => 'welcome-to-aurora',
                    'excerpt' => 'Discover Aurora, the platform that unifies CRM, ERP, e-commerce, billing and document management.',
                    'blocks' => [
                        ['type' => 'header',    'data' => ['text' => 'One platform to manage everything', 'level' => 2]],
                        ['type' => 'paragraph', 'data' => ['text' => self::LOREM]],
                        ['type' => 'image',     'data' => ['file' => ['url' => $u0, 'width' => 1280, 'height' => 853], 'caption' => 'Aurora admin dashboard', 'withBorder' => false, 'withBackground' => false, 'stretched' => true]],
                        ['type' => 'paragraph', 'data' => ['text' => self::LOREM]],
                    ],
                ],
                'media' => $media[0] ?? null,
                'terms' => ['actualites', 'nouveaute', 'open-source'],
                'ago' => '3 weeks',
            ],
            [
                'fr' => [
                    'title' => 'Les meilleures pratiques du développement web en 2025',
                    'slug' => 'meilleures-pratiques-developpement-web-2025',
                    'excerpt' => 'Symfony, Vue.js, Vite, Tailwind CSS — le stack moderne pour construire des applications web performantes.',
                    'blocks' => [
                        ['type' => 'header',    'data' => ['text' => 'Le stack moderne en 2025', 'level' => 2]],
                        ['type' => 'paragraph', 'data' => ['text' => "Le développement web évolue vite. En 2025, les meilleures équipes s'appuient sur des outils modernes, typés et performants. ".self::LOREM]],
                        ['type' => 'image',     'data' => ['file' => ['url' => $u1, 'width' => 1280, 'height' => 720], 'caption' => "Architecture d'une application Aurora moderne", 'withBorder' => false, 'withBackground' => false, 'stretched' => true]],
                        ['type' => 'header',    'data' => ['text' => 'Symfony 7 & PHP 8.4', 'level' => 3]],
                        ['type' => 'paragraph', 'data' => ['text' => 'Les attributs PHP 8.4, les readonly properties et les énumérations font de PHP un langage moderne et expressif. '.self::LOREM]],
                        ['type' => 'header',    'data' => ['text' => 'Vue.js 3 & Composition API', 'level' => 3]],
                        ['type' => 'paragraph', 'data' => ['text' => self::LOREM]],
                        ['type' => 'image',     'data' => ['file' => ['url' => $u2, 'width' => 800, 'height' => 1000], 'caption' => 'Développement front-end avec Vue.js 3', 'withBorder' => true, 'withBackground' => false, 'stretched' => false]],
                        ['type' => 'header',    'data' => ['text' => 'Tailwind CSS v4', 'level' => 3]],
                        ['type' => 'paragraph', 'data' => ['text' => 'Le utility-first CSS framework repensé avec une configuration CSS-native et des performances de build imbattables. '.self::LOREM]],
                    ],
                ],
                'en' => [
                    'title' => 'Web Development Best Practices in 2025',
                    'slug' => 'web-development-best-practices-2025',
                    'excerpt' => 'Symfony, Vue.js, Vite, Tailwind CSS — the modern stack for performant web applications.',
                    'blocks' => [
                        ['type' => 'header',    'data' => ['text' => 'The modern stack in 2025', 'level' => 2]],
                        ['type' => 'paragraph', 'data' => ['text' => self::LOREM]],
                        ['type' => 'image',     'data' => ['file' => ['url' => $u1, 'width' => 1280, 'height' => 720], 'caption' => 'Modern web development architecture', 'withBorder' => false, 'withBackground' => false, 'stretched' => true]],
                        ['type' => 'paragraph', 'data' => ['text' => self::LOREM]],
                    ],
                ],
                'media' => $media[1] ?? null,
                'terms' => ['tutoriels', 'symfony', 'vue-js', 'tailwind-css', 'php'],
                'ago' => '2 weeks',
            ],
            [
                'fr' => [
                    'title' => 'Comment Aurora transforme la gestion de votre entreprise',
                    'slug' => 'aurora-transforme-gestion-entreprise',
                    'excerpt' => "Retour d'expérience après 6 mois d'utilisation — témoignage d'un dirigeant de PME.",
                    'blocks' => [
                        ['type' => 'header',    'data' => ['text' => '6 mois avec Aurora : notre bilan', 'level' => 2]],
                        ['type' => 'paragraph', 'data' => ['text' => 'Avant Aurora, notre équipe jonglait entre 4 outils différents pour gérer les clients, les stocks, les commandes et la facturation. '.self::LOREM]],
                        ['type' => 'image',     'data' => ['file' => ['url' => $u3, 'width' => 1200, 'height' => 800], 'caption' => "L'équipe Aurora Tech dans leurs nouveaux locaux", 'withBorder' => false, 'withBackground' => false, 'stretched' => true]],
                        ['type' => 'header',    'data' => ['text' => 'Ce qui a changé', 'level' => 3]],
                        ['type' => 'paragraph', 'data' => ['text' => "Le premier bénéfice immédiat : la centralisation des données. Un seul endroit pour trouver l'historique d'un client, ses commandes, ses factures. ".self::LOREM]],
                        ['type' => 'image',     'data' => ['file' => ['url' => $u2, 'width' => 800, 'height' => 1000], 'caption' => 'Tableau de bord CRM Aurora — pipeline deals', 'withBorder' => false, 'withBackground' => false, 'stretched' => false]],
                        ['type' => 'header',    'data' => ['text' => 'Le module GED', 'level' => 3]],
                        ['type' => 'paragraph', 'data' => ['text' => 'Tous nos contrats, guides techniques et supports marketing sont maintenant centralisés. La recherche par catégorie nous fait gagner un temps précieux. '.self::LOREM]],
                    ],
                ],
                'en' => [
                    'title' => 'How Aurora Transforms Your Business Management',
                    'slug' => 'aurora-transforms-business-management',
                    'excerpt' => 'A 6-month experience report — testimonial from an SME founder.',
                    'blocks' => [
                        ['type' => 'header',    'data' => ['text' => '6 months with Aurora: our review', 'level' => 2]],
                        ['type' => 'paragraph', 'data' => ['text' => self::LOREM]],
                        ['type' => 'image',     'data' => ['file' => ['url' => $u3, 'width' => 1200, 'height' => 800], 'caption' => 'Aurora Tech team at the office', 'withBorder' => false, 'withBackground' => false, 'stretched' => true]],
                        ['type' => 'paragraph', 'data' => ['text' => self::LOREM]],
                    ],
                ],
                'media' => $media[2] ?? null,
                'terms' => ['etudes-de-cas'],
                'ago' => '1 week',
            ],
        ];

        foreach ($bilingualDefs as $def) {
            $post = new Post();
            $post->setPostType($postType)
                 ->setStatus(PostStatusEnum::Published)
                 ->setPublishedAt(new DateTimeImmutable('-'.$def['ago']))
                 ->setFeaturedMedia($def['media'] ?? null);
            $tag($post, $def['terms'], $terms);

            foreach (['fr', 'en'] as $locale) {
                $loc = $def[$locale];
                $tr = new PostTranslation();
                $tr->setPost($post)->setLocale($locale)
                   ->setTitle($loc['title'])->setSlug($loc['slug'])
                   ->setBlocks($loc['blocks'])
                   ->setSearchContent($this->blocksText($loc['blocks']));
                if ('fr' === $locale) {
                    $tr->setMetaDescription($loc['excerpt']);
                }

                if ('fr' === $locale && null !== $def['media']) {
                    $tr->setOgImage($def['media']);
                }

                $em->persist($tr);
            }

            $em->persist($post);
            $createdPosts[] = $post;
        }

        // French-only posts — richer variety to showcase taxonomy filtering
        $img0 = isset($media[0]) ? $this->documentUrlGenerator->publicUrl($media[0]) : '';
        $img1 = isset($media[1]) ? $this->documentUrlGenerator->publicUrl($media[1]) : '';
        $img2 = isset($media[2]) ? $this->documentUrlGenerator->publicUrl($media[2]) : '';
        $img3 = isset($media[3]) ? $this->documentUrlGenerator->publicUrl($media[3]) : '';

        $frDefs = [
            [
                'title' => 'Retour sur Aurora Tech Day 2025',
                'slug' => 'aurora-tech-day-2025',
                'media' => $media[3] ?? null,
                'ago' => '3 days',
                'terms' => ['actualites', 'open-source'],
                'blocks' => [
                    ['type' => 'header',    'data' => ['text' => "Une journée dédiée à l'innovation", 'level' => 2]],
                    ['type' => 'paragraph', 'data' => ['text' => 'Plus de 200 développeurs et dirigeants réunis pour découvrir les nouveautés Aurora. '.self::LOREM]],
                    ['type' => 'image',     'data' => ['file' => ['url' => $img3, 'width' => 1200, 'height' => 800], 'caption' => 'Aurora Tech Day 2025 — Grande salle des conférences', 'withBorder' => false, 'withBackground' => false, 'stretched' => true]],
                    ['type' => 'header',    'data' => ['text' => 'Les annonces phares', 'level' => 3]],
                    ['type' => 'paragraph', 'data' => ['text' => self::LOREM]],
                    ['type' => 'image',     'data' => ['file' => ['url' => $img0, 'width' => 1280, 'height' => 853], 'caption' => 'Démonstration en direct du module GED', 'withBorder' => false, 'withBackground' => false, 'stretched' => false]],
                    ['type' => 'paragraph', 'data' => ['text' => self::LOREM]],
                ],
            ],
            [
                'title' => 'Roadmap Aurora 2025-2026 : les grandes orientations',
                'slug' => 'roadmap-aurora-2025-2026',
                'media' => $media[0] ?? null,
                'ago' => '10 days',
                'terms' => ['produit', 'actualites', 'nouveaute'],
                'blocks' => [
                    ['type' => 'header',    'data' => ['text' => 'Notre vision pour les 18 prochains mois', 'level' => 2]],
                    ['type' => 'paragraph', 'data' => ['text' => "Nous avons écouté vos retours. Voici les priorités qui guideront le développement d'Aurora jusqu'en 2026. ".self::LOREM]],
                    ['type' => 'image',     'data' => ['file' => ['url' => $img0, 'width' => 1280, 'height' => 853], 'caption' => 'Feuille de route Aurora 2025-2026', 'withBorder' => false, 'withBackground' => true, 'stretched' => false]],
                    ['type' => 'header',    'data' => ['text' => 'Module Suivi & Workflow', 'level' => 3]],
                    ['type' => 'paragraph', 'data' => ['text' => self::LOREM]],
                    ['type' => 'header',    'data' => ['text' => 'Intelligence artificielle intégrée', 'level' => 3]],
                    ['type' => 'paragraph', 'data' => ['text' => self::LOREM]],
                ],
            ],
            [
                'title' => 'Tutoriel : créer votre premier module client Aurora',
                'slug' => 'tutoriel-premier-module-client',
                'media' => $media[1] ?? null,
                'ago' => '5 days',
                'terms' => ['tutoriels', 'symfony', 'php', 'documentation'],
                'blocks' => [
                    ['type' => 'header',    'data' => ['text' => 'Prérequis', 'level' => 2]],
                    ['type' => 'paragraph', 'data' => ['text' => 'Aurora est installé, vous avez un projet client. Maintenant, créons un module sur-mesure. '.self::LOREM]],
                    ['type' => 'image',     'data' => ['file' => ['url' => $img1, 'width' => 1280, 'height' => 720], 'caption' => "Structure d'un module Aurora", 'withBorder' => true, 'withBackground' => false, 'stretched' => false]],
                    ['type' => 'header',    'data' => ['text' => "Étape 1 : Créer l'entité", 'level' => 3]],
                    ['type' => 'paragraph', 'data' => ['text' => self::LOREM]],
                    ['type' => 'header',    'data' => ['text' => 'Étape 2 : Le composant Vue', 'level' => 3]],
                    ['type' => 'paragraph', 'data' => ['text' => self::LOREM]],
                    ['type' => 'image',     'data' => ['file' => ['url' => $img2, 'width' => 800, 'height' => 1000], 'caption' => "Le résultat final dans l'admin", 'withBorder' => false, 'withBackground' => false, 'stretched' => false]],
                ],
            ],
            [
                'title' => "Aurora & l'IA : automatisez vos processus métier",
                'slug' => 'aurora-ia-automatisation-processus',
                'media' => $media[2] ?? null,
                'ago' => '2 days',
                'terms' => ['produit', 'nouveaute', 'php'],
                'blocks' => [
                    ['type' => 'header',    'data' => ['text' => "L'IA au service de la productivité", 'level' => 2]],
                    ['type' => 'paragraph', 'data' => ['text' => self::LOREM]],
                    ['type' => 'image',     'data' => ['file' => ['url' => $img2, 'width' => 800, 'height' => 1000], 'caption' => 'Interface Aurora avec suggestions IA', 'withBorder' => false, 'withBackground' => false, 'stretched' => false]],
                    ['type' => 'header',    'data' => ['text' => 'OCR et extraction de données', 'level' => 3]],
                    ['type' => 'paragraph', 'data' => ['text' => self::LOREM]],
                ],
            ],
            [
                'title' => 'Guide : Sécuriser Aurora en production',
                'slug' => 'guide-securiser-aurora-production',
                'media' => $media[0] ?? null,
                'ago' => '15 days',
                'terms' => ['tutoriels', 'devops', 'php', 'documentation'],
                'blocks' => [
                    ['type' => 'header',    'data' => ['text' => 'Checklist sécurité production', 'level' => 2]],
                    ['type' => 'paragraph', 'data' => ['text' => self::LOREM]],
                    ['type' => 'image',     'data' => ['file' => ['url' => $img0, 'width' => 1280, 'height' => 853], 'caption' => 'Dashboard monitoring Aurora', 'withBorder' => false, 'withBackground' => false, 'stretched' => true]],
                    ['type' => 'paragraph', 'data' => ['text' => self::LOREM]],
                ],
            ],
            // Additional posts to make taxonomy filtering compelling
            [
                'title' => 'Vue.js 3 Composition API : guide complet pour débutants',
                'slug' => 'vuejs-3-composition-api-guide',
                'media' => $media[2] ?? null,
                'ago' => '6 days',
                'terms' => ['tutoriels', 'vue-js', 'tailwind-css', 'documentation'],
                'blocks' => [
                    ['type' => 'header',    'data' => ['text' => 'Pourquoi la Composition API ?', 'level' => 2]],
                    ['type' => 'paragraph', 'data' => ['text' => "Vue.js 3 introduit la Composition API comme alternative plus flexible et testable à l'Options API. ".self::LOREM]],
                    ['type' => 'image',     'data' => ['file' => ['url' => $img2, 'width' => 800, 'height' => 1000], 'caption' => 'Exemple de composable Vue.js 3', 'withBorder' => true, 'withBackground' => false, 'stretched' => false]],
                    ['type' => 'header',    'data' => ['text' => 'ref() et reactive() : les bases', 'level' => 3]],
                    ['type' => 'paragraph', 'data' => ['text' => self::LOREM]],
                    ['type' => 'header',    'data' => ['text' => 'Créer un composable réutilisable', 'level' => 3]],
                    ['type' => 'paragraph', 'data' => ['text' => self::LOREM]],
                ],
            ],
            [
                'title' => 'PostgreSQL pour les développeurs PHP : optimisation avancée',
                'slug' => 'postgresql-developpeurs-php-optimisation',
                'media' => $media[1] ?? null,
                'ago' => '20 days',
                'terms' => ['tutoriels', 'postgresql', 'php', 'documentation'],
                'blocks' => [
                    ['type' => 'header',    'data' => ['text' => 'Index, requêtes et performances', 'level' => 2]],
                    ['type' => 'paragraph', 'data' => ['text' => 'PostgreSQL offre des fonctionnalités avancées qui font toute la différence en production. '.self::LOREM]],
                    ['type' => 'image',     'data' => ['file' => ['url' => $img1, 'width' => 1280, 'height' => 720], 'caption' => 'Explain analyze sur une requête complexe', 'withBorder' => true, 'withBackground' => false, 'stretched' => false]],
                    ['type' => 'header',    'data' => ['text' => 'JSONB et recherche full-text', 'level' => 3]],
                    ['type' => 'paragraph', 'data' => ['text' => self::LOREM]],
                    ['type' => 'header',    'data' => ['text' => 'Migrations sans downtime', 'level' => 3]],
                    ['type' => 'paragraph', 'data' => ['text' => self::LOREM]],
                ],
            ],
            [
                'title' => 'Déploiement Aurora avec Docker & CI/CD GitHub Actions',
                'slug' => 'deploiement-aurora-docker-cicd',
                'media' => $media[0] ?? null,
                'ago' => '12 days',
                'terms' => ['tutoriels', 'devops', 'open-source', 'documentation'],
                'blocks' => [
                    ['type' => 'header',    'data' => ['text' => 'Infrastructure as Code pour Aurora', 'level' => 2]],
                    ['type' => 'paragraph', 'data' => ['text' => self::LOREM]],
                    ['type' => 'image',     'data' => ['file' => ['url' => $img0, 'width' => 1280, 'height' => 853], 'caption' => 'Pipeline CI/CD Aurora sur GitHub Actions', 'withBorder' => false, 'withBackground' => false, 'stretched' => true]],
                    ['type' => 'header',    'data' => ['text' => 'Docker Compose en production', 'level' => 3]],
                    ['type' => 'paragraph', 'data' => ['text' => self::LOREM]],
                    ['type' => 'header',    'data' => ['text' => "Rollback automatique en cas d'erreur", 'level' => 3]],
                    ['type' => 'paragraph', 'data' => ['text' => self::LOREM]],
                ],
            ],
            [
                'title' => 'Étude de cas : BioMed France migre vers Aurora',
                'slug' => 'etude-de-cas-biomed-france-aurora',
                'media' => $media[3] ?? null,
                'ago' => '18 days',
                'terms' => ['etudes-de-cas', 'postgresql', 'symfony'],
                'blocks' => [
                    ['type' => 'header',    'data' => ['text' => 'Contexte : une PME de santé face à ses outils', 'level' => 2]],
                    ['type' => 'paragraph', 'data' => ['text' => 'BioMed France gère 45 collaborateurs, un catalogue de 800 références et des dizaines de clients grands comptes. '.self::LOREM]],
                    ['type' => 'image',     'data' => ['file' => ['url' => $img3, 'width' => 1200, 'height' => 800], 'caption' => 'Locaux BioMed France à Marseille', 'withBorder' => false, 'withBackground' => false, 'stretched' => true]],
                    ['type' => 'header',    'data' => ['text' => 'Résultats après 8 mois', 'level' => 3]],
                    ['type' => 'paragraph', 'data' => ['text' => self::LOREM]],
                ],
            ],
            [
                'title' => 'Webinar Aurora — démo ERP + Facturation en direct',
                'slug' => 'webinar-aurora-demo-erp-facturation',
                'media' => $media[1] ?? null,
                'ago' => '1 day',
                'terms' => ['actualites', 'webinar', 'nouveaute'],
                'blocks' => [
                    ['type' => 'header',    'data' => ['text' => 'Rejoignez-nous pour 90 minutes de démo live', 'level' => 2]],
                    ['type' => 'paragraph', 'data' => ['text' => self::LOREM]],
                    ['type' => 'image',     'data' => ['file' => ['url' => $img1, 'width' => 1280, 'height' => 720], 'caption' => 'Capture écran du webinar précédent', 'withBorder' => false, 'withBackground' => false, 'stretched' => false]],
                    ['type' => 'header',    'data' => ['text' => 'Au programme', 'level' => 3]],
                    ['type' => 'paragraph', 'data' => ['text' => self::LOREM]],
                ],
            ],
            [
                'title' => 'Template : cahier des charges pour un projet Aurora',
                'slug' => 'template-cahier-des-charges-aurora',
                'media' => $media[2] ?? null,
                'ago' => '25 days',
                'terms' => ['produit', 'template', 'documentation'],
                'blocks' => [
                    ['type' => 'header',    'data' => ['text' => 'Un point de départ pour vos projets', 'level' => 2]],
                    ['type' => 'paragraph', 'data' => ['text' => 'Ce template couvre les sections clés : périmètre fonctionnel, intégrations, hébergement, planning. '.self::LOREM]],
                    ['type' => 'image',     'data' => ['file' => ['url' => $img2, 'width' => 800, 'height' => 1000], 'caption' => 'Extrait du template de cahier des charges', 'withBorder' => true, 'withBackground' => false, 'stretched' => false]],
                    ['type' => 'paragraph', 'data' => ['text' => self::LOREM]],
                ],
            ],
            [
                'title' => 'Sécuriser une API Symfony avec JWT et API Platform',
                'slug' => 'securiser-api-symfony-jwt',
                'media' => $media[0] ?? null,
                'ago' => '8 days',
                'terms' => ['tutoriels', 'symfony', 'php', 'devops'],
                'blocks' => [
                    ['type' => 'header',    'data' => ['text' => 'Authentification stateless avec JWT', 'level' => 2]],
                    ['type' => 'paragraph', 'data' => ['text' => self::LOREM]],
                    ['type' => 'image',     'data' => ['file' => ['url' => $img0, 'width' => 1280, 'height' => 853], 'caption' => 'Diagramme flux JWT + Refresh Token', 'withBorder' => false, 'withBackground' => false, 'stretched' => true]],
                    ['type' => 'header',    'data' => ['text' => 'Intégration avec Aurora', 'level' => 3]],
                    ['type' => 'paragraph', 'data' => ['text' => self::LOREM]],
                ],
            ],
            [
                'title' => 'Aurora est désormais open source — rejoignez la communauté',
                'slug' => 'aurora-open-source-communaute',
                'media' => $media[3] ?? null,
                'ago' => '4 days',
                'terms' => ['actualites', 'open-source', 'nouveaute'],
                'blocks' => [
                    ['type' => 'header',    'data' => ['text' => 'Une décision qui change tout', 'level' => 2]],
                    ['type' => 'paragraph', 'data' => ['text' => 'Après deux ans de développement interne, Aurora passe en open source sous licence MIT. '.self::LOREM]],
                    ['type' => 'image',     'data' => ['file' => ['url' => $img3, 'width' => 1200, 'height' => 800], 'caption' => "L'équipe Aurora célèbre le passage en open source", 'withBorder' => false, 'withBackground' => false, 'stretched' => true]],
                    ['type' => 'header',    'data' => ['text' => 'Comment contribuer', 'level' => 3]],
                    ['type' => 'paragraph', 'data' => ['text' => self::LOREM]],
                ],
            ],
        ];

        foreach ($frDefs as $def) {
            $post = new Post();
            $post->setPostType($postType)
                 ->setStatus(PostStatusEnum::Published)
                 ->setPublishedAt(new DateTimeImmutable('-'.$def['ago']))
                 ->setFeaturedMedia($def['media'] ?? null);
            $tag($post, $def['terms'], $terms);

            $tr = new PostTranslation();
            $tr->setPost($post)->setLocale('fr')
               ->setTitle($def['title'])->setSlug($def['slug'])
               ->setBlocks($def['blocks'])
               ->setSearchContent($this->blocksText($def['blocks']));
            if (null !== $def['media']) {
                $tr->setOgImage($def['media']);
            }

            $em->persist($tr);
            $em->persist($post);
            $createdPosts[] = $post;
        }

        return $createdPosts;
    }

    private function blocksText(array $blocks): string
    {
        $parts = [];
        foreach ($blocks as $b) {
            if (isset($b['data']['text']) && is_string($b['data']['text'])) {
                $parts[] = $b['data']['text'];
            }
        }

        return implode(' ', $parts);
    }

    private function createComments(EntityManagerInterface $em, array $posts): void
    {
        $commentData = [
            ['name' => 'Pierre Dupont',    'email' => 'pierre.dupont@example.com', 'status' => CommentStatusEnum::Approved, 'text' => 'Article très intéressant ! J\'ai particulièrement apprécié la partie sur les meilleures pratiques. Merci pour ce partage.'],
            ['name' => 'Camille Martin',   'email' => 'camille.martin@example.com', 'status' => CommentStatusEnum::Approved, 'text' => 'Exactement ce que je cherchais. On utilise Aurora depuis 3 mois et les résultats sont au rendez-vous.'],
            ['name' => 'François Petit',   'email' => 'f.petit@company.fr',        'status' => CommentStatusEnum::Approved, 'text' => "Super contenu ! Question : est-ce qu'Aurora supporte le multi-tenant ? Merci d'avance."],
            ['name' => 'Alice Bernard',    'email' => 'alice@startup.io',           'status' => CommentStatusEnum::Pending,  'text' => 'Bonne introduction, mais j\'aurais aimé plus de détails sur la partie déploiement.'],
            ['name' => 'Marc Fontaine',    'email' => 'marc.fontaine@mail.com',     'status' => CommentStatusEnum::Approved, 'text' => 'Le module GED est vraiment bien pensé. On l\'a intégré à nos processus RH.'],
            ['name' => 'Sophie Leblond',   'email' => 'sophie.l@example.org',       'status' => CommentStatusEnum::Pending,  'text' => 'Avez-vous prévu un module de gestion de projet ? Ce serait un excellent complément !'],
        ];

        foreach ($posts as $postIdx => $post) {
            // Assign 2-3 comments per post, cycling through commentData
            $count = (0 === $postIdx % 2) ? 3 : 2;
            for ($i = 0; $i < $count; ++$i) {
                $cd = $commentData[($postIdx * 2 + $i) % count($commentData)];
                $c = new Comment();
                $c->setPost($post)
                  ->setAuthorName($cd['name'])
                  ->setAuthorEmail($cd['email'])
                  ->setContent($cd['text'])
                  ->setStatus($cd['status']);
                $em->persist($c);
            }
        }
    }

    private function createForms(EntityManagerInterface $em): void
    {
        /**
         * Form definitions.
         *
         * Structure:
         *   slug_fr / slug_en   : per-locale slugs
         *   fr / en             : translated title
         *   crmSync             : bool — create CRM contact on submission
         *   webhookUrl          : ?string
         *   steps               : list<{fr,en}> — multi-step labels (null = single page)
         *   fields              : list of field definitions
         *     key               : internal reference for conditions
         *     type / fr / en / ph_fr / ph_en / req / opts_fr / opts_en
         *     step              : int step index (0-based), null = no step
         *     conditions_def    : list<{fieldKey, operator, value}> — resolved after flush
         *     conditionsLogic   : 'and'|'or'
         *   submissions         : list<{labelFr => value}>
         */
        $formDefs = [
            // ── 1. Contact (with CRM sync) ─────────────────────────────────────
            [
                'slug_fr' => 'nous-contacter',
                'slug_en' => 'contact',
                'fr' => 'Nous contacter',
                'en' => 'Contact Us',
                'crmSync' => true,
                'webhookUrl' => null,
                'steps' => null,
                'fields' => [
                    ['key' => 'nom',     'type' => FormFieldTypeEnum::Text,     'fr' => 'Nom complet',   'en' => 'Full name',     'ph_fr' => 'Jean Dupont',           'ph_en' => 'John Doe',          'req' => true],
                    ['key' => 'email',   'type' => FormFieldTypeEnum::Email,    'fr' => 'Adresse email', 'en' => 'Email address', 'ph_fr' => 'jean@exemple.fr',       'ph_en' => 'john@example.com',  'req' => true],
                    ['key' => 'tel',     'type' => FormFieldTypeEnum::Tel,      'fr' => 'Téléphone',     'en' => 'Phone',         'ph_fr' => '+33 6 00 00 00 00',     'ph_en' => '+1 555 000 0000',   'req' => false],
                    ['key' => 'sujet',   'type' => FormFieldTypeEnum::Select,   'fr' => 'Sujet',         'en' => 'Subject',       'ph_fr' => 'Choisissez un sujet',   'ph_en' => 'Choose a subject',  'req' => true,
                        'opts_fr' => ['Demande commerciale', 'Support technique', 'Partenariat', 'Autre'],
                        'opts_en' => ['Sales inquiry', 'Technical support', 'Partnership', 'Other']],
                    ['key' => 'message', 'type' => FormFieldTypeEnum::Textarea, 'fr' => 'Message',       'en' => 'Message',       'ph_fr' => 'Votre message…',        'ph_en' => 'Your message…',     'req' => true],
                ],
                'submissions' => [
                    ['Nom complet' => 'Pierre Dubois',   'Adresse email' => 'pierre.dubois@tech-innovation.fr', 'Téléphone' => '+33 6 12 34 56 78', 'Sujet' => 'Demande commerciale', 'Message' => "Bonjour, nous souhaitons migrer notre outil CRM actuel vers Aurora. Pouvez-vous nous envoyer un devis pour 10 utilisateurs avec le module GED inclus ? Merci d'avance."],
                    ['Nom complet' => 'Camille Leroy',   'Adresse email' => 'c.leroy@biomed-france.com',        'Téléphone' => '+33 6 23 45 67 89', 'Sujet' => 'Support technique',   'Message' => "Depuis la mise à jour de vendredi, nos données CRM ne se synchronisent plus correctement avec l'ERP. Les stocks ne sont plus à jour côté e-commerce. Urgence niveau 2."],
                    ['Nom complet' => 'François Moreau', 'Adresse email' => 'f.moreau@retail-connect.fr',       'Téléphone' => '+33 6 34 56 78 90', 'Sujet' => 'Partenariat',         'Message' => "Nous sommes un intégrateur spécialisé en transformation digitale pour les réseaux de distribution. Aurora correspond parfaitement à nos besoins clients. Pouvons-nous discuter d'un partenariat revendeur ?"],
                    ['Nom complet' => 'Julie Chen',      'Adresse email' => 'julie.chen@tech-innovation.fr',    'Téléphone' => '',                  'Sujet' => 'Demande commerciale', 'Message' => 'Suite à notre démo de la semaine dernière, mon équipe est convaincue. Nous aimerions démarrer avec la Suite Complète Aurora pour 15 utilisateurs. Quelles sont les prochaines étapes ?'],
                    ['Nom complet' => 'Isabelle Renard', 'Adresse email' => 'i.renard@nexus-digital.fr',        'Téléphone' => '+33 6 67 89 01 23', 'Sujet' => 'Autre',               'Message' => "Bonjour, je cherche à intégrer Aurora dans notre stack Vercel + Next.js via API. Disposez-vous d'une documentation sur votre API REST et les webhooks disponibles ?"],
                    ['Nom complet' => 'David Beaumont',  'Adresse email' => 'd.beaumont@leclerc-nord.fr',       'Téléphone' => '+33 6 78 90 12 34', 'Sujet' => 'Support technique',   'Message' => "Le module Billing plante lors de l'import OCR de factures PDF multi-pages. Log d'erreur joint. Merci de traiter en priorité car nous avons 200+ factures en attente de traitement."],
                ],
            ],

            // ── 2. Newsletter (CRM sync — French-localized) ────────────────────
            [
                'slug_fr' => 'inscription-newsletter',
                'slug_en' => 'newsletter',
                'fr' => 'Inscription à la newsletter',
                'en' => 'Newsletter Sign-up',
                'crmSync' => true,
                'webhookUrl' => null,
                'steps' => null,
                'fields' => [
                    ['key' => 'email',   'type' => FormFieldTypeEnum::Email,    'fr' => 'Votre email', 'en' => 'Your email',      'ph_fr' => 'vous@exemple.fr', 'ph_en' => 'you@example.com', 'req' => true],
                    ['key' => 'prenom',  'type' => FormFieldTypeEnum::Text,     'fr' => 'Prénom',      'en' => 'First name',      'ph_fr' => 'Prénom',          'ph_en' => 'First name',      'req' => false],
                    ['key' => 'consent', 'type' => FormFieldTypeEnum::Checkbox, 'fr' => "J'accepte de recevoir les communications Aurora", 'en' => 'I agree to receive Aurora communications', 'ph_fr' => '', 'ph_en' => '', 'req' => true],
                ],
                'submissions' => [
                    ['Votre email' => 'julie.martin@gmail.com',     'Prénom' => 'Julie',    "J'accepte de recevoir les communications Aurora" => '1'],
                    ['Votre email' => 'marc.fontaine@outlook.fr',   'Prénom' => 'Marc',     "J'accepte de recevoir les communications Aurora" => '1'],
                    ['Votre email' => 'sophie.b@yahoo.fr',          'Prénom' => 'Sophie',   "J'accepte de recevoir les communications Aurora" => '1'],
                    ['Votre email' => 'thomas.dev@protonmail.com',  'Prénom' => 'Thomas',   "J'accepte de recevoir les communications Aurora" => '1'],
                    ['Votre email' => 'alice.design@gmail.com',     'Prénom' => 'Alice',    "J'accepte de recevoir les communications Aurora" => '1'],
                    ['Votre email' => 'hugo.cto@startupfactory.fr', 'Prénom' => 'Hugo',     "J'accepte de recevoir les communications Aurora" => '1'],
                    ['Votre email' => 'nathalie.rh@clinique-sj.fr', 'Prénom' => 'Nathalie', "J'accepte de recevoir les communications Aurora" => '1'],
                    ['Votre email' => 'pierre.pdg@ecobuilding.fr',  'Prénom' => 'Pierre',   "J'accepte de recevoir les communications Aurora" => '1'],
                ],
            ],

            // ── 3. Demande de devis — multi-step ──────────────────────────────
            [
                'slug_fr' => 'demande-de-devis',
                'slug_en' => 'request-quote',
                'fr' => 'Demande de devis',
                'en' => 'Request a Quote',
                'crmSync' => true,
                'webhookUrl' => null,
                'steps' => [
                    ['fr' => 'Vos coordonnées', 'en' => 'Your details'],
                    ['fr' => 'Votre projet',     'en' => 'Your project'],
                ],
                'fields' => [
                    ['key' => 'nom',      'type' => FormFieldTypeEnum::Text,     'fr' => 'Nom complet',            'en' => 'Full name',           'ph_fr' => 'Jean Dupont',          'ph_en' => 'John Doe',         'req' => true,  'step' => 0],
                    ['key' => 'email',    'type' => FormFieldTypeEnum::Email,    'fr' => 'Adresse email',          'en' => 'Email address',       'ph_fr' => 'jean@exemple.fr',      'ph_en' => 'john@example.com', 'req' => true,  'step' => 0],
                    ['key' => 'tel',      'type' => FormFieldTypeEnum::Tel,      'fr' => 'Téléphone',              'en' => 'Phone',               'ph_fr' => '+33 6 00 00 00 00',    'ph_en' => '+1 555 000 0000',  'req' => false, 'step' => 0],
                    ['key' => 'service',  'type' => FormFieldTypeEnum::Select,   'fr' => 'Type de prestation',     'en' => 'Service type',        'ph_fr' => '',                     'ph_en' => '',                 'req' => true,  'step' => 1,
                        'opts_fr' => ['Développement web', 'Conseil & architecture', 'Formation', 'Intégration Aurora', 'Autre'],
                        'opts_en' => ['Web development', 'Consulting & architecture', 'Training', 'Aurora integration', 'Other']],
                    ['key' => 'budget',   'type' => FormFieldTypeEnum::Select,   'fr' => 'Budget estimé',          'en' => 'Estimated budget',    'ph_fr' => '',                     'ph_en' => '',                 'req' => false, 'step' => 1,
                        'opts_fr' => ['< 5 000 €', '5 000 – 15 000 €', '15 000 – 50 000 €', '> 50 000 €'],
                        'opts_en' => ['< €5,000', '€5,000 – €15,000', '€15,000 – €50,000', '> €50,000']],
                    ['key' => 'projet',   'type' => FormFieldTypeEnum::Textarea, 'fr' => 'Décrivez votre projet',  'en' => 'Describe your project', 'ph_fr' => 'Contexte, objectifs, contraintes…', 'ph_en' => 'Context, goals, constraints…', 'req' => true, 'step' => 1],
                ],
                'submissions' => [
                    ['Nom complet' => 'Antoine Garnier', 'Adresse email' => 'a.garnier@fintech-horizons.fr', 'Téléphone' => '+33 6 90 12 34 56', 'Type de prestation' => 'Intégration Aurora', 'Budget estimé' => '15 000 – 50 000 €', 'Décrivez votre projet' => "Nous souhaitons intégrer Aurora dans notre système de gestion de portefeuille clients. Besoin d'une interface personnalisée pour nos conseillers financiers."],
                    ['Nom complet' => 'Laure Michaud',   'Adresse email' => 'l.michaud@ecobuilding.fr',      'Téléphone' => '+33 6 01 23 45 67', 'Type de prestation' => 'Développement web',  'Budget estimé' => '5 000 – 15 000 €',  'Décrivez votre projet' => "Refonte complète de notre site corporate avec intégration Aurora pour la gestion des appels d'offres et documents contractuels."],
                    ['Nom complet' => 'Emma Rousseau',   'Adresse email' => 'e.rousseau@startupfactory.fr',  'Téléphone' => '',                  'Type de prestation' => 'Formation',          'Budget estimé' => '< 5 000 €',         'Décrivez votre projet' => "Formation d'une équipe de 8 personnes sur Aurora — modules CRM, GED et facturation. Idéalement en présentiel sur Paris."],
                ],
            ],

            // ── 4. Satisfaction — conditional fields ──────────────────────────
            [
                'slug_fr' => 'satisfaction',
                'slug_en' => 'satisfaction',
                'fr' => 'Enquête de satisfaction',
                'en' => 'Satisfaction Survey',
                'crmSync' => false,
                'webhookUrl' => null,
                'steps' => null,
                'fields' => [
                    ['key' => 'recommande', 'type' => FormFieldTypeEnum::Radio,    'fr' => 'Recommanderiez-vous Aurora à un collègue ?', 'en' => 'Would you recommend Aurora to a colleague?', 'ph_fr' => '', 'ph_en' => '', 'req' => true,
                        'opts_fr' => ['Oui, sans hésitation', 'Probablement', 'Non'],
                        'opts_en' => ['Yes, absolutely', 'Probably', 'No']],
                    // Shown only when "Non" is selected — conditions_def resolved after flush
                    ['key' => 'pourquoi_non', 'type' => FormFieldTypeEnum::Textarea, 'fr' => 'Pourquoi pas ?', 'en' => 'Why not?', 'ph_fr' => "Qu'est-ce qui vous a déçu ?", 'ph_en' => 'What disappointed you?', 'req' => false,
                        'conditions_def' => [['fieldKey' => 'recommande', 'operator' => 'eq', 'value' => 'Non']],
                        'conditionsLogic' => 'and'],
                    ['key' => 'source', 'type' => FormFieldTypeEnum::Select,   'fr' => 'Comment nous avez-vous trouvé ?', 'en' => 'How did you find us?', 'ph_fr' => '', 'ph_en' => '', 'req' => false,
                        'opts_fr' => ['Bouche-à-oreille', 'Moteur de recherche', 'Réseaux sociaux', 'Conférence / événement', 'Autre'],
                        'opts_en' => ['Word of mouth', 'Search engine', 'Social media', 'Conference / event', 'Other']],
                    // Shown only when "Autre" is selected
                    ['key' => 'source_autre', 'type' => FormFieldTypeEnum::Text, 'fr' => 'Précisez la source', 'en' => 'Please specify the source', 'ph_fr' => 'ex : podcast, presse…', 'ph_en' => 'e.g. podcast, press…', 'req' => false,
                        'conditions_def' => [['fieldKey' => 'source', 'operator' => 'eq', 'value' => 'Autre']],
                        'conditionsLogic' => 'and'],
                    ['key' => 'commentaire', 'type' => FormFieldTypeEnum::Textarea, 'fr' => 'Commentaire libre', 'en' => 'Additional comments', 'ph_fr' => 'Vos suggestions, remarques…', 'ph_en' => 'Your suggestions, remarks…', 'req' => false],
                ],
                'submissions' => [
                    ['Recommanderiez-vous Aurora à un collègue ?' => 'Oui, sans hésitation', 'Comment nous avez-vous trouvé ?' => 'Bouche-à-oreille',           'Commentaire libre' => 'Excellent outil, très bien intégré. Le module CRM est particulièrement efficace.'],
                    ['Recommanderiez-vous Aurora à un collègue ?' => 'Probablement',          'Comment nous avez-vous trouvé ?' => 'Moteur de recherche',          'Commentaire libre' => "L'interface est intuitive mais quelques options avancées mériteraient plus de documentation."],
                    ['Recommanderiez-vous Aurora à un collègue ?' => 'Non',                   'Pourquoi pas ?' => 'Le module de facturation manque encore de fonctionnalités pour notre secteur (BTP). On attend avec impatience les prochaines mises à jour.', 'Comment nous avez-vous trouvé ?' => 'Conférence / événement', 'Commentaire libre' => ''],
                    ['Recommanderiez-vous Aurora à un collègue ?' => 'Oui, sans hésitation', 'Comment nous avez-vous trouvé ?' => 'Autre', 'Précisez la source' => 'Article dans le magazine Développez.com', 'Commentaire libre' => 'Super découverte !'],
                    ['Recommanderiez-vous Aurora à un collègue ?' => 'Probablement',          'Comment nous avez-vous trouvé ?' => 'Réseaux sociaux',              'Commentaire libre' => "Bon produit dans l'ensemble. La courbe d'apprentissage est un peu longue au démarrage."],
                ],
            ],
        ];

        foreach ($formDefs as $fd) {
            $form = new Form();
            $form->setCrmSync($fd['crmSync'] ?? false);
            $form->setWebhookUrl($fd['webhookUrl'] ?? null);
            $form->setSteps($fd['steps'] ?? null);
            $em->persist($form);

            foreach (['fr', 'en'] as $locale) {
                $ft = new FormTranslation();
                $ft->setForm($form)
                   ->setLocale($locale)
                   ->setTitle($fd[$locale])
                   ->setSlug('fr' === $locale ? $fd['slug_fr'] : $fd['slug_en']);
                $em->persist($ft);
            }

            // Build fields — keep two maps: labelFr → field, key → field
            $fieldsByLabel = [];
            $fieldsByKey = [];
            foreach ($fd['fields'] as $pos => $fieldDef) {
                $field = new FormField();
                $field->setForm($form)
                      ->setType($fieldDef['type'])
                      ->setRequired($fieldDef['req'])
                      ->setPosition($pos)
                      ->setStep($fieldDef['step'] ?? null)
                      ->setConditionsLogic($fieldDef['conditionsLogic'] ?? 'and');
                $em->persist($field);

                foreach (['fr', 'en'] as $locale) {
                    $fft = new FormFieldTranslation();
                    $fft->setField($field)->setLocale($locale)
                        ->setLabel('fr' === $locale ? $fieldDef['fr'] : $fieldDef['en'])
                        ->setPlaceholder('fr' === $locale ? ($fieldDef['ph_fr'] ?: null) : ($fieldDef['ph_en'] ?: null));
                    if (isset($fieldDef['opts_fr']) && 'fr' === $locale) {
                        $fft->setOptions($fieldDef['opts_fr']);
                    }

                    if (isset($fieldDef['opts_en']) && 'en' === $locale) {
                        $fft->setOptions($fieldDef['opts_en']);
                    }

                    $em->persist($fft);
                }

                $fieldsByLabel[$fieldDef['fr']] = $field;
                $fieldsByKey[$fieldDef['key']] = $field;
            }

            // Flush to get field IDs — needed for conditions AND submissions
            $em->flush();

            // Resolve conditions_def → real field IDs, then persist
            foreach ($fd['fields'] as $fieldDef) {
                if (empty($fieldDef['conditions_def'])) {
                    continue;
                }

                if (!isset($fieldsByKey[$fieldDef['key']])) {
                    continue;
                }

                $conditions = [];
                foreach ($fieldDef['conditions_def'] as $condDef) {
                    $targetField = $fieldsByKey[$condDef['fieldKey']] ?? null;
                    if (!$targetField instanceof FormField) {
                        continue;
                    }

                    $conditions[] = [
                        'fieldId' => $targetField->getId(),
                        'operator' => $condDef['operator'],
                        'value' => $condDef['value'],
                    ];
                }

                if ([] !== $conditions) {
                    $fieldsByKey[$fieldDef['key']]->setConditions($conditions);
                }
            }

            $em->flush();

            // Submissions
            foreach ($fd['submissions'] as $sub) {
                $data = [];
                foreach ($sub as $label => $value) {
                    if (isset($fieldsByLabel[$label])) {
                        $data[(string) $fieldsByLabel[$label]->getId()] = $value;
                    }
                }

                $fs = new FormSubmission();
                $fs->setForm($form)->setData($data)->setLocale('fr');
                $em->persist($fs);
            }
        }
    }

    private function createMenuItems(EntityManagerInterface $em, array $media): void
    {
        // ── Ensure menus exist ────────────────────────────────────────────────
        $primary = $em->getRepository(Menu::class)->findOneBy(['location' => 'primary'])
            ?? new Menu()->setName('Menu principal')->setLocation('primary');
        $em->persist($primary);

        $footer = $em->getRepository(Menu::class)->findOneBy(['location' => 'footer'])
            ?? new Menu()->setName('Menu pied de page')->setLocation('footer');
        $em->persist($footer);

        $account = $em->getRepository(Menu::class)->findOneBy(['location' => 'account'])
            ?? new Menu()->setName('Menu compte')->setLocation('account');
        $em->persist($account);

        // ── Retrieve real entities to link ────────────────────────────────────
        $pageType = $em->getRepository(PostType::class)->findOneBy(['slug' => 'page']);
        $articleType = $em->getRepository(PostType::class)->findOneBy(['slug' => 'article']);
        $contactForm = $em->getRepository(Form::class)->findOneBy([]);

        // ── Create Page posts that don't exist yet ────────────────────────────
        $pageDefs = [
            ['fr_title' => 'Notre histoire', 'fr_slug' => 'notre-histoire', 'en_title' => 'Our Story',     'en_slug' => 'our-story',
                'fr_text' => 'Aurora Tech est née en 2022 de la conviction qu\'une PME mérite les mêmes outils qu\'un grand groupe. '.self::LOREM,
                'en_text' => 'Aurora Tech was founded in 2022 with the belief that SMEs deserve the same tools as large corporations. '.self::LOREM,
                'media' => $media[0] ?? null],
            ['fr_title' => 'Solutions Aurora', 'fr_slug' => 'solutions', 'en_title' => 'Aurora Solutions', 'en_slug' => 'solutions',
                'fr_text' => 'De la gestion commerciale à la facturation, Aurora couvre l\'ensemble de vos processus métier. '.self::LOREM,
                'en_text' => 'From sales management to invoicing, Aurora covers all your business processes. '.self::LOREM,
                'media' => $media[1] ?? null],
            ['fr_title' => 'Tarifs', 'fr_slug' => 'tarifs', 'en_title' => 'Pricing', 'en_slug' => 'pricing',
                'fr_text' => 'Choisissez la formule adaptée à votre équipe. Tous nos abonnements incluent les mises à jour et le support. '.self::LOREM,
                'en_text' => 'Choose the plan that fits your team. All subscriptions include updates and support. '.self::LOREM,
                'media' => $media[2] ?? null],
            ['fr_title' => 'Ressources', 'fr_slug' => 'ressources', 'en_title' => 'Resources', 'en_slug' => 'resources',
                'fr_text' => 'Documentation, tutoriels vidéo, webinaires et modèles prêts à l\'emploi pour démarrer rapidement. '.self::LOREM,
                'en_text' => 'Documentation, video tutorials, webinars and ready-to-use templates to get started quickly. '.self::LOREM,
                'media' => $media[3] ?? null],
            ['fr_title' => 'À propos', 'fr_slug' => 'a-propos', 'en_title' => 'About Us', 'en_slug' => 'about-us',
                'fr_text' => 'Une équipe de passionnés qui construit la suite logicielle dont les PME françaises ont besoin. '.self::LOREM,
                'en_text' => 'A passionate team building the software suite that French SMEs need. '.self::LOREM,
                'media' => $media[0] ?? null],
            ['fr_title' => 'Mentions légales', 'fr_slug' => 'cgu', 'en_title' => 'Terms of Service', 'en_slug' => 'terms',
                'fr_text' => 'Conditions générales d\'utilisation de la plateforme Aurora. '.self::LOREM, 'en_text' => self::LOREM, 'media' => null],
            ['fr_title' => 'Politique de confidentialité', 'fr_slug' => 'confidentialite', 'en_title' => 'Privacy Policy', 'en_slug' => 'privacy',
                'fr_text' => 'Comment nous collectons, utilisons et protégeons vos données personnelles. '.self::LOREM, 'en_text' => self::LOREM, 'media' => null],
            ['fr_title' => 'Équipe Aurora', 'fr_slug' => 'equipe', 'en_title' => 'Our Team', 'en_slug' => 'team',
                'fr_text' => 'Rencontrez les personnes qui construisent Aurora chaque jour. '.self::LOREM, 'en_text' => self::LOREM, 'media' => $media[2] ?? null],
        ];

        /** @var array<string, Post> $pages key = fr_slug */
        $pages = [];
        foreach ($pageDefs as $pd) {
            if (!$pageType instanceof PostType) {
                break;
            }

            $page = new Post();
            $page->setPostType($pageType)->setStatus(PostStatusEnum::Published)->setFeaturedMedia($pd['media']);
            $trFr = new PostTranslation();
            $trFr->setPost($page)->setLocale('fr')->setTitle($pd['fr_title'])->setSlug($pd['fr_slug'])
                 ->setBlocks([['type' => 'paragraph', 'data' => ['text' => $pd['fr_text']]]])
                 ->setSearchContent($pd['fr_text']);
            $trEn = new PostTranslation();
            $trEn->setPost($page)->setLocale('en')->setTitle($pd['en_title'])->setSlug($pd['en_slug'])
                 ->setBlocks([['type' => 'paragraph', 'data' => ['text' => $pd['en_text']]]])
                 ->setSearchContent($pd['en_text']);
            $em->persist($trFr);
            $em->persist($trEn);
            $em->persist($page);
            $pages[$pd['fr_slug']] = $page;
        }

        $em->flush();

        // ── Helper: create a menu item ────────────────────────────────────────
        $addItem = function (
            Menu $menu,
            string $frLabel,
            string $enLabel,
            MenuItemTargetTypeEnum $type,
            int $pos,
            ?int $targetId = null,
            ?string $customUrl = null,
            ?MenuItem $parent = null,
        ) use ($em): MenuItem {
            $item = new MenuItem();
            $item->setMenu($menu)->setTargetType($type)->setPosition($pos);
            if (null !== $targetId) {
                $item->setTargetId($targetId);
            }

            if (null !== $customUrl) {
                $item->setCustomUrl($customUrl);
            }

            if ($parent instanceof MenuItem) {
                $item->setParent($parent);
            }

            $em->persist($item);
            foreach (['fr', 'en'] as $locale) {
                $tr = new MenuItemTranslation();
                $tr->setMenuItem($item)->setLocale($locale)->setLabel('fr' === $locale ? $frLabel : $enLabel);
                $em->persist($tr);
            }

            return $item;
        };

        // ── Primary navigation ────────────────────────────────────────────────
        $pos = 0;
        $addItem($primary, 'Accueil', 'Home', MenuItemTargetTypeEnum::Home, $pos++);
        if (isset($pages['notre-histoire'])) {
            $addItem($primary, 'Notre histoire', 'Our Story', MenuItemTargetTypeEnum::Post, $pos++, $pages['notre-histoire']->getId());
        }

        if (isset($pages['solutions'])) {
            $addItem($primary, 'Solutions', 'Solutions', MenuItemTargetTypeEnum::Post, $pos++, $pages['solutions']->getId());
        }

        if ($articleType instanceof PostType) {
            $addItem($primary, 'Blog', 'Blog', MenuItemTargetTypeEnum::PostTypeArchive, $pos++, $articleType->getId());
        }

        if (isset($pages['tarifs'])) {
            $addItem($primary, 'Tarifs', 'Pricing', MenuItemTargetTypeEnum::Post, $pos++, $pages['tarifs']->getId());
        }

        // Boutique → front shop (custom URL, locale-prefixed in front routing)
        $addItem($primary, 'Boutique', 'Shop', MenuItemTargetTypeEnum::CustomUrl, $pos++, null, '/fr/shop');
        if (isset($pages['ressources'])) {
            $addItem($primary, 'Ressources', 'Resources', MenuItemTargetTypeEnum::Post, $pos++, $pages['ressources']->getId());
        }

        if (isset($pages['a-propos'])) {
            $addItem($primary, 'À propos', 'About', MenuItemTargetTypeEnum::Post, $pos++, $pages['a-propos']->getId());
        }

        // Contact → link to the contact form's front page (use the form's slug via custom URL)
        if ($contactForm instanceof Form) {
            $contactFormSlug = $contactForm->getTranslation('fr')?->getSlug();
            if (null !== $contactFormSlug) {
                $addItem($primary, 'Contact', 'Contact', MenuItemTargetTypeEnum::CustomUrl, $pos++, null, '/fr/forms/'.$contactFormSlug);
            }
        }

        // ── Footer navigation (grouped sections) ─────────────────────────────
        $pos = 0;

        // Produit
        $sect = $addItem($footer, 'Produit', 'Product', MenuItemTargetTypeEnum::CustomUrl, $pos++);
        $addItem($footer, 'Fonctionnalités', 'Features', MenuItemTargetTypeEnum::Post, 0, isset($pages['solutions']) ? $pages['solutions']->getId() : null, null, $sect);
        $addItem($footer, 'Tarifs', 'Pricing', MenuItemTargetTypeEnum::Post, 1, isset($pages['tarifs']) ? $pages['tarifs']->getId() : null, null, $sect);
        $addItem($footer, 'Roadmap', 'Roadmap', MenuItemTargetTypeEnum::CustomUrl, 2, null, '/roadmap', $sect);
        $addItem($footer, 'Blog', 'Blog', $articleType instanceof PostType ? MenuItemTargetTypeEnum::PostTypeArchive : MenuItemTargetTypeEnum::CustomUrl, 3, $articleType?->getId(), null, $sect);

        // Ressources
        $sect2 = $addItem($footer, 'Ressources', 'Resources', MenuItemTargetTypeEnum::Post, $pos++, isset($pages['ressources']) ? $pages['ressources']->getId() : null);
        $addItem($footer, 'Documentation', 'Documentation', MenuItemTargetTypeEnum::CustomUrl, 0, null, '/docs', $sect2);
        $addItem($footer, 'Tutoriels', 'Tutorials', MenuItemTargetTypeEnum::CustomUrl, 1, null, '/tutoriels', $sect2);
        if ($contactForm instanceof Form) {
            $contactFormSlug = $contactForm->getTranslation('fr')?->getSlug();
            $addItem($footer, 'Formulaire contact', 'Contact form', MenuItemTargetTypeEnum::CustomUrl, 2, null, null !== $contactFormSlug ? '/fr/forms/'.$contactFormSlug : '/contact', $sect2);
        }

        // Entreprise
        $sect3 = $addItem($footer, 'Entreprise', 'Company', MenuItemTargetTypeEnum::CustomUrl, $pos++);
        $addItem($footer, 'À propos', 'About', MenuItemTargetTypeEnum::Post, 0, isset($pages['a-propos']) ? $pages['a-propos']->getId() : null, null, $sect3);
        $addItem($footer, 'Équipe', 'Team', MenuItemTargetTypeEnum::Post, 1, isset($pages['equipe']) ? $pages['equipe']->getId() : null, null, $sect3);
        $addItem($footer, 'Carrières', 'Careers', MenuItemTargetTypeEnum::CustomUrl, 2, null, '/carrieres', $sect3);

        // Légal
        $sect4 = $addItem($footer, 'Légal', 'Legal', MenuItemTargetTypeEnum::CustomUrl, $pos++);
        $addItem($footer, 'CGU', 'Terms', MenuItemTargetTypeEnum::Post, 0, isset($pages['cgu']) ? $pages['cgu']->getId() : null, null, $sect4);
        $addItem($footer, 'Confidentialité', 'Privacy', MenuItemTargetTypeEnum::Post, 1, isset($pages['confidentialite']) ? $pages['confidentialite']->getId() : null, null, $sect4);
        $addItem($footer, 'Cookies', 'Cookies', MenuItemTargetTypeEnum::CustomUrl, 2, null, '/cookies', $sect4);
    }
}
