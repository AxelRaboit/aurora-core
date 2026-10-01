<?php

declare(strict_types=1);

namespace Aurora\Fixtures\Studio;

use Aurora\Core\Locale\Enum\LocaleEnum;
use Aurora\Fixtures\Editorial\EditorialDemoFixtures;
use Aurora\Module\Editorial\Post\Entity\Post;
use Aurora\Module\Editorial\Post\Entity\PostInterface;
use Aurora\Module\Editorial\Post\Enum\PostStatusEnum;
use Aurora\Module\Editorial\Post\Enum\PostVisibilityEnum;
use Aurora\Module\Editorial\Post\Grid\GridNormalizer;
use Aurora\Module\Editorial\Post\Reading\Entity\PostReadingLink;
use Aurora\Module\Editorial\Post\Repository\PostTranslationRepository;
use Aurora\Module\Editorial\Post\Service\EditorBlocks;
use Aurora\Module\Editorial\Post\Service\PostTextExtractor;
use Aurora\Module\Editorial\PostType\Entity\PostTypeInterface;
use Aurora\Module\Editorial\PostType\Repository\PostTypeRepository;
use Aurora\Module\Studio\CustomerSpace\Entity\CustomerSpaceInterface;
use Aurora\Module\Studio\CustomerSpace\Repository\CustomerSpaceRepository;
use DateTimeImmutable;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Bundle\FixturesBundle\FixtureGroupInterface;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;
use RuntimeException;

/**
 * A client's documents, for the demo: an audit delivered to Atelier Dupont,
 * and a strategy template to duplicate for the next client.
 *
 * The audit is what the feature is for, shown finished: written from the
 * client's space, published, shared by link, with a reading link already
 * sent. It uses the zones an audit needs - key figures, a chart, findings, a
 * plan in steps, a summary box - so the reading page and the client's
 * "Deliverables" tab show something worth reading rather than a heading.
 *
 * The template is shared by link and left in draft, attached to no space:
 * "Duplicate" is how it is meant to be used, and a copy keeps that it is read
 * by link.
 *
 * Dev/test only, `demo` group.
 */
class SpacePublicationDemoFixtures extends Fixture implements DependentFixtureInterface, FixtureGroupInterface
{
    private const string SPACE_NAME = 'Atelier Dupont - Réseaux sociaux';

    private const string STRATEGY_SLUG = 'strategie-atelier-dupont';

    public function __construct(
        private readonly PostTypeRepository $postTypes,
        private readonly CustomerSpaceRepository $spaces,
        private readonly GridNormalizer $gridNormalizer,
        private readonly PostTextExtractor $textExtractor,
        private readonly PostTranslationRepository $translations,
    ) {}

    public static function getGroups(): array
    {
        return ['demo'];
    }

    public function getDependencies(): array
    {
        return [EditorialDemoFixtures::class, StudioDemoFixtures::class];
    }

    public function load(ObjectManager $manager): void
    {
        $page = $this->postTypes->findOneBySlug('page');
        $space = $this->spaces->findOneBy(['name' => self::SPACE_NAME]);

        if (!$page instanceof PostTypeInterface || !$space instanceof CustomerSpaceInterface) {
            throw new RuntimeException('The page type or the demo space "'.self::SPACE_NAME.'" is missing: load the Editorial and Studio demo fixtures first.');
        }

        // Run twice on an install - `make fixtures` loads every group, and
        // `make demo` loads this one again - so nothing is written twice:
        // each document is skipped when its French address is taken.
        $template = null === $this->translations->findOneBy(['locale' => 'fr', 'slug' => self::TEMPLATE['fr']['slug']])
            ? $this->template($page)
            : null;

        if ($template instanceof PostInterface) {
            $manager->persist($template);
        }

        // The space's second document: a strategy still in draft, which the
        // team sees in the space and the client does not, until it is
        // published.
        if (null === $this->translations->findOneBy(['locale' => 'fr', 'slug' => self::STRATEGY_SLUG])) {
            $strategy = $this->template(
                $page,
                self::STRATEGY_SLUG,
                'Stratégie de contenus, dernier trimestre',
                'Les trois axes et le calendrier proposés pour octobre à décembre.',
            );
            $strategy->setCustomerSpaceId((int) $space->getId())
                ->setReadingPage(['preparedFor' => $space->getCustomer()->getLegalName()]);
            $manager->persist($strategy);
        }

        if (null !== $this->translations->findOneBy(['locale' => 'fr', 'slug' => self::AUDIT['fr']['slug']])) {
            $manager->flush();

            return;
        }

        $audit = $this->audit($page, $space);
        $manager->persist($audit);
        $manager->flush();

        // After the flush: a grid names nothing by id here, but the link
        // points at the publication, which has no id before it is written.
        $link = new PostReadingLink($audit);
        $link->setLabel('Claire Dupont, le 1er octobre');

        $manager->persist($link);

        $manager->flush();
    }

    private function audit(PostTypeInterface $page, CustomerSpaceInterface $space): PostInterface
    {
        $post = new Post();
        $post->setPostType($page)
            ->setStatus(PostStatusEnum::Published)
            ->setPublishedAt(new DateTimeImmutable('-2 days'))
            ->setCommentsEnabled(false)
            ->setShareEnabled(false)
            ->setCustomerSpaceId((int) $space->getId())
            ->setReadingPage(['preparedFor' => $space->getCustomer()->getLegalName()]);

        $post->setGridLayout($this->gridNormalizer->normalizeLayout([
            'enabled' => true,
            'snap' => 4,
            'zones' => [
                ['id' => 'summary', 'type' => GridNormalizer::ZONE_TEXT, 'span' => ['base' => 48, 'md' => null, 'lg' => 48]],
                [
                    'id' => 'figures',
                    'type' => GridNormalizer::ZONE_ITEMS,
                    'span' => ['base' => 48, 'md' => null, 'lg' => 48],
                    'display' => 'stats',
                    'columns' => 4,
                    'items' => [['id' => 'f1'], ['id' => 'f2'], ['id' => 'f3'], ['id' => 'f4']],
                ],
                ['id' => 'growth', 'type' => GridNormalizer::ZONE_CHART, 'span' => ['base' => 48, 'md' => null, 'lg' => 24], 'options' => ['chartType' => 'bar', 'chartUnit' => '%']],
                ['id' => 'findings', 'type' => GridNormalizer::ZONE_TEXT, 'span' => ['base' => 48, 'md' => null, 'lg' => 24]],
                ['id' => 'planTitle', 'type' => GridNormalizer::ZONE_TEXT, 'span' => ['base' => 48, 'md' => null, 'lg' => 48]],
                [
                    'id' => 'plan',
                    'type' => GridNormalizer::ZONE_ITEMS,
                    'span' => ['base' => 48, 'md' => null, 'lg' => 48],
                    'display' => 'steps',
                    'columns' => 3,
                    'items' => [['id' => 's1'], ['id' => 's2'], ['id' => 's3']],
                ],
                ['id' => 'next', 'type' => GridNormalizer::ZONE_TEXT, 'span' => ['base' => 48, 'md' => null, 'lg' => 48]],
            ],
        ]));

        foreach (LocaleEnum::values() as $locale) {
            $text = self::AUDIT[$locale];
            $translation = $post->translate($locale);
            $translation->setTitle($text['title'])->setSlug($text['slug'])->setDescription($text['description']);

            $translation->setGrid($this->gridNormalizer->normalizeContent([
                'zones' => [
                    'summary' => ['blocks' => [
                        EditorBlocks::header($text['summaryTitle']),
                        EditorBlocks::paragraph($text['summary']),
                        EditorBlocks::callout($text['verdict'], 'info', $text['verdictTitle']),
                    ]],
                    'figures' => ['items' => [
                        'f1' => ['title' => '1 240', 'description' => $text['figures'][0]],
                        'f2' => ['title' => '2,1 %', 'description' => $text['figures'][1]],
                        'f3' => ['title' => '3', 'description' => $text['figures'][2]],
                        'f4' => ['title' => '+18 %', 'description' => $text['figures'][3]],
                    ]],
                    'growth' => ['label' => $text['chartLabel'], 'code' => $text['chart']],
                    'findings' => ['blocks' => [
                        EditorBlocks::header($text['findingsTitle']),
                        EditorBlocks::list($text['findings']),
                    ]],
                    'planTitle' => ['blocks' => [EditorBlocks::header($text['planTitle'])]],
                    'plan' => ['items' => [
                        's1' => ['title' => $text['plan'][0][0], 'description' => $text['plan'][0][1]],
                        's2' => ['title' => $text['plan'][1][0], 'description' => $text['plan'][1][1]],
                        's3' => ['title' => $text['plan'][2][0], 'description' => $text['plan'][2][1]],
                    ]],
                    'next' => ['blocks' => [
                        EditorBlocks::header($text['nextTitle']),
                        EditorBlocks::paragraph($text['next']),
                    ]],
                ],
            ], $post->getGridLayout()));

            $translation->setSearchContent($this->textExtractor->extract($translation));
        }

        return $post;
    }

    /**
     * The strategy template, or a copy of it under another address and, in
     * French, another title: what a document started from the template looks
     * like before it is filled in.
     */
    private function template(PostTypeInterface $page, ?string $slug = null, ?string $frenchTitle = null, ?string $frenchDescription = null): PostInterface
    {
        $post = new Post();
        $post->setPostType($page)
            ->setStatus(PostStatusEnum::Draft)
            ->setVisibility(PostVisibilityEnum::Link)
            ->setCommentsEnabled(false)
            ->setShareEnabled(false);

        $post->setGridLayout($this->gridNormalizer->normalizeLayout([
            'enabled' => true,
            'snap' => 4,
            'zones' => [
                ['id' => 'context', 'type' => GridNormalizer::ZONE_TEXT, 'span' => ['base' => 48, 'md' => null, 'lg' => 48]],
                [
                    'id' => 'pillars',
                    'type' => GridNormalizer::ZONE_ITEMS,
                    'span' => ['base' => 48, 'md' => null, 'lg' => 48],
                    'display' => 'steps',
                    'columns' => 3,
                    'items' => [['id' => 'p1'], ['id' => 'p2'], ['id' => 'p3']],
                ],
                ['id' => 'calendar', 'type' => GridNormalizer::ZONE_TEXT, 'span' => ['base' => 48, 'md' => null, 'lg' => 48]],
            ],
        ]));

        foreach (LocaleEnum::values() as $locale) {
            $text = self::TEMPLATE[$locale];
            $translation = $post->translate($locale);
            $translation
                ->setTitle('fr' === $locale && null !== $frenchTitle ? $frenchTitle : $text['title'])
                ->setSlug(null === $slug ? $text['slug'] : ('fr' === $locale ? $slug : $slug.'-'.$locale))
                ->setDescription('fr' === $locale && null !== $frenchDescription ? $frenchDescription : $text['description']);

            $translation->setGrid($this->gridNormalizer->normalizeContent([
                'zones' => [
                    'context' => ['blocks' => [
                        EditorBlocks::header($text['contextTitle']),
                        EditorBlocks::paragraph($text['context']),
                    ]],
                    'pillars' => ['items' => [
                        'p1' => ['title' => $text['pillars'][0], 'description' => $text['pillarHint']],
                        'p2' => ['title' => $text['pillars'][1], 'description' => $text['pillarHint']],
                        'p3' => ['title' => $text['pillars'][2], 'description' => $text['pillarHint']],
                    ]],
                    'calendar' => ['blocks' => [
                        EditorBlocks::header($text['calendarTitle']),
                        EditorBlocks::callout($text['calendar'], 'info'),
                    ]],
                ],
            ], $post->getGridLayout()));

            $translation->setSearchContent($this->textExtractor->extract($translation));
        }

        return $post;
    }

    private const array AUDIT = [
        'fr' => [
            'title' => 'Audit de présence en ligne',
            'slug' => 'audit-atelier-dupont',
            'description' => 'Instagram et Facebook, septembre 2026 : où en est Atelier Dupont, et ce que nous proposons pour les trois prochains mois.',
            'summaryTitle' => 'En résumé',
            'summary' => 'Atelier Dupont publie régulièrement et sa communauté grandit, mais les publications parlent surtout des produits et peu de l\'atelier. Les contenus qui montrent le geste et les personnes sont ceux qui engagent le plus.',
            'verdictTitle' => 'Notre recommandation',
            'verdict' => "Garder le rythme de deux publications par semaine, et en consacrer une sur deux aux coulisses de l'atelier.",
            'figures' => ['Abonnés Instagram', "Taux d'engagement moyen", 'Publications par semaine', 'Abonnés en six mois'],
            'chartLabel' => 'Engagement par type de contenu',
            'chart' => "Vidéos de l'atelier ; 6,2\nCarrousels ; 3,4\nStories ; 2,5\nPhotos de produit ; 1,9",
            'findingsTitle' => 'Ce que nous avons constaté',
            'findings' => [
                'Les vidéos de fabrication obtiennent trois fois plus de réactions que les photos de produit.',
                'Facebook touche surtout une clientèle locale, fidèle mais peu active.',
                'Aucune publication ne renvoie vers le site ni vers la prise de rendez-vous.',
            ],
            'planTitle' => 'Le plan sur trois mois',
            'plan' => [
                ['Octobre', 'Une série « Dans l\'atelier » : un geste, une personne, une minute.'],
                ['Novembre', 'Un lien vers la prise de rendez-vous dans chaque publication qui s\'y prête.'],
                ['Décembre', 'Les créations de fin d\'année, en photo et en vidéo, publiées trois semaines avant les fêtes.'],
            ],
            'nextTitle' => 'La suite',
            'next' => 'Nous faisons le point ensemble début janvier, chiffres à l\'appui. D\'ici là, chaque contenu passe par votre validation dans votre espace.',
        ],
        'en' => [
            'title' => 'Online presence audit',
            'slug' => 'audit-atelier-dupont',
            'description' => 'Instagram and Facebook, September 2026: where Atelier Dupont stands, and what we suggest for the next three months.',
            'summaryTitle' => 'In short',
            'summary' => 'Atelier Dupont posts regularly and its community is growing, but the posts are mostly about products and say little about the workshop. The posts that show the craft and the people are the ones that engage most.',
            'verdictTitle' => 'Our recommendation',
            'verdict' => 'Keep the pace of two posts a week, and give one in two to the workshop behind the scenes.',
            'figures' => ['Instagram followers', 'Average engagement rate', 'Posts a week', 'Followers in six months'],
            'chartLabel' => 'Engagement by type of post',
            'chart' => "Workshop videos ; 6.2\nCarousels ; 3.4\nStories ; 2.5\nProduct photos ; 1.9",
            'findingsTitle' => 'What we found',
            'findings' => [
                'Making-of videos get three times the reactions of product photos.',
                'Facebook mostly reaches a local, loyal but quiet audience.',
                'No post leads to the website or to booking an appointment.',
            ],
            'planTitle' => 'The plan for three months',
            'plan' => [
                ['October', 'A series "In the workshop": one gesture, one person, one minute.'],
                ['November', 'A link to booking in every post where it fits.'],
                ['December', 'The year-end pieces, in photo and video, posted three weeks before the holidays.'],
            ],
            'nextTitle' => 'Next',
            'next' => 'We review it together in early January, with the figures. Until then, every post goes through your approval in your space.',
        ],
        'es' => [
            'title' => 'Auditoría de presencia en línea',
            'slug' => 'auditoria-atelier-dupont',
            'description' => 'Instagram y Facebook, septiembre de 2026: dónde está Atelier Dupont y lo que proponemos para los próximos tres meses.',
            'summaryTitle' => 'En resumen',
            'summary' => 'Atelier Dupont publica con regularidad y su comunidad crece, pero las publicaciones hablan sobre todo de los productos y poco del taller. Los contenidos que muestran el oficio y a las personas son los que más interacción generan.',
            'verdictTitle' => 'Nuestra recomendación',
            'verdict' => 'Mantener el ritmo de dos publicaciones por semana y dedicar una de cada dos a lo que pasa en el taller.',
            'figures' => ['Seguidores en Instagram', 'Tasa media de interacción', 'Publicaciones por semana', 'Seguidores en seis meses'],
            'chartLabel' => 'Interacción por tipo de contenido',
            'chart' => "Vídeos del taller ; 6,2\nCarruseles ; 3,4\nStories ; 2,5\nFotos de producto ; 1,9",
            'findingsTitle' => 'Lo que hemos observado',
            'findings' => [
                'Los vídeos de fabricación obtienen tres veces más reacciones que las fotos de producto.',
                'Facebook llega sobre todo a una clientela local, fiel pero poco activa.',
                'Ninguna publicación lleva al sitio ni a la reserva de cita.',
            ],
            'planTitle' => 'El plan a tres meses',
            'plan' => [
                ['Octubre', 'Una serie « En el taller »: un gesto, una persona, un minuto.'],
                ['Noviembre', 'Un enlace a la reserva de cita en cada publicación que lo permita.'],
                ['Diciembre', 'Las creaciones de fin de año, en foto y vídeo, publicadas tres semanas antes de las fiestas.'],
            ],
            'nextTitle' => 'Lo siguiente',
            'next' => 'Hacemos balance juntos a principios de enero, con las cifras. Hasta entonces, cada contenido pasa por su validación en su espacio.',
        ],
    ];

    private const array TEMPLATE = [
        'fr' => [
            'title' => 'Modèle - Stratégie de contenus',
            'slug' => 'modele-strategie-de-contenus',
            'description' => 'À dupliquer pour chaque client : le contexte, trois axes et le calendrier.',
            'contextTitle' => 'Le contexte',
            'context' => 'Qui est le client, ce qu\'il vend, à qui il parle, et ce qu\'il attend de ses réseaux.',
            'pillars' => ['Premier axe', 'Deuxième axe', 'Troisième axe'],
            'pillarHint' => 'Le sujet, le format et la fréquence.',
            'calendarTitle' => 'Le calendrier',
            'calendar' => 'Un mois type : quels contenus, quels jours, sur quels réseaux.',
        ],
        'en' => [
            'title' => 'Template - Content strategy',
            'slug' => 'template-content-strategy',
            'description' => 'Duplicate it for each client: the context, three pillars and the calendar.',
            'contextTitle' => 'The context',
            'context' => 'Who the client is, what they sell, who they speak to, and what they expect from their social media.',
            'pillars' => ['First pillar', 'Second pillar', 'Third pillar'],
            'pillarHint' => 'The topic, the format and how often.',
            'calendarTitle' => 'The calendar',
            'calendar' => 'A typical month: which posts, on which days, on which networks.',
        ],
        'es' => [
            'title' => 'Plantilla - Estrategia de contenidos',
            'slug' => 'plantilla-estrategia-de-contenidos',
            'description' => 'Para duplicar con cada cliente: el contexto, tres ejes y el calendario.',
            'contextTitle' => 'El contexto',
            'context' => 'Quién es el cliente, qué vende, a quién se dirige y qué espera de sus redes.',
            'pillars' => ['Primer eje', 'Segundo eje', 'Tercer eje'],
            'pillarHint' => 'El tema, el formato y la frecuencia.',
            'calendarTitle' => 'El calendario',
            'calendar' => 'Un mes tipo: qué contenidos, qué días, en qué redes.',
        ],
    ];
}
