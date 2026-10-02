<?php

declare(strict_types=1);

namespace Aurora\Fixtures\Editorial;

use Aurora\Core\Locale\Enum\LocaleEnum;
use Aurora\Module\Editorial\Post\Entity\Post;
use Aurora\Module\Editorial\Post\Enum\PostStatusEnum;
use Aurora\Module\Editorial\Post\Enum\PostVisibilityEnum;
use Aurora\Module\Editorial\Post\Grid\GridNormalizer;
use Aurora\Module\Editorial\Post\Reading\Entity\PostReadingLink;
use Aurora\Module\Editorial\Post\Repository\PostTranslationRepository;
use Aurora\Module\Editorial\Post\Service\EditorBlocks;
use Aurora\Module\Editorial\Post\Service\PostTextExtractor;
use Aurora\Module\Editorial\PostType\Entity\PostTypeInterface;
use Aurora\Module\Editorial\PostType\Repository\PostTypeRepository;
use DateTimeImmutable;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Bundle\FixturesBundle\FixtureGroupInterface;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;
use RuntimeException;

/**
 * Une publication partagée par lien seulement, pour la démo : un dossier de
 * presse, envoyé à une rédaction par un lien de lecture.
 *
 * Les livrables des clients ont quitté les publications pour leur propre
 * module ; les liens de lecture restent une fonction des publications, pour
 * une page qu'on veut faire lire sans la mettre sur le site. Celle-ci en
 * montre un, déjà envoyé.
 *
 * Rejouable : sautée quand son adresse française est prise. Groupe `demo`.
 */
class ReadingLinkDemoFixtures extends Fixture implements DependentFixtureInterface, FixtureGroupInterface
{
    private const array TEXTS = [
        'fr' => [
            'title' => 'Dossier de presse',
            'slug' => 'dossier-de-presse',
            'description' => 'Le studio en une page, pour les rédactions : qui nous sommes, ce que nous faisons, et les chiffres de l\'année.',
            'intro' => 'Un studio indépendant qui conçoit des sites, des photos et des contenus pour les artisans et les petites entreprises de la région.',
            'figures' => ['Clients accompagnés', 'Sites livrés', 'Années d\'activité'],
            'contact' => 'Pour toute demande d\'interview ou de visuels en haute définition, écrivez-nous : nous répondons dans la journée.',
        ],
        'en' => [
            'title' => 'Press kit',
            'slug' => 'press-kit',
            'description' => "The studio on one page, for newsrooms: who we are, what we do, and this year's figures.",
            'intro' => 'An independent studio designing websites, photos and content for craftspeople and small businesses in the area.',
            'figures' => ['Clients supported', 'Websites delivered', 'Years in business'],
            'contact' => 'For an interview or high-resolution pictures, write to us: we answer the same day.',
        ],
        'es' => [
            'title' => 'Dossier de prensa',
            'slug' => 'dossier-de-prensa',
            'description' => 'El estudio en una página, para las redacciones: quiénes somos, qué hacemos y las cifras del año.',
            'intro' => 'Un estudio independiente que diseña sitios, fotos y contenidos para artesanos y pequeñas empresas de la región.',
            'figures' => ['Clientes acompañados', 'Sitios entregados', 'Años de actividad'],
            'contact' => 'Para una entrevista o imágenes en alta definición, escríbanos: respondemos en el día.',
        ],
    ];

    public function __construct(
        private readonly PostTypeRepository $postTypes,
        private readonly PostTranslationRepository $translations,
        private readonly GridNormalizer $gridNormalizer,
        private readonly PostTextExtractor $textExtractor,
    ) {}

    public static function getGroups(): array
    {
        return ['demo'];
    }

    public function getDependencies(): array
    {
        return [EditorialDemoFixtures::class];
    }

    public function load(ObjectManager $manager): void
    {
        if (null !== $this->translations->findOneBy(['locale' => 'fr', 'slug' => self::TEXTS['fr']['slug']])) {
            return;
        }

        $page = $this->postTypes->findOneBySlug('page');
        if (!$page instanceof PostTypeInterface) {
            throw new RuntimeException('The page type is missing: load the Editorial demo fixtures first.');
        }

        $post = new Post();
        $post->setPostType($page)
            ->setStatus(PostStatusEnum::Published)
            ->setPublishedAt(new DateTimeImmutable('-5 days'))
            ->setVisibility(PostVisibilityEnum::Link)
            ->setCommentsEnabled(false)
            ->setShareEnabled(false)
            ->setReadingPage(['preparedFor' => 'Lyon Mag']);

        $post->setGridLayout($this->gridNormalizer->normalizeLayout([
            'enabled' => true,
            'snap' => 4,
            'zones' => [
                ['id' => 'intro', 'type' => GridNormalizer::ZONE_TEXT, 'span' => ['base' => 48, 'md' => null, 'lg' => 48]],
                [
                    'id' => 'figures',
                    'type' => GridNormalizer::ZONE_ITEMS,
                    'span' => ['base' => 48, 'md' => null, 'lg' => 48],
                    'display' => 'stats',
                    'columns' => 3,
                    'surface' => 'card',
                    'items' => [['id' => 'f1'], ['id' => 'f2'], ['id' => 'f3']],
                ],
                ['id' => 'contact', 'type' => GridNormalizer::ZONE_TEXT, 'span' => ['base' => 48, 'md' => null, 'lg' => 48]],
            ],
        ]));

        foreach (LocaleEnum::values() as $locale) {
            $text = self::TEXTS[$locale];
            $translation = $post->translate($locale);
            $translation->setTitle($text['title'])->setSlug($text['slug'])->setDescription($text['description']);

            $translation->setGrid($this->gridNormalizer->normalizeContent([
                'zones' => [
                    'intro' => ['blocks' => [EditorBlocks::paragraph($text['intro'])]],
                    'figures' => ['items' => [
                        'f1' => ['title' => '42', 'description' => $text['figures'][0]],
                        'f2' => ['title' => '27', 'description' => $text['figures'][1]],
                        'f3' => ['title' => '6', 'description' => $text['figures'][2]],
                    ]],
                    'contact' => ['blocks' => [EditorBlocks::callout($text['contact'], 'info')]],
                ],
            ], $post->getGridLayout()));

            $translation->setSearchContent($this->textExtractor->extract($translation));
        }

        $manager->persist($post);
        $manager->flush();

        $link = new PostReadingLink($post);
        $link->setLabel('Rédaction de Lyon Mag, le 28 septembre');

        $manager->persist($link);
        $manager->flush();
    }
}
