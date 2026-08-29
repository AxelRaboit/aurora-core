<?php

declare(strict_types=1);

namespace Aurora\Module\Notes\Markdown\View;

use Aurora\Core\Support\Num;
use Aurora\Module\Configuration\Setting\Repository\SettingRepository;
use Aurora\Module\Notes\Markdown\Repository\MarkdownNoteRepository;
use Aurora\Module\Notes\Markdown\Setting\MarkdownNoteSettingEnum;
use Aurora\Module\Platform\User\Entity\CoreUserInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final readonly class MarkdownNotesViewBuilder
{
    public function __construct(
        private MarkdownNoteRepository $noteRepository,
        private UrlGeneratorInterface $urlGenerator,
        private SettingRepository $settingRepository,
    ) {}

    /** @return array<string, mixed> */
    public function indexView(CoreUserInterface $user): array
    {
        return [
            'notes' => $this->noteRepository->findFlatListForUser($user),
            'listPath' => $this->urlGenerator->generate('backend_notes_markdown_list'),
            'showPath' => $this->urlGenerator->generate('backend_notes_markdown_show', ['id' => '__id__']),
            'createPath' => $this->urlGenerator->generate('backend_notes_markdown_create'),
            'updatePath' => $this->urlGenerator->generate('backend_notes_markdown_update', ['id' => '__id__']),
            'deletePath' => $this->urlGenerator->generate('backend_notes_markdown_delete', ['id' => '__id__']),
            'movePath' => $this->urlGenerator->generate('backend_notes_markdown_move', ['id' => '__id__']),
            'reorderPath' => $this->urlGenerator->generate('backend_notes_markdown_reorder'),
            'backlinksPath' => $this->urlGenerator->generate('backend_notes_markdown_backlinks', ['id' => '__id__']),
            'unlinkedMentionsPath' => $this->urlGenerator->generate('backend_notes_markdown_unlinked_mentions', ['id' => '__id__']),
            'graphPath' => $this->urlGenerator->generate('backend_notes_markdown_graph'),
            'searchPath' => $this->urlGenerator->generate('backend_notes_markdown_search'),
            'tagsListPath' => $this->urlGenerator->generate('backend_notes_markdown_tags_list'),
            'tagsRenamePath' => $this->urlGenerator->generate('backend_notes_markdown_tags_rename'),
            'tagsMergePath' => $this->urlGenerator->generate('backend_notes_markdown_tags_merge'),
            'tagsDeletePath' => $this->urlGenerator->generate('backend_notes_markdown_tags_delete'),
            'imageUploadPath' => $this->urlGenerator->generate('backend_notes_markdown_images_upload'),
            'imageMaxEdge' => (int) $this->settingRepository->getOrDefault(MarkdownNoteSettingEnum::ImageMaxEdge),
            'imageQuality' => $this->imageQualityRatio(),
        ];
    }

    /**
     * Read the WebP-quality setting (stored as an int percentage so the
     * Settings UI's `int` renderer can edit it) and project it back into
     * the [0..1] float the canvas encoder expects. Delegates to
     * {@see Num::percentToRatio()} for the clamping.
     */
    private function imageQualityRatio(): float
    {
        return Num::percentToRatio(
            (int) $this->settingRepository->getOrDefault(MarkdownNoteSettingEnum::ImageQualityPct),
        );
    }
}
