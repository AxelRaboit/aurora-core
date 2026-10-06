<?php

declare(strict_types=1);

namespace Aurora\Fixtures\Ged;

use Aurora\Core\Storage\Enum\MimeTypeEnum;
use Aurora\Core\Storage\Service\ImageRenditionGenerator;
use Aurora\Core\Storage\Service\PdfThumbnailGenerator;
use Aurora\Core\Storage\StorageManager;
use Aurora\Fixtures\Core\AppFixtures;
use Aurora\Module\Configuration\Setting\Enum\ApplicationParameterEnum;
use Aurora\Module\Configuration\Setting\Service\SettingsService;
use Aurora\Module\Ged\Document\Dto\ColorAlternateInput;
use Aurora\Module\Ged\Document\Entity\Document;
use Aurora\Module\Ged\Document\Entity\DocumentVersion;
use Aurora\Module\Ged\Document\Service\DocumentColorAlternateCreator;
use Aurora\Module\Ged\DocumentCategory\Entity\DocumentCategoryInterface;
use Aurora\Module\Ged\DocumentFolder\Entity\DocumentFolder;
use Aurora\Module\Ged\DocumentTag\Entity\DocumentTag;
use Aurora\Module\Ged\Enum\DocumentStatusEnum;
use DateTimeImmutable;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Bundle\FixturesBundle\FixtureGroupInterface;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ObjectManager;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Demo media library (documents copied from test_files) + GED categories,
 * folders and tags. Media are exposed via {@see mediaRef} so every module that
 * needs demo media pulls them by reference. Dev/test only.
 */
class GedDemoFixtures extends Fixture implements DependentFixtureInterface, FixtureGroupInterface
{
    public static function mediaRef(int $index): string
    {
        return 'ged_demo_media_'.$index;
    }

    public function __construct(
        #[Autowire(param: 'app.upload_dir')]
        private readonly string $uploadDir,
        private readonly PdfThumbnailGenerator $pdfThumbnailGenerator,
        private readonly ImageRenditionGenerator $renditions,
        private readonly StorageManager $storageManager,
        private readonly SettingsService $settingsManager,
        private readonly DocumentColorAlternateCreator $colorAlternates,
        private readonly Filesystem $fs = new Filesystem(),
    ) {}

    public static function getGroups(): array
    {
        return ['demo'];
    }

    public function getDependencies(): array
    {
        return [AppFixtures::class];
    }

    public function load(ObjectManager $manager): void
    {
        assert($manager instanceof EntityManagerInterface);

        $media = $this->createMedia($manager);

        foreach ($media as $i => $document) {
            $this->addReference(self::mediaRef($i), $document);
        }

        $this->createGed($manager, $media);

        $manager->flush();

        $this->createVersionHistory($manager);

        $this->createColorFamily($manager);

        // Favicon + logo point at Aurora's own mark (the last media), not at
        // one of the photographs: a logo is a mark, and the demo's photographs are
        // subjects - a flag in the corner of every screen of the manual reads
        // as the product's identity, which it is not. After flush so IDs exist.
        $mark = $media[array_key_last($media)] ?? null;
        if (null !== $mark?->getId()) {
            $faviconId = (string) $mark->getId();
            $this->settingsManager->set(ApplicationParameterEnum::FaviconMediaId->value, $faviconId);
            $this->settingsManager->set(ApplicationParameterEnum::LogoMediaId->value, $faviconId);
        }

        $manager->flush();
    }

    private function createMedia(EntityManagerInterface $em): array
    {
        $month = new DateTimeImmutable()->format('Y/m');
        $destDir = $this->uploadDir.'/ged/'.$month;
        $this->fs->mkdir($destDir);

        // Two levels up, which is the repository root: `fixtures/Ged` sits
        // two deep. It said four, so it looked for `test_files/` two levels
        // above the project, found nothing, and drew a placeholder for every
        // picture - on every machine, for as long as the folder has been
        // shipped with the repository. The test next door checks that the
        // files named here exist; it resolves the root correctly, so it
        // passed while the fixture read somewhere else entirely.
        // **Royalty-free photographs (Pexels), since 28/09/2026.** The demo is
        // also used to capture the public site for the Aurora tour, and a site
        // made of flat colours does not show what a real site turns into. Each
        // photo is credited (photographer, link) as a Pexels import would have
        // done; the list is in test_files/images/pexels/CREDITS.md.
        //
        // The first four keep their long-standing title: it is the key that
        // makes `make demo` replayable. The following ones are added after the
        // video and the document, so no reference shifts.
        $sourceDir = dirname(__DIR__, 2).'/test_files';
        $defs = [
            ['src' => 'images/pexels/pexels-4348298.jpg',  'name' => 'hero-banner.jpg',   'original' => 'hero-banner.jpg',   'mime' => 'image/jpeg', 'w' => 1920, 'h' => 1280, 'pexels' => [4348298, 'Antoni Shkraba'], 'alt' => 'Un atelier lumineux, des tables de travail et des plantes'],
            ['src' => 'images/pexels/pexels-36103492.jpg', 'name' => 'landscape.jpg',     'original' => 'landscape.jpg',     'mime' => 'image/jpeg', 'w' => 1920, 'h' => 1280, 'pexels' => [36103492, 'gang liang'], 'alt' => 'Un lac de montagne sous un ciel bleu'],
            ['src' => 'images/pexels/pexels-9697663.jpg',  'name' => 'portrait-team.jpg', 'original' => 'portrait-team.jpg', 'mime' => 'image/jpeg', 'w' => 1280, 'h' => 1920, 'pexels' => [9697663, 'Daria Nekipelova'], 'alt' => 'Portrait d\'une femme dans la lumière du soleil'],
            ['src' => 'images/pexels/pexels-5668471.jpg',  'name' => 'office-setup.jpg',  'original' => 'office-setup.jpg',  'mime' => 'image/jpeg', 'w' => 1920, 'h' => 1280, 'pexels' => [5668471, 'Sora Shimazaki'], 'alt' => 'Un ordinateur portable ouvert, un carnet et un café'],
            ['src' => 'videos/sample-30s-720p.mp4',  'name' => 'demo-video.mp4',   'original' => 'demo-video.mp4',   'mime' => 'video/mp4',  'w' => 1280, 'h' => 720],
            ['src' => 'files/placeholders/document-placeholder.webp', 'name' => 'document-sample.webp', 'original' => 'document-sample.webp', 'mime' => 'image/webp', 'w' => 0, 'h' => 0],
            ['src' => 'images/pexels/pexels-11506719.jpg', 'name' => 'ville-crepuscule.jpg',   'original' => 'Une ville au crépuscule',      'mime' => 'image/jpeg', 'w' => 1920, 'h' => 1158, 'pexels' => [11506719, 'Tinny HU'], 'alt' => 'Une ville au coucher du soleil, reflétée dans le fleuve'],
            ['src' => 'images/pexels/pexels-15307795.jpg', 'name' => 'appareil-photo.jpg',     'original' => 'Un appareil photo',            'mime' => 'image/jpeg', 'w' => 1280, 'h' => 1920, 'pexels' => [15307795, 'Furkan Elveren'], 'alt' => 'Des mains qui tiennent un appareil photo argentique'],
            ['src' => 'images/pexels/pexels-16229745.jpg', 'name' => 'reseaux-sociaux.jpg',    'original' => 'Des réseaux sociaux',          'mime' => 'image/jpeg', 'w' => 1920, 'h' => 1158, 'pexels' => [16229745, 'Sanket Mishra'], 'alt' => 'Un téléphone affichant des applications de réseaux sociaux'],
            ['src' => 'images/pexels/pexels-9052803.jpg',  'name' => 'site-web.jpg',           'original' => 'Un site sur ordinateur',       'mime' => 'image/jpeg', 'w' => 1920, 'h' => 1280, 'pexels' => [9052803, 'SHVETS production'], 'alt' => 'Une personne qui parcourt un site sur un ordinateur portable'],
            ['src' => 'images/pexels/pexels-9458996.jpg',  'name' => 'facade-blanche.jpg',     'original' => 'Une façade blanche',           'mime' => 'image/jpeg', 'w' => 1920, 'h' => 1280, 'pexels' => [9458996, 'Stephen Andrews'], 'alt' => 'La façade blanche et minimaliste d\'un bâtiment moderne'],
            ['src' => 'images/pexels/pexels-16566144.jpg', 'name' => 'facade-verre.jpg',       'original' => 'Une façade de verre',          'mime' => 'image/jpeg', 'w' => 1536, 'h' => 1920, 'pexels' => [16566144, 'Mikita Yo'], 'alt' => 'Une façade de verre aux lignes courbes'],
            ['src' => 'images/pexels/pexels-39192265.jpg', 'name' => 'montagnes.jpg',          'original' => 'Des montagnes',                'mime' => 'image/jpeg', 'w' => 1920, 'h' => 1280, 'pexels' => [39192265, 'Mohd Atir'], 'alt' => 'Un lac entre des forêts de pins et des sommets enneigés'],
            ['src' => 'images/pexels/pexels-37190347.jpg', 'name' => 'cote-rocheuse.jpg',      'original' => 'Une côte rocheuse',            'mime' => 'image/jpeg', 'w' => 1920, 'h' => 1080, 'pexels' => [37190347, 'Hobi Photography'], 'alt' => 'Une côte rocheuse vue du ciel, les vagues qui se brisent'],
            ['src' => 'images/pexels/pexels-17191088.jpg', 'name' => 'plage.jpg',              'original' => 'Une plage',                    'mime' => 'image/jpeg', 'w' => 1920, 'h' => 1080, 'pexels' => [17191088, 'Field Photography'], 'alt' => 'Une plage de sable doré vue du ciel'],
            ['src' => 'images/pexels/pexels-8725071.jpg',  'name' => 'gratte-ciel.jpg',        'original' => 'Des gratte-ciel',              'mime' => 'image/jpeg', 'w' => 1920, 'h' => 1282, 'pexels' => [8725071, 'Josh Hild'], 'alt' => 'Des gratte-ciel au coucher du soleil'],
            // The demo's favicon and logo: Aurora's mark ("Lever du jour",
            // without background), not a photo. Since 04/10/2026; before that
            // it was a drawn flat colour, an unrelated purple square.
            ['src' => 'images/aurora-mark.png', 'name' => 'aurora-mark.png', 'original' => 'Marque Aurora', 'mime' => 'image/png', 'w' => 512, 'h' => 512],
        ];

        $media = [];
        foreach ($defs as $def) {
            $src = $sourceDir.'/'.$def['src'];
            $dest = $destDir.'/'.$def['name'];

            // `test_files/` sits beside the repository and is not shipped with
            // it, so on a fresh clone none of these exist. Skipping them was
            // the wrong answer twice over: the media list closed up, the
            // references it publishes shifted by one, and the editorial
            // fixtures then died on "ged_demo_media_0 does not exist" - an
            // error naming a picture, three fixtures away from the missing
            // folder that caused it.
            //
            // So a picture that has no source is drawn instead. It is plainly
            // a placeholder rather than a photograph pretending to be one, and
            // `make demo` works on any machine.
            if (!file_exists($src)) {
                $this->drawPlaceholder($dest, $def);
            } else {
                $this->fs->copy($src, $dest, true);
            }

            // Anything that cannot be drawn - the video - keeps its row and
            // loses its file, which the library already knows how to show: a
            // document with nothing attached is exactly what the upload flow
            // is tested against.
            if (!file_exists($dest)) {
                $document = $em->getRepository(Document::class)
                    ->findOneBy(['title' => $def['original']])
                    ?? new Document();

                $document->setTitle($def['original'])
                    ->setOriginalName($def['original'])
                    ->setStatus(DocumentStatusEnum::Published)
                    ->setRenditions([]);

                $em->persist($document);
                $media[] = $document;

                continue;
            }

            // Reused when the title is already taken. Without this a second
            // `make demo` inserted the whole set again - forty rows for
            // eighteen files, every publication still pointing at the first
            // copy, and a fresh set of orphans on disk each run.
            //
            // Keyed on the title rather than the path, because the path
            // carries the month it was written in: run the fixtures in
            // September against a library seeded in August and every lookup
            // missed, which is the same duplication by another route.
            $document = $em->getRepository(Document::class)
                ->findOneBy(['title' => $def['original']])
                ?? new Document();

            $document->setTitle($def['original'])
                ->setFileName($def['name'])
                ->setOriginalName($def['original'])
                ->setMimeType($def['mime'])
                ->setSize((int) filesize($dest))
                ->setFilePath('ged/'.$month.'/'.$def['name'])
                ->setStatus(DocumentStatusEnum::Published)
                // The sizes an upload through the interface would have made.
                // Left empty, every demo page served the full-size original
                // to a phone - and the one claim the library makes about
                // itself was the one thing the demo did not do.
                ->setRenditions($this->renditions->generate($this->storageManager->active(), 'ged/'.$month.'/'.$def['name'], $def['mime']));

            if ($def['w'] > 0) {
                $document->setWidth($def['w'])->setHeight($def['h']);
            }

            // Credited as a Pexels import would have done: it is what shows
            // the photographer's name under the image on the site.
            if (isset($def['pexels'])) {
                [$pexelsId, $photographer] = $def['pexels'];
                $document->setSourceUrl(sprintf('https://www.pexels.com/photo/%d/', $pexelsId))
                    ->setAttributionName($photographer)
                    ->setAttributionUrl('https://www.pexels.com')
                    ->setAlt($def['alt']);
            }

            $em->persist($document);
            $media[] = $document;
        }

        return $media;
    }

    /**
     * Draws the stand-in for a demo picture whose source file is absent.
     *
     * **A solid colour, one per file.** What matters is telling the thumbnails
     * apart, not imitating a photo: the hue is derived from the file name, so
     * it is stable from one regeneration to the next and different from one
     * neighbour to the next. A gradient did the same job while suggesting an
     * image where there is none, and a demo screenshot shows a flat colour
     * better.
     *
     * Only the image formats GD writes. A missing video or PDF is left to the
     * caller, which keeps the row and drops the file.
     *
     * @param array{name: string, mime: string, w: int, h: int} $def
     */
    private function drawPlaceholder(string $dest, array $def): void
    {
        $writers = [
            'image/jpeg' => static fn ($image, string $path): bool => imagejpeg($image, $path, 82),
            'image/png' => imagepng(...),
            'image/webp' => static fn ($image, string $path): bool => imagewebp($image, $path, 82),
        ];

        $write = $writers[$def['mime']] ?? null;
        if (null === $write || !function_exists('imagecreatetruecolor')) {
            return;
        }

        $width = $def['w'] > 0 ? $def['w'] : 1200;
        $height = $def['h'] > 0 ? $def['h'] : 800;

        $image = imagecreatetruecolor($width, $height);

        // The name decides the hue, so "portrait-team.jpg" is the same colour
        // every time it is regenerated and never the colour of its neighbour.
        $hue = crc32($def['name']) % 360;

        [$r, $g, $b] = $this->hueToRgb($hue, 0.45, 0.42);
        imagefilledrectangle($image, 0, 0, $width, $height, imagecolorallocate($image, $r, $g, $b));

        $label = imagecolorallocate($image, 255, 255, 255);
        imagestring($image, 5, 24, $height - 40, $def['name'], $label);

        $write($image, $dest);
        imagedestroy($image);
    }

    /**
     * HSL to RGB, the small part of it these placeholders need.
     *
     * @return array{int, int, int}
     */
    private function hueToRgb(int $hue, float $saturation, float $lightness): array
    {
        $c = (1 - abs(2 * $lightness - 1)) * $saturation;
        $x = $c * (1 - abs(fmod($hue / 60, 2) - 1));
        $m = $lightness - $c / 2;

        $channels = match (intdiv($hue, 60)) {
            0 => [$c, $x, 0.0],
            1 => [$x, $c, 0.0],
            2 => [0.0, $c, $x],
            3 => [0.0, $x, $c],
            4 => [$x, 0.0, $c],
            default => [$c, 0.0, $x],
        };

        return array_map(
            static fn (float $channel): int => (int) round(255 * ($channel + $m)),
            $channels,
        );
    }

    private function createGed(EntityManagerInterface $em, array $media): void
    {
        // ── Tags ──────────────────────────────────────────────────────────────
        $tagDefs = [
            ['name' => 'Confidentiel',  'color' => '#ef4444'],
            ['name' => 'À valider',     'color' => '#f59e0b'],
            ['name' => 'Signé',         'color' => '#10b981'],
            ['name' => 'Archivé',       'color' => '#6b7280'],
            ['name' => 'RGPD',          'color' => '#3b82f6'],
            ['name' => 'ISO 27001',     'color' => '#8b5cf6'],
        ];
        $tags = [];
        $tagRepository = $em->getRepository(DocumentTag::class);
        foreach ($tagDefs as $def) {
            // Reused when the name is already taken, like the documents just
            // above. Without this a second `make demo` created a second set of
            // six tags and linked the documents to those too, so every row in
            // the library grew another pair of badges - five runs, five
            // "Confidentiel" on the same contract, and nothing in the
            // interface to explain why.
            $tag = $tagRepository->findOneBy(['name' => $def['name']]) ?? new DocumentTag();
            $tag->setName($def['name'])->setColor($def['color']);
            $em->persist($tag);
            $tags[] = $tag;
        }

        // aliases: 0=Confidentiel, 1=À valider, 2=Signé, 3=Archivé, 4=RGPD, 5=ISO 27001

        // ── Folders ───────────────────────────────────────────────────────────
        $folderDefs = [
            ['name' => 'Aurora Tech',    'parent' => null, 'position' => 0],
            ['name' => 'Clients',        'parent' => null, 'position' => 1],
            ['name' => 'Internes',       'parent' => null, 'position' => 2],
            ['name' => 'Contrats',       'parent' => 1,    'position' => 0],
            ['name' => 'Présentations',  'parent' => 1,    'position' => 1],
            ['name' => 'RH',             'parent' => 2,    'position' => 0],
            ['name' => 'Finance',        'parent' => 2,    'position' => 1],
            // In the trash, like the category and the three documents further
            // down: the screen that gathers the trash of every module only
            // showed notes, and the tour page presenting it had nothing else
            // to capture.
            ['name' => 'Anciens devis',  'parent' => 1,    'position' => 2, 'trashed' => '-8 days'],
        ];
        $folders = [];
        $folderRepository = $em->getRepository(DocumentFolder::class);
        foreach ($folderDefs as $def) {
            // Same reason as the tags: the tree was rebuilt whole at each run,
            // and the library ended up with three "Clients" folders holding
            // nothing.
            $folder = $folderRepository->findOneBy(['name' => $def['name']]) ?? new DocumentFolder();
            $folder->setName($def['name'])->setPosition($def['position'])
                ->setDeletedAt(isset($def['trashed']) ? new DateTimeImmutable($def['trashed']) : null);
            if (null !== $def['parent']) {
                $folder->setParent($folders[$def['parent']]);
            }

            $em->persist($folder);
            $folders[] = $folder;
        }

        // aliases: 0=Aurora Tech, 1=Clients, 2=Internes, 3=Contrats, 4=Présentations, 5=RH, 6=Finance

        // ── Categories ────────────────────────────────────────────────────────
        $catDefs = [
            ['name' => 'Contrats Clients',         'slug' => 'contrats-clients',       'desc' => 'Contrats signés avec nos clients et partenaires commerciaux.'],
            ['name' => 'Documentation Technique',  'slug' => 'doc-technique',          'desc' => 'Guides d\'installation, spécifications et manuels techniques.'],
            ['name' => 'Ressources Marketing',     'slug' => 'ressources-marketing',   'desc' => 'Visuels, présentations et supports de communication.'],
            ['name' => 'Ressources Humaines',      'slug' => 'ressources-humaines',    'desc' => 'Fiches de poste, procédures RH et documents administratifs du personnel.'],
            ['name' => 'Finance & Comptabilité',   'slug' => 'finance-comptabilite',   'desc' => 'Rapports financiers, budgets et documents comptables.'],
            ['name' => 'Qualité & Conformité',     'slug' => 'qualite-conformite',     'desc' => 'Politiques qualité, audits et certifications.'],
            ['name' => 'Événements 2023',          'slug' => 'evenements-2023',        'desc' => 'Supports des salons et journées portes ouvertes de 2023.', 'trashed' => '-20 days'],
        ];
        $categories = [];
        // Through the interface, not the concrete class. A client may map
        // `DocumentCategoryInterface` onto its own entity - that is the whole
        // extensibility convention - and Doctrine then refuses an Aurora
        // `DocumentCategory` for the association, with an error that names two
        // classes and no reason. `getClassName()` gives back whichever one this
        // installation actually resolved to.
        $categoryRepository = $em->getRepository(DocumentCategoryInterface::class);
        $categoryClass = $categoryRepository->getClassName();

        foreach ($catDefs as $def) {
            // Reused when the slug is already taken, so `make demo` can run on
            // a database that already has demo data. It used to always insert
            // and die on the unique slug - after the target had purged
            // var/uploads, which left the pictures gone and the rows unchanged.
            $c = $categoryRepository->findOneBy(['slug' => $def['slug']]) ?? new $categoryClass();
            $c->setName($def['name'])->setSlug($def['slug'])->setDescription($def['desc'])
                ->setDeletedAt(isset($def['trashed']) ? new DateTimeImmutable($def['trashed']) : null);
            $em->persist($c);
            $categories[] = $c;
        }

        // ── Documents (cat, folder, tags) ─────────────────────────────────────
        // `file` (when non-null) is a path under test_files/ that gets
        // copied into var/uploads/ged/Y/m/ - one copy per doc so each
        // carries a unique filePath, mirroring real uploads through
        // /suite/ged/documents/upload. Docs with `file => null` stay
        // file-less so users have something to test the editor's upload
        // flow with.
        $samplePdf = 'files/pdfs/pdfform_sample.pdf';
        $docDefs = [
            ['title' => 'Contrat Tech Innovation SARL 2025',           'cat' => 0, 'folder' => 3, 'tags' => [0, 2],    'status' => DocumentStatusEnum::Published, 'desc' => 'Contrat de prestation de services signé le 15 janvier 2025. Durée : 12 mois renouvelable.', 'file' => $samplePdf],
            ['title' => 'Contrat BioMed France - Maintenance 2025',    'cat' => 0, 'folder' => 3, 'tags' => [0, 2],    'status' => DocumentStatusEnum::Published, 'desc' => 'Contrat de maintenance et support niveau 2 pour la suite Aurora.', 'file' => $samplePdf],
            ['title' => 'Avenant Contrat Retail Connect - Jan 2025',   'cat' => 0, 'folder' => 3, 'tags' => [0, 1],    'status' => DocumentStatusEnum::Draft,     'desc' => 'Avenant tarifaire en cours de négociation pour le renouvellement 2025.', 'file' => null],
            ['title' => "Guide d'installation Aurora v2.0",            'cat' => 1, 'folder' => 0, 'tags' => [],         'status' => DocumentStatusEnum::Published, 'desc' => 'Documentation complète pour installer et configurer Aurora en production.', 'file' => $samplePdf],
            ['title' => 'API Aurora - Documentation Développeur v2.1', 'cat' => 1, 'folder' => 0, 'tags' => [],         'status' => DocumentStatusEnum::Published, 'desc' => 'Référence complète de l\'API REST Aurora : endpoints, authentification, exemples.', 'file' => $samplePdf],
            ['title' => 'Architecture Technique Aurora - Whitepaper',  'cat' => 1, 'folder' => 0, 'tags' => [3],        'status' => DocumentStatusEnum::Archived,  'desc' => 'Document d\'architecture technique v1.x (archivé, remplacé par la version 2.x).', 'file' => $samplePdf],
            ['title' => 'Rapport Annuel 2024 - Aurora Tech',           'cat' => 4, 'folder' => 6, 'tags' => [0, 1],    'status' => DocumentStatusEnum::Draft,     'desc' => 'Bilan financier et opérationnel de l\'exercice 2024. En cours de validation.', 'file' => null],
            ['title' => 'Budget Prévisionnel 2025 - Aurora Tech',      'cat' => 4, 'folder' => 6, 'tags' => [0],        'status' => DocumentStatusEnum::Published, 'desc' => 'Budget prévisionnel approuvé par le comité de direction le 10 janvier 2025.', 'file' => $samplePdf],
            ['title' => 'Facture Commerciale 2025-156',                'cat' => 4, 'folder' => 6, 'tags' => [0, 2],    'status' => DocumentStatusEnum::Published, 'desc' => 'Facture numérisée, rattachée au dossier comptable de l\'exercice.', 'file' => 'files/placeholders/document-placeholder.webp'],
            ['title' => 'Charte Graphique Aurora - Brand Guidelines',  'cat' => 2, 'folder' => 4, 'tags' => [],         'status' => DocumentStatusEnum::Published, 'desc' => 'Couleurs, typographies, logos et règles d\'utilisation de la marque Aurora.', 'file' => $samplePdf],
            ['title' => 'Kit Presse Aurora Tech Day 2025',             'cat' => 2, 'folder' => 4, 'tags' => [],         'status' => DocumentStatusEnum::Published, 'desc' => 'Communiqué de presse, visuels HD et biographies intervenants.', 'file' => $samplePdf],
            ['title' => 'Fiche de Poste - Développeur Full Stack',     'cat' => 3, 'folder' => 5, 'tags' => [4],        'status' => DocumentStatusEnum::Published, 'desc' => 'Description du poste, compétences requises et processus de recrutement.', 'file' => $samplePdf],
            ['title' => 'Politique de Télétravail - Aurora Tech',      'cat' => 3, 'folder' => 5, 'tags' => [4],        'status' => DocumentStatusEnum::Published, 'desc' => 'Règles et procédures applicables au travail à distance.', 'file' => $samplePdf],
            ['title' => 'Certification ISO 27001 - Audit 2024',        'cat' => 5, 'folder' => 2, 'tags' => [5, 0],    'status' => DocumentStatusEnum::Published, 'desc' => 'Rapport d\'audit de conformité ISO 27001 réalisé en novembre 2024.', 'file' => $samplePdf],

            // Images, because a library of nothing but PDFs shows one row and
            // one icon, repeated. Half of what the screen does - the tiles,
            // the preview when a document is opened, the sizes generated on
            // upload - is invisible until something in it is a picture.
            ['title' => 'Visuel de campagne - Automne 2025',           'cat' => 2, 'folder' => 4, 'tags' => [],         'status' => DocumentStatusEnum::Published, 'desc' => 'Visuel principal de la campagne d\'automne, décliné en trois formats.', 'file' => 'images/campagne-automne.jpg',   'w' => 1600, 'h' => 900],
            ['title' => 'Photo d\'équipe - Séminaire 2025',            'cat' => 2, 'folder' => 4, 'tags' => [],         'status' => DocumentStatusEnum::Published, 'desc' => 'Photo de groupe prise au séminaire annuel, utilisable sur le site et en presse.', 'file' => 'images/equipe-seminaire.jpg',   'w' => 1400, 'h' => 933],
            ['title' => 'Logo Aurora - Fond sombre',                   'cat' => 2, 'folder' => 4, 'tags' => [],         'status' => DocumentStatusEnum::Published, 'desc' => 'Logo sur fond sombre, à réserver aux bandeaux et aux couvertures.', 'file' => 'images/logo-fond-sombre.png',   'w' => 1200, 'h' => 1200],
            ['title' => 'Bureau - Illustration article',               'cat' => 1, 'folder' => 0, 'tags' => [],         'status' => DocumentStatusEnum::Draft,     'desc' => 'Illustration en cours de sélection pour l\'article sur l\'installation.', 'file' => 'images/bureau-illustration.webp', 'w' => 1600, 'h' => 1067],
            ['title' => 'Plan des locaux - Étage 2',                   'cat' => 3, 'folder' => 5, 'tags' => [4],        'status' => DocumentStatusEnum::Published, 'desc' => 'Plan d\'évacuation du deuxième étage, affiché près des ascenseurs.', 'file' => 'images/plan-etage-2.png',       'w' => 1240, 'h' => 1754],
            ['title' => 'Capture - Tableau de bord client',            'cat' => 1, 'folder' => 0, 'tags' => [0],        'status' => DocumentStatusEnum::Published, 'desc' => 'Capture d\'écran du tableau de bord, jointe à la documentation de prise en main.', 'file' => 'images/capture-tableau-de-bord.png', 'w' => 1600, 'h' => 1000],

            // In the trash, at different dates, so the screen says what it
            // usually says: what goes soon and what has time left. At the end
            // of the list, so no earlier file gets renamed.
            ['title' => 'Devis Menuiserie - première version',        'cat' => 0, 'folder' => 7, 'tags' => [],         'status' => DocumentStatusEnum::Draft,     'desc' => 'Premier chiffrage, remplacé par le devis signé.', 'file' => $samplePdf, 'trashed' => '-2 days'],
            ['title' => 'Brochure commerciale 2023',                  'cat' => 2, 'folder' => 4, 'tags' => [3],        'status' => DocumentStatusEnum::Archived,  'desc' => 'Ancienne brochure, retirée depuis la nouvelle charte.', 'file' => $samplePdf, 'trashed' => '-11 days'],
            ['title' => 'Photo d\'équipe - prise floue',              'cat' => 2, 'folder' => 4, 'tags' => [],         'status' => DocumentStatusEnum::Draft,     'desc' => 'Doublon raté de la photo du séminaire.', 'file' => 'images/equipe-seminaire.jpg', 'w' => 1400, 'h' => 933, 'trashed' => '-1 day'],
        ];

        // `2` and not `4`, as twenty lines above: four levels went out of the
        // repository to point at `~/dev/test_files`, which does not exist. As
        // a result, fifteen documents out of thirty-three had no file, no
        // size and no thumbnail - a demo media library made of empty rows.
        // The test next door did not see it because it only read the other
        // half of the list.
        $testFilesRoot = dirname(__DIR__, 2).'/test_files';
        $gedMonth = new DateTimeImmutable()->format('Y/m');
        $gedDir = $this->uploadDir.'/ged/'.$gedMonth;
        $this->fs->mkdir($gedDir);

        $mimeByExt = [
            'pdf' => 'application/pdf',
            'webp' => 'image/webp',
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'png' => 'image/png',
        ];

        $documentRepository = $em->getRepository(Document::class);

        foreach ($docDefs as $idx => $def) {
            // Keyed on the title: some of these carry no file at all - on
            // purpose, so there is something to test the upload flow against -
            // so the path cannot be the key. The titles are distinct across the
            // set, which is what makes them one.
            $d = $documentRepository->findOneBy(['title' => $def['title']]) ?? new Document();
            $d->setTitle($def['title'])
              ->setDescription($def['desc'])
              ->setStatus($def['status'])
              ->setCategory($categories[$def['cat']])
              ->setFolder($folders[$def['folder']])
              ->setDeletedAt(isset($def['trashed']) ? new DateTimeImmutable($def['trashed']) : null);
            // Emptied first, so the set of badges is the one written here and
            // not the sum of every run: a document reused by title keeps the
            // links it already had, and the definition is the authority.
            $d->clearTags();
            foreach ($def['tags'] as $tagIndex) {
                $d->addTag($tags[$tagIndex]);
            }

            if (null !== $def['file']) {
                $src = $testFilesRoot.'/'.$def['file'];
                $ext = mb_strtolower(pathinfo($def['file'], PATHINFO_EXTENSION));
                $mimeType = $mimeByExt[$ext] ?? 'application/octet-stream';
                $fileName = sprintf('demo-doc-%02d.%s', $idx, $ext);
                $destFile = $gedDir.'/'.$fileName;

                if (file_exists($src)) {
                    $this->fs->copy($src, $destFile, true);
                } elseif (isset($def['w'])) {
                    // The flat colour from above, and it is a choice, not a
                    // fallback: these documents have no source in
                    // `test_files/` because a drawn flat colour is better than
                    // a committed photograph, which would have a subject and
                    // weight.
                    //
                    // The previous comment said `test_files/` was not shipped
                    // with the repository. That was wrong - six files are
                    // tracked there - and it was the symptom of the root
                    // resolved wrongly twenty lines above.
                    //
                    // A PDF has no such fallback: without `w`, a source that
                    // cannot be found leaves a row without a file, and the
                    // test next door refuses that case.
                    $this->drawPlaceholder($destFile, [
                        'name' => $fileName,
                        'mime' => $mimeType,
                        'w' => $def['w'],
                        'h' => $def['h'],
                    ]);
                }

                if (file_exists($destFile)) {
                    $d->setFilePath('ged/'.$gedMonth.'/'.$fileName)
                      ->setFileName($fileName)
                      ->setOriginalName($def['title'].'.'.$ext)
                      ->setMimeType($mimeType)
                      ->setSize((int) filesize($destFile));

                    if (MimeTypeEnum::Pdf->value === $mimeType) {
                        $thumbDir = 'ged/thumbnails/'.$gedMonth;
                        $thumbBasename = pathinfo($fileName, PATHINFO_FILENAME);
                        $thumbnailPath = $this->pdfThumbnailGenerator->generate(
                            $this->storageManager->active(),
                            'ged/'.$gedMonth.'/'.$fileName,
                            $thumbDir,
                            $thumbBasename,
                        );
                        if (null !== $thumbnailPath) {
                            $d->setThumbnailPath($thumbnailPath);
                        }
                    }

                    // What an upload through the interface would have written:
                    // the dimensions the screen prints, and the sizes it
                    // serves. Without them a demo picture is a file on disk
                    // that the library cannot say anything about.
                    $dimensions = @getimagesize($destFile);
                    if (false !== $dimensions) {
                        $d->setWidth($dimensions[0])->setHeight($dimensions[1]);
                    }

                    $d->setRenditions($this->renditions->generate($this->storageManager->active(), 'ged/'.$gedMonth.'/'.$fileName, $mimeType));
                }
            }

            $em->persist($d);
        }
    }

    /**
     * Three successive states of the same visual.
     *
     * Replacing a document's file leaves a trace: the detail panel lists the
     * versions, and only lists them from two onwards. A fresh demo has none -
     * history is born from a replacement, and nobody makes one before the
     * first capture - so the page explaining versions showed a library with
     * no history.
     *
     * The two earlier files are written to disk: a version can be downloaded,
     * and a row pointing to nothing would only have shown half the screen.
     */
    private function createVersionHistory(EntityManagerInterface $em): void
    {
        $document = $em->getRepository(Document::class)
            ->findOneBy(['title' => 'Visuel de campagne - Automne 2025']);

        if (!$document instanceof Document || null === $document->getFilePath()) {
            return;
        }

        $versionRepository = $em->getRepository(DocumentVersion::class);

        // The demo is replayed: without this guard, each `make demo` added
        // three more rows to the same document.
        if ([] !== $versionRepository->findBy(['document' => $document])) {
            return;
        }

        $month = new DateTimeImmutable()->format('Y/m');
        $dir = $this->uploadDir.'/ged/'.$month;
        $this->fs->mkdir($dir);

        // The most recent version is the current file: that is what the
        // product writes, as it snapshots the document's state on every save.
        // The two previous ones have their own file.
        $history = [
            ['file' => 'demo-doc-14-v1.jpg', 'own' => true,  'days' => 24],
            ['file' => 'demo-doc-14-v2.jpg', 'own' => true,  'days' => 9],
            ['file' => (string) $document->getFileName(), 'own' => false, 'days' => 0],
        ];

        $ids = [];
        foreach ($history as $number => $state) {
            // The current file keeps the path the document carries: it may
            // have been written in a different month from this run's.
            $path = $state['own'] ? 'ged/'.$month.'/'.$state['file'] : $document->getFilePath();

            if ($state['own']) {
                $dest = $dir.'/'.$state['file'];

                if (!file_exists($dest)) {
                    $this->drawPlaceholder($dest, [
                        'name' => $state['file'],
                        'mime' => 'image/jpeg',
                        'w' => 1600,
                        'h' => 900,
                    ]);
                }

                if (!file_exists($dest)) {
                    continue;
                }
            }

            $version = new DocumentVersion();
            $version->setDocument($document)
                ->setFilePath($path)
                ->setFileName($state['file'])
                ->setOriginalName('visuel-campagne-automne.jpg')
                ->setMimeType('image/jpeg')
                ->setSize(@filesize($this->uploadDir.'/'.$path) ?: 0)
                ->setVersionNumber($number + 1);

            $em->persist($version);
            $ids[] = [$version, $state['days']];
        }

        $em->flush();

        // The creation date is set in the constructor, and the product has no
        // reason to move it. Three versions born in the same second would not
        // show what the column is there to read, so the demo moves them back
        // in the database rather than opening an entry point nobody would use
        // elsewhere.
        $table = $em->getClassMetadata(DocumentVersion::class)->getTableName();
        $connection = $em->getConnection();

        foreach ($ids as [$version, $days]) {
            if (0 === $days) {
                continue;
            }

            $connection->executeStatement(
                sprintf('UPDATE %s SET created_at = :created WHERE id = :id', $table),
                [
                    'created' => new DateTimeImmutable(sprintf('-%d days', $days))->format('Y-m-d H:i:s'),
                    'id' => $version->getId(),
                ],
            );
        }
    }

    /**
     * A family: the original and two colour variants.
     *
     * Without it, a fresh demo has no variant, and everything the media
     * library can do with a family stays invisible: the preview banner, the
     * grouping in the list, the usage counted for the whole family. The
     * variants go through the service behind the "Variante de couleur" button,
     * not through a file drawn here: that is what a user gets, and no image is
     * committed for it.
     *
     * The blue one is filed in another folder, on purpose: it is the case that
     * trashing a folder must catch ("Emporter aussi la variante rangée
     * ailleurs"), and it only shows up with it.
     */
    private function createColorFamily(EntityManagerInterface $em): void
    {
        $documentRepository = $em->getRepository(Document::class);
        $original = $documentRepository->findOneBy(['title' => 'Visuel de campagne - Automne 2025']);

        if (!$original instanceof Document || null === $original->getFilePath()) {
            return;
        }

        // The demo is replayed: a family already there is not made again.
        if ([] !== $documentRepository->findBy(['original' => $original])) {
            return;
        }

        $elsewhere = $em->getRepository(DocumentFolder::class)->findOneBy(['name' => 'Aurora Tech']);

        foreach ([['#ef4444', 'rouge', false], ['#3b82f6', 'bleu', true]] as [$color, $label, $fileElsewhere]) {
            $alternate = $this->colorAlternates->create($original, new ColorAlternateInput(color: $color, label: $label));

            if ($fileElsewhere && $alternate instanceof Document && $elsewhere instanceof DocumentFolder) {
                $alternate->setFolder($elsewhere);
            }
        }

        $em->flush();
    }
}
