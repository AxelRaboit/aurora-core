<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\Deck\Controller;

use Aurora\Core\Enum\HttpMethodEnum;
use Aurora\Core\Http\JsonResponseTrait;
use Aurora\Core\Storage\Access\UploadPolicyProvider;
use Aurora\Core\Storage\Access\UploadRefusalEnum;
use Aurora\Module\Ged\Document\Service\InlineImageUploader;
use Aurora\Module\Studio\Deck\Service\DeckFonts;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The fonts a free slide may be set in, listed and uploaded from a deck.
 *
 * Served to readers by `DeliverableFontsController`, under the deliverables:
 * a presentation is read through a deliverable's link, and its fonts must
 * answer whatever the decks toggle says.
 */
final class DeckFontsController extends AbstractController
{
    use JsonResponseTrait;

    public function __construct(
        private readonly DeckFonts $fonts,
        private readonly InlineImageUploader $uploader,
        private readonly UploadPolicyProvider $uploadPolicies,
    ) {}

    /**
     * The uploaded fonts, for the picker.
     */
    #[Route('/suite/studio/decks/fonts', name: 'suite_studio_deck_fonts', methods: [HttpMethodEnum::Get->value], priority: 10)]
    #[IsGranted('studio.decks.view')]
    public function list(): JsonResponse
    {
        return $this->jsonSuccess(['fonts' => $this->fonts->all()]);
    }

    /**
     * A font file, filed in the library and offered at once.
     *
     * Two privileges: editing decks, which is what the person is doing, and
     * creating documents, since the file becomes one. The library's own upload
     * asks for the second, and a font must not be a way round it.
     */
    #[Route('/suite/studio/decks/fonts/upload', name: 'suite_studio_deck_font_upload', methods: [HttpMethodEnum::Post->value], priority: 10)]
    #[IsGranted('studio.decks.edit')]
    #[IsGranted('ged.documents.create')]
    public function upload(Request $request): JsonResponse
    {
        /** @var UploadedFile|null $file */
        $file = $request->files->get('file');

        if (null === $file) {
            return $this->jsonFailure('suite.studio.decks.free.font_errors.required');
        }

        if (!$this->fonts->isFontFile($file, $file->getClientOriginalName())) {
            return $this->jsonFailure('suite.studio.decks.free.font_errors.not_a_font');
        }

        if ($this->uploadPolicies->forStaffDocuments()->refusalFor($file) instanceof UploadRefusalEnum) {
            return $this->jsonFailure('suite.studio.decks.free.font_errors.refused');
        }

        return $this->jsonSuccess(['font' => $this->fonts->describe($this->uploader->upload($file))]);
    }
}
