<?php

declare(strict_types=1);

namespace Aurora\Module\Notes\Markdown\Controller\Backend;

use Aurora\Core\Enum\HttpMethodEnum;
use Aurora\Core\Http\JsonRequestTrait;
use Aurora\Core\Http\JsonResponseTrait;
use Aurora\Core\Validation\Service\PayloadValidator;
use Aurora\Module\Notes\Markdown\Dto\TagDeleteInputFactoryInterface;
use Aurora\Module\Notes\Markdown\Dto\TagMergeInputFactoryInterface;
use Aurora\Module\Notes\Markdown\Dto\TagRenameInputFactoryInterface;
use Aurora\Module\Notes\Markdown\Manager\MarkdownNoteManagerInterface;
use Aurora\Module\Notes\Markdown\Serializer\MarkdownNoteSerializerInterface;
use Aurora\Module\Platform\User\Entity\CoreUserInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Tag management endpoints - rename / merge / delete a tag across all the
 * current user's notes, and list the tag → count histogram for the
 * "Manage tags" modal. Split from `MarkdownNotesController` to keep both
 * controllers focused (notes CRUD + sub-flows vs cross-cutting tag ops).
 *
 * Route names preserved : `backend_notes_markdown_tags_list/_rename/_merge/_delete`.
 */
#[Route('/backend/notes/markdown/tags', name: 'backend_notes_markdown_tags')]
#[IsGranted('notes.markdown.use')]
final class MarkdownTagsController extends AbstractController
{
    use JsonRequestTrait;
    use JsonResponseTrait;

    public function __construct(
        private readonly MarkdownNoteManagerInterface $manager,
        private readonly MarkdownNoteSerializerInterface $serializer,
        private readonly PayloadValidator $payloadValidator,
        private readonly TagRenameInputFactoryInterface $tagRenameFactory,
        private readonly TagMergeInputFactoryInterface $tagMergeFactory,
        private readonly TagDeleteInputFactoryInterface $tagDeleteFactory,
    ) {}

    #[Route('', name: '_list', methods: [HttpMethodEnum::Get->value])]
    public function list(): JsonResponse
    {
        /** @var CoreUserInterface $user */
        $user = $this->getUser();

        return $this->jsonSuccess([
            'tags' => $this->serializer->serializeTagCounts($this->manager->tagCounts($user)),
        ]);
    }

    #[Route('/rename', name: '_rename', methods: [HttpMethodEnum::Post->value])]
    public function rename(Request $request): JsonResponse
    {
        /** @var CoreUserInterface $user */
        $user = $this->getUser();

        $input = $this->tagRenameFactory->fromArray($this->decodeJson($request));

        $errors = $this->payloadValidator->errors($input);
        if ([] !== $errors) {
            return $this->jsonInvalidInput($errors);
        }

        return $this->jsonSuccess(['affected' => $this->manager->renameTag($user, $input->oldTag, $input->newTag)]);
    }

    #[Route('/merge', name: '_merge', methods: [HttpMethodEnum::Post->value])]
    public function merge(Request $request): JsonResponse
    {
        /** @var CoreUserInterface $user */
        $user = $this->getUser();

        $input = $this->tagMergeFactory->fromArray($this->decodeJson($request));

        $errors = $this->payloadValidator->errors($input);
        if ([] !== $errors) {
            return $this->jsonInvalidInput($errors);
        }

        return $this->jsonSuccess(['affected' => $this->manager->mergeTags($user, $input->sourceTags, $input->targetTag)]);
    }

    #[Route('/delete', name: '_delete', methods: [HttpMethodEnum::Post->value])]
    public function delete(Request $request): JsonResponse
    {
        /** @var CoreUserInterface $user */
        $user = $this->getUser();

        $input = $this->tagDeleteFactory->fromArray($this->decodeJson($request));

        $errors = $this->payloadValidator->errors($input);
        if ([] !== $errors) {
            return $this->jsonInvalidInput($errors);
        }

        return $this->jsonSuccess(['affected' => $this->manager->removeTag($user, $input->tag)]);
    }
}
