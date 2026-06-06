<?php

declare(strict_types=1);

namespace Aurora\Module\Ged\DataFixtures;

use Aurora\Core\DataFixtures\AppFixtures;
use Aurora\Core\Storage\Enum\MimeTypeEnum;
use Aurora\Core\Storage\Service\PdfThumbnailGenerator;
use Aurora\Module\Billing\Invoice\Entity\Invoice;
use Aurora\Module\Configuration\Setting\Enum\ApplicationParameterEnum;
use Aurora\Module\Configuration\Setting\Service\SettingsService;
use Aurora\Module\Ged\DocumentCategory\Entity\DocumentCategory;
use Aurora\Module\Ged\DocumentFolder\Entity\DocumentFolder;
use Aurora\Module\Ged\DocumentTag\Entity\DocumentTag;
use Aurora\Module\Ged\Document\Entity\Document;
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
        #[Autowire('%app.upload_dir%')]
        private readonly string $uploadDir,
        private readonly PdfThumbnailGenerator $pdfThumbnailGenerator,
        private readonly SettingsService $settingsManager,
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

        // Favicon + logo point at the landscape image (media[1]); after flush so IDs exist.
        if (isset($media[1]) && null !== $media[1]->getId()) {
            $faviconId = (string) $media[1]->getId();
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

        $sourceDir = dirname(__DIR__, 4).'/test_files';
        $defs = [
            ['src' => 'images/ai-generated-8359510_1280-1816135935.jpg', 'name' => 'hero-banner.jpg',      'original' => 'hero-banner.jpg',    'mime' => 'image/jpeg', 'w' => 1280, 'h' => 853],
            ['src' => 'images/canadian-flag-canada-maple-country-wallpaper-1506073439.jpg', 'name' => 'landscape.jpg', 'original' => 'landscape.jpg', 'mime' => 'image/jpeg', 'w' => 1280, 'h' => 720],
            ['src' => 'images/me.jpg',           'name' => 'portrait-team.jpg',  'original' => 'portrait-team.jpg',  'mime' => 'image/jpeg', 'w' => 800,  'h' => 1000],
            ['src' => 'images/previous_job.jpg', 'name' => 'office-setup.jpg',   'original' => 'office-setup.jpg',   'mime' => 'image/jpeg', 'w' => 1200, 'h' => 800],
            ['src' => 'videos/sample-30s-720p.mp4',  'name' => 'demo-video.mp4',   'original' => 'demo-video.mp4',   'mime' => 'video/mp4',  'w' => 1280, 'h' => 720],
            ['src' => 'files/invoices/Commercial-Invoice-Sample.webp', 'name' => 'invoice-sample.webp', 'original' => 'invoice-sample.webp', 'mime' => 'image/webp', 'w' => 0, 'h' => 0],
        ];

        $media = [];
        foreach ($defs as $def) {
            $src = $sourceDir.'/'.$def['src'];
            if (!file_exists($src)) {
                continue;
            }

            $dest = $destDir.'/'.$def['name'];
            $this->fs->copy($src, $dest, true);

            $document = new Document();
            $document->setTitle($def['original'])
                ->setFileName($def['name'])
                ->setOriginalName($def['original'])
                ->setMimeType($def['mime'])
                ->setSize((int) filesize($dest))
                ->setFilePath('ged/'.$month.'/'.$def['name'])
                ->setStatus(DocumentStatusEnum::Published)
                ->setVariants([]);

            if ($def['w'] > 0) {
                $document->setWidth($def['w'])->setHeight($def['h']);
            }

            $em->persist($document);
            $media[] = $document;
        }

        return $media;
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
        foreach ($tagDefs as $def) {
            $tag = new DocumentTag();
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
        ];
        $folders = [];
        foreach ($folderDefs as $def) {
            $folder = new DocumentFolder();
            $folder->setName($def['name'])->setPosition($def['position']);
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
        ];
        $categories = [];
        foreach ($catDefs as $def) {
            $c = new DocumentCategory();
            $c->setName($def['name'])->setSlug($def['slug'])->setDescription($def['desc']);
            $em->persist($c);
            $categories[] = $c;
        }

        // ── Documents (cat, folder, tags) ─────────────────────────────────────
        // `file` (when non-null) is a path under test_files/ that gets
        // copied into var/uploads/ged/Y/m/ — one copy per doc so each
        // carries a unique filePath, mirroring real uploads through
        // /backend/ged/documents/upload. Docs with `file => null` stay
        // file-less so users have something to test the editor's upload
        // flow with.
        $samplePdf = 'files/pdfs/pdfform_sample.pdf';
        $docDefs = [
            ['title' => 'Contrat Tech Innovation SARL 2025',           'cat' => 0, 'folder' => 3, 'tags' => [0, 2],    'status' => DocumentStatusEnum::Published, 'desc' => 'Contrat de prestation de services signé le 15 janvier 2025. Durée : 12 mois renouvelable.', 'file' => $samplePdf],
            ['title' => 'Contrat BioMed France — Maintenance 2025',    'cat' => 0, 'folder' => 3, 'tags' => [0, 2],    'status' => DocumentStatusEnum::Published, 'desc' => 'Contrat de maintenance et support niveau 2 pour la suite Aurora.', 'file' => $samplePdf],
            ['title' => 'Avenant Contrat Retail Connect — Jan 2025',   'cat' => 0, 'folder' => 3, 'tags' => [0, 1],    'status' => DocumentStatusEnum::Draft,     'desc' => 'Avenant tarifaire en cours de négociation pour le renouvellement 2025.', 'file' => null],
            ['title' => "Guide d'installation Aurora v2.0",            'cat' => 1, 'folder' => 0, 'tags' => [],         'status' => DocumentStatusEnum::Published, 'desc' => 'Documentation complète pour installer et configurer Aurora en production.', 'file' => $samplePdf],
            ['title' => 'API Aurora — Documentation Développeur v2.1', 'cat' => 1, 'folder' => 0, 'tags' => [],         'status' => DocumentStatusEnum::Published, 'desc' => 'Référence complète de l\'API REST Aurora : endpoints, authentification, exemples.', 'file' => $samplePdf],
            ['title' => 'Architecture Technique Aurora — Whitepaper',  'cat' => 1, 'folder' => 0, 'tags' => [3],        'status' => DocumentStatusEnum::Archived,  'desc' => 'Document d\'architecture technique v1.x (archivé, remplacé par la version 2.x).', 'file' => $samplePdf],
            ['title' => 'Rapport Annuel 2024 — Aurora Tech',           'cat' => 4, 'folder' => 6, 'tags' => [0, 1],    'status' => DocumentStatusEnum::Draft,     'desc' => 'Bilan financier et opérationnel de l\'exercice 2024. En cours de validation.', 'file' => null],
            ['title' => 'Budget Prévisionnel 2025 — Aurora Tech',      'cat' => 4, 'folder' => 6, 'tags' => [0],        'status' => DocumentStatusEnum::Published, 'desc' => 'Budget prévisionnel approuvé par le comité de direction le 10 janvier 2025.', 'file' => $samplePdf],
            ['title' => 'Facture Commerciale BTQ-2024-156',            'cat' => 4, 'folder' => 6, 'tags' => [0, 2],    'status' => DocumentStatusEnum::Published, 'desc' => 'Facture commerciale d\'exemple — Elegance Boutique → Canadian Fashion Hub. Numérisée pour audit douanier.', 'file' => 'files/invoices/Commercial-Invoice-Sample.webp'],
            ['title' => 'Charte Graphique Aurora — Brand Guidelines',  'cat' => 2, 'folder' => 4, 'tags' => [],         'status' => DocumentStatusEnum::Published, 'desc' => 'Couleurs, typographies, logos et règles d\'utilisation de la marque Aurora.', 'file' => $samplePdf],
            ['title' => 'Kit Presse Aurora Tech Day 2025',             'cat' => 2, 'folder' => 4, 'tags' => [],         'status' => DocumentStatusEnum::Published, 'desc' => 'Communiqué de presse, visuels HD et biographies intervenants.', 'file' => $samplePdf],
            ['title' => 'Fiche de Poste — Développeur Full Stack',     'cat' => 3, 'folder' => 5, 'tags' => [4],        'status' => DocumentStatusEnum::Published, 'desc' => 'Description du poste, compétences requises et processus de recrutement.', 'file' => $samplePdf],
            ['title' => 'Politique de Télétravail — Aurora Tech',      'cat' => 3, 'folder' => 5, 'tags' => [4],        'status' => DocumentStatusEnum::Published, 'desc' => 'Règles et procédures applicables au travail à distance.', 'file' => $samplePdf],
            ['title' => 'Certification ISO 27001 — Audit 2024',        'cat' => 5, 'folder' => 2, 'tags' => [5, 0],    'status' => DocumentStatusEnum::Published, 'desc' => 'Rapport d\'audit de conformité ISO 27001 réalisé en novembre 2024.', 'file' => $samplePdf],
        ];

        $testFilesRoot = dirname(__DIR__, 4).'/test_files';
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

        foreach ($docDefs as $idx => $def) {
            $d = new Document();
            $d->setTitle($def['title'])
              ->setDescription($def['desc'])
              ->setStatus($def['status'])
              ->setCategory($categories[$def['cat']])
              ->setFolder($folders[$def['folder']]);
            foreach ($def['tags'] as $tagIndex) {
                $d->addTag($tags[$tagIndex]);
            }

            if (null !== $def['file']) {
                $src = $testFilesRoot.'/'.$def['file'];
                if (file_exists($src)) {
                    $ext = mb_strtolower(pathinfo($def['file'], PATHINFO_EXTENSION));
                    $fileName = sprintf('demo-doc-%02d.%s', $idx, $ext);
                    $destFile = $gedDir.'/'.$fileName;
                    $this->fs->copy($src, $destFile, true);

                    $mimeType = $mimeByExt[$ext] ?? 'application/octet-stream';
                    $d->setFilePath('ged/'.$gedMonth.'/'.$fileName)
                      ->setFileName($fileName)
                      ->setOriginalName($def['title'].'.'.$ext)
                      ->setMimeType($mimeType)
                      ->setSize((int) filesize($destFile));

                    if (MimeTypeEnum::Pdf->value === $mimeType) {
                        $thumbDir = 'ged/thumbnails/'.$gedMonth;
                        $thumbBasename = pathinfo($fileName, PATHINFO_FILENAME);
                        $thumbnailPath = $this->pdfThumbnailGenerator->generate(
                            'ged/'.$gedMonth.'/'.$fileName,
                            $thumbDir,
                            $thumbBasename,
                        );
                        if (null !== $thumbnailPath) {
                            $d->setThumbnailPath($thumbnailPath);
                        }
                    }
                }
            }

            $em->persist($d);
        }
    }
}
