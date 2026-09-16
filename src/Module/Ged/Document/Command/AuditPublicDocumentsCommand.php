<?php

declare(strict_types=1);

namespace Aurora\Module\Ged\Document\Command;

use Aurora\Module\Configuration\Setting\Enum\ApplicationParameterEnum;
use Aurora\Module\Configuration\Setting\Repository\SettingRepository;
use Aurora\Module\Editorial\Post\Entity\PostInterface;
use Aurora\Module\Editorial\Post\Enum\PostStatusEnum;
use Aurora\Module\Editorial\Post\Repository\PostRepository;
use Aurora\Module\Ged\Document\Entity\DocumentInterface;
use Aurora\Module\Ged\Document\Repository\DocumentRepository;
use Aurora\Module\Ged\Enum\DocumentStatusEnum;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

use function is_array;
use function is_int;
use function is_string;
use function sprintf;
use function str_ends_with;

/**
 * Which documents a public page points at while not being published.
 *
 * Read-only, and written to be run before the withholding matters rather
 * than after. `/uploads/{path}` now serves published documents alone, and a
 * site whose logo, favicon or post banner happens to be a draft would lose
 * that picture the moment the change is deployed. The status has been
 * `draft` by default since the GED existed, and nothing forced it to
 * anything else on the ordinary upload path, so "it was chosen while still a
 * draft" is the expected case, not the unlikely one.
 *
 * It reports, it does not fix. What to do about each line is a judgement -
 * publish the document, or point the page at another one - and a command
 * that decided for you would be publishing files on its own.
 *
 * **What it looks at**, which is every surface that resolves a document
 * without checking its status:
 *
 *  - the three branding settings, read by id with no status filter at all;
 *  - the thumbnail of every published post, and each translation's
 *    `og:image`;
 *  - the pictures laid out in a published post's banner, grid and gallery.
 *
 * **What it does not**: a client module's own surfaces. It has no way to know
 * them. The document usage registry (`aurora.document_usage_provider`) is
 * what would tell it, and since 2026-09-16 that registry is answered - by
 * Studio decks, Studio space attachments and Editorial posts - so wiring this
 * command to it rather than to the hand-written list above is now possible
 * and would cover a client's own modules for free. Left as it is until
 * somebody needs it, but no longer for want of implementations.
 */
#[AsCommand(
    name: 'aurora:ged:audit-public-documents',
    description: 'List the documents a public page points at that are not published.',
)]
final class AuditPublicDocumentsCommand extends Command
{
    /** The settings that hold a document id. All three are resolved by id, none checks a status. */
    private const array BRANDING_PARAMETERS = [
        ApplicationParameterEnum::LogoMediaId,
        ApplicationParameterEnum::FaviconMediaId,
        ApplicationParameterEnum::SeoDefaultOgImage,
    ];

    public function __construct(
        private readonly DocumentRepository $documentRepository,
        private readonly PostRepository $postRepository,
        private readonly SettingRepository $settingRepository,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Documents publics non publiés');

        /** @var array<int, list<string>> $referencedBy */
        $referencedBy = [];

        foreach (self::BRANDING_PARAMETERS as $parameter) {
            $id = (int) ($this->settingRepository->get($parameter->value, '') ?? '');

            if ($id > 0) {
                $referencedBy[$id][] = sprintf('réglage %s', $parameter->value);
            }
        }

        foreach ($this->publishedPosts() as $post) {
            $label = sprintf('publication #%d', (int) $post->getId());

            $thumbnail = $post->getThumbnail();
            if ($thumbnail instanceof DocumentInterface && null !== $thumbnail->getId()) {
                $referencedBy[$thumbnail->getId()][] = $label.' (vignette)';
            }

            foreach ($post->getTranslations() as $translation) {
                $ogImage = $translation->getOgImage();
                if ($ogImage instanceof DocumentInterface && null !== $ogImage->getId()) {
                    $referencedBy[$ogImage->getId()][] = $label.' (og:image)';
                }
            }

            foreach ([
                'bandeau' => $post->getBannerLayout(),
                'grille' => $post->getGridLayout(),
                'galerie' => $post->getGalleryLayout(),
            ] as $surface => $layout) {
                foreach ($this->documentIdsIn($layout) as $id) {
                    $referencedBy[$id][] = sprintf('%s (%s)', $label, $surface);
                }
            }
        }

        if ([] === $referencedBy) {
            $io->success('Aucune page publique ne pointe vers un document.');

            return Command::SUCCESS;
        }

        $rows = [];
        foreach ($this->documentRepository->findBy(['id' => array_keys($referencedBy)]) as $document) {
            $id = $document->getId();
            if (null === $id) {
                continue;
            }

            if (DocumentStatusEnum::Published === $document->getStatus()) {
                continue;
            }

            $rows[] = [
                $id,
                $document->getTitle(),
                $document->isTrashed() ? 'corbeille' : $document->getStatus()->value,
                implode(', ', array_unique($referencedBy[$id])),
            ];
        }

        if ([] === $rows) {
            $io->success(sprintf(
                '%d document(s) référencé(s) par une page publique, tous publiés. Rien ne disparaîtra.',
                count($referencedBy),
            ));

            return Command::SUCCESS;
        }

        $io->table(['id', 'titre', 'statut', 'référencé par'], $rows);
        $io->warning(sprintf(
            '%d document(s) cesseront d\'être servis aux visiteurs. Publiez-les, ou changez la page qui les référence.',
            count($rows),
        ));

        // Not a failure exit code: the command answered the question it was
        // asked. A CI pipeline that wants this to be blocking can read the
        // table; a person running it before a deploy should not have to
        // explain a red line to themselves.
        return Command::SUCCESS;
    }

    /** @return iterable<PostInterface> */
    private function publishedPosts(): iterable
    {
        return $this->postRepository->findBy(['status' => PostStatusEnum::Published]);
    }

    /**
     * The document ids inside one layout array.
     *
     * **It is `mediaId`, never `id`.** An `id` in these columns names the
     * block, not the picture: a banner item is `"banner-text"`, a gallery
     * slot is `"shot-1"`. Reading `id` here finds strings, silently collects
     * nothing, and reports a clean site that is not one - which is the worst
     * thing a pre-deployment check can do. Confirmed against the three view
     * builders and against real rows.
     *
     * Three shapes carry a document, and all three are read:
     * `mediaId` (a zone or an item), `logoMediaId` (a banner's logo) and
     * `mediaIds` (a gallery zone, which names many at once).
     *
     * Matched on the suffix rather than on a fixed list, and walked at any
     * depth, because the grid's shape is actively changing: a zone kind
     * added next week with its own `…MediaId` is picked up without this
     * command being touched. The cost of the suffix rule is that a key
     * outside these columns could match it by accident; these three columns
     * are the only place it is applied.
     *
     * @param array<mixed> $layout
     *
     * @return list<int>
     */
    private function documentIdsIn(array $layout): array
    {
        $ids = [];

        foreach ($layout as $key => $value) {
            $name = is_string($key) ? mb_strtolower($key) : '';

            if (str_ends_with($name, 'mediaids') && is_array($value)) {
                foreach ($value as $mediaId) {
                    if (is_int($mediaId) && $mediaId > 0) {
                        $ids[] = $mediaId;
                    }
                }

                continue;
            }

            if (str_ends_with($name, 'mediaid') && is_int($value) && $value > 0) {
                $ids[] = $value;

                continue;
            }

            if (is_array($value)) {
                foreach ($this->documentIdsIn($value) as $nested) {
                    $ids[] = $nested;
                }
            }
        }

        return $ids;
    }
}
