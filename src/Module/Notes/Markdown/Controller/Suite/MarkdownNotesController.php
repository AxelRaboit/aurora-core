<?php

declare(strict_types=1);

namespace Aurora\Module\Notes\Markdown\Controller\Suite;

use Aurora\Core\Enum\HttpMethodEnum;
use Aurora\Core\Enum\HttpStatusEnum;
use Aurora\Core\Http\JsonRequestTrait;
use Aurora\Core\Http\JsonResponseTrait;
use Aurora\Core\Storage\Access\UploadPolicyProvider;
use Aurora\Core\Storage\Access\UploadRefusalEnum;
use Aurora\Core\Validation\Service\PayloadValidator;
use Aurora\Module\Configuration\Setting\Service\SiteDateFormatter;
use Aurora\Module\Ged\Pexels\Service\PexelsClient;
use Aurora\Module\Notes\Favorite\Manager\NoteFavoriteManagerInterface;
use Aurora\Module\Notes\Folder\Entity\NoteFolderInterface;
use Aurora\Module\Notes\Folder\Repository\NoteFolderRepository;
use Aurora\Module\Notes\Live\Service\NoteLiveHub;
use Aurora\Module\Notes\Markdown\Dto\MarkdownNoteInputFactoryInterface;
use Aurora\Module\Notes\Markdown\Dto\MarkdownNoteReorderInputFactoryInterface;
use Aurora\Module\Notes\Markdown\Entity\MarkdownNoteInterface;
use Aurora\Module\Notes\Markdown\Entity\MarkdownNoteRevision;
use Aurora\Module\Notes\Markdown\Manager\MarkdownNoteManagerInterface;
use Aurora\Module\Notes\Markdown\Repository\MarkdownNoteRepository;
use Aurora\Module\Notes\Markdown\Repository\MarkdownNoteRevisionRepository;
use Aurora\Module\Notes\Markdown\Serializer\MarkdownNoteSerializerInterface;
use Aurora\Module\Notes\Markdown\Service\MarkdownDailyNote;
use Aurora\Module\Notes\Markdown\Service\MarkdownNoteArchive;
use Aurora\Module\Notes\Markdown\Service\MarkdownNoteHistory;
use Aurora\Module\Notes\Markdown\Service\MarkdownNoteImporter;
use Aurora\Module\Notes\Markdown\View\MarkdownNotesViewBuilder;
use Aurora\Module\Notes\Share\Repository\MarkdownNoteMemberRepository;
use Aurora\Module\Notes\Space\Entity\NoteSpaceInterface;
use Aurora\Module\Notes\Space\Service\NoteSpaceAccess;
use Aurora\Module\Platform\User\Entity\CoreUserInterface;
use DateTimeImmutable;
use DateTimeInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

use function array_filter;
use function array_values;
use function iconv;
use function is_array;
use function is_numeric;
use function preg_replace;

#[Route('/suite/notes/markdown', name: 'suite_notes_markdown')]
#[IsGranted('notes.markdown.use')]
final class MarkdownNotesController extends AbstractController
{
    use JsonRequestTrait;
    use JsonResponseTrait;

    public function __construct(
        private readonly MarkdownNoteSerializerInterface $serializer,
        private readonly MarkdownNoteManagerInterface $manager,
        private readonly MarkdownNoteRepository $repository,
        private readonly MarkdownNoteInputFactoryInterface $inputFactory,
        private readonly MarkdownNoteReorderInputFactoryInterface $reorderInputFactory,
        private readonly PayloadValidator $payloadValidator,
        private readonly MarkdownNotesViewBuilder $viewBuilder,
        private readonly NoteFolderRepository $folderRepository,
        private readonly MarkdownNoteArchive $archive,
        private readonly MarkdownNoteImporter $importer,
        private readonly UploadPolicyProvider $uploadPolicyProvider,
        private readonly NoteSpaceAccess $spaceAccess,
        private readonly NoteFavoriteManagerInterface $favorites,
        private readonly TranslatorInterface $translator,
        private readonly SiteDateFormatter $dateFormatter,
        private readonly MarkdownNoteHistory $history,
        private readonly MarkdownNoteRevisionRepository $markdownNoteRevisionRepository,
        private readonly MarkdownNoteMemberRepository $memberRepository,
        private readonly NoteLiveHub $liveHub,
    ) {}

    /**
     * All documents: the notebook, at its root.
     *
     * The address used to render the first note, for lack of a page to show.
     * The notebook now has its own, and that is what greets you: what you
     * look for on arrival is most often a note you do not have in front of
     * you, not the one you opened last.
     */
    #[Route('', name: '', methods: [HttpMethodEnum::Get->value])]
    public function index(): Response
    {
        /** @var CoreUserInterface $user */
        $user = $this->getUser();

        return $this->render('@Notes/suite/markdown/index.html.twig', $this->viewBuilder->indexView($user));
    }

    /**
     * The content of a folder, at its address.
     *
     * One address per folder, like one address per note: it can be passed
     * on, a middle click opens a tab, and the breadcrumb is computed on the
     * server side so that a reload does not show the root for a fraction of
     * a second.
     */
    #[Route('/folder/{id}', name: '_folder', requirements: ['id' => '\d+|__id__'], methods: [HttpMethodEnum::Get->value])]
    public function folder(int $id): Response
    {
        /** @var CoreUserInterface $user */
        $user = $this->getUser();

        // A folder of a space you can read.
        $folder = $this->spaceAccess->readableFolder($user, $id);

        if (!$folder instanceof NoteFolderInterface) {
            throw $this->createNotFoundException();
        }

        return $this->render(
            '@Notes/suite/markdown/index.html.twig',
            $this->viewBuilder->indexView($user, folder: $folder),
        );
    }

    /**
     * All of the person's notes, flat, without their text.
     *
     * With their first paragraph all the same: it is what the grid view shows
     * on a card, and doing it here avoids one query per card. The rest of the
     * body does not leave the server until a note is opened.
     */
    #[Route('/list', name: '_list', methods: [HttpMethodEnum::Get->value])]
    public function list(): JsonResponse
    {
        /** @var CoreUserInterface $user */
        $user = $this->getUser();

        $excerpts = $this->repository->findExcerptsForUser($user);

        return $this->jsonSuccess([
            'notes' => array_map(
                static fn (array $note): array => [...$note, 'excerpt' => $excerpts[(int) $note['id']] ?? null],
                $this->repository->findFlatListForUser($user),
            ),
            // Travels with the list, like the list travels with the page: the
            // menu panel reloads through here, and without the roles it could
            // not tell a note handed over on its own from one of its own
            // spaces.
            'sharedNotes' => $this->memberRepository->findRolesFor($user),
        ]);
    }

    /**
     * The note, alone, without the back office around it.
     *
     * A separate address rather than a display mode: you keep it open in a
     * tab, you share it with yourself, and the browser comes back to it. The
     * public share template draws it - it is already made to render a note
     * without menu or breadcrumb - but read by its owner: images go through
     * the ordinary route, and a wiki link leads to this same view rather than
     * being neutralized, since the whole notebook is within reach.
     */
    // `__id__` is allowed so that the page receives an address template to
    // fill rather than one address per note: it arrives here as zero, which
    // belongs to nobody, so it answers 404 like any unknown id.
    /**
     * Enter the reader without choosing a note: through a browser bookmark
     * or through the shortcut. You land on the first note of the notebook;
     * an empty notebook sends you to the library, where you write one.
     */
    #[Route('/read', name: '_read_entry', methods: [HttpMethodEnum::Get->value])]
    public function readEntry(): Response
    {
        /** @var CoreUserInterface $user */
        $user = $this->getUser();

        $first = $this->viewBuilder->firstInReadingOrder($user);

        return $this->redirectToRoute(
            null === $first ? 'suite_notes_markdown' : 'suite_notes_markdown_read',
            null === $first ? [] : ['id' => $first],
        );
    }

    #[Route('/{id}/read', name: '_read', requirements: ['id' => '\d+|__id__'], methods: [HttpMethodEnum::Get->value])]
    public function read(int $id): Response
    {
        /** @var CoreUserInterface $user */
        $user = $this->getUser();

        // Everything the note's space lets you read; writing is decided
        // elsewhere, by the role.
        $note = $this->spaceAccess->readableNote($user, $id);

        if (!$note instanceof MarkdownNoteInterface) {
            throw $this->createNotFoundException();
        }

        return $this->render(
            '@Notes/suite/markdown/read.html.twig',
            $this->viewBuilder->readView($user, $note),
        );
    }

    /**
     * Photos for a note's banner, searched on Pexels.
     *
     * A relay and not a direct call: the key stays on the server, as for the
     * media library picker. But this relay stops there - **nothing is
     * downloaded, nothing goes into the GED**. The note only keeps the image's
     * address and its author's credit, and if the photo disappears from their
     * side one day, you pick another one.
     *
     * Its own route rather than the media library one: that one requires the
     * `ged.documents.view` right, which someone taking notes does not
     * necessarily have, and asking them to get it to pick a decorative image
     * would be one right too many.
     */
    #[Route('/covers/search', name: '_covers_search', methods: [HttpMethodEnum::Get->value])]
    public function searchCovers(Request $request, PexelsClient $pexels): JsonResponse
    {
        // Announced rather than failed: the picker shows "not configured",
        // which tells an administrator what to do. A 500 would only say
        // "something broke".
        if (!$pexels->isConfigured()) {
            return $this->jsonSuccess(['configured' => false, 'results' => []]);
        }

        $result = $pexels->search(
            (string) $request->query->get('q', ''),
            $request->query->getInt('page', 1),
        );

        return $this->jsonSuccess([
            'configured' => true,
            'results' => $result['results'],
            'totalPages' => $result['totalPages'],
        ]);
    }

    #[Route('/{id}/restore', name: '_restore', methods: [HttpMethodEnum::Post->value])]
    public function restore(int $id): JsonResponse
    {
        /** @var CoreUserInterface $user */
        $user = $this->getUser();

        $note = $this->spaceAccess->administrableNote($user, $id);
        if (!$note instanceof MarkdownNoteInterface) {
            return $this->jsonNotFound();
        }

        $this->manager->restore($note);

        return $this->jsonSuccess();
    }

    #[Route('/{id}/force-delete', name: '_force_delete', methods: [HttpMethodEnum::Post->value])]
    public function forceDelete(int $id): JsonResponse
    {
        /** @var CoreUserInterface $user */
        $user = $this->getUser();

        $note = $this->spaceAccess->administrableNote($user, $id);
        if (!$note instanceof MarkdownNoteInterface) {
            return $this->jsonNotFound();
        }

        $this->manager->forceDelete($note);

        return $this->jsonSuccess();
    }

    /**
     * Destroys every note this user has in the trash, sub-pages included.
     *
     * Only theirs: a note belongs to its author, and there is no view in which
     * emptying one person's trash should reach another's.
     */
    #[Route('/empty-trash', name: '_empty_trash', methods: [HttpMethodEnum::Post->value])]
    public function emptyTrash(): JsonResponse
    {
        /** @var CoreUserInterface $user */
        $user = $this->getUser();

        $deleted = 0;
        // Emptying a trash is final: only in the spaces you manage. The
        // editor restores, they do not destroy.
        foreach ($this->repository->findTrashedRootsForUser($user) as $note) {
            if (!$this->spaceAccess->canManage($user, $note->getSpace())) {
                continue;
            }

            $this->manager->forceDelete($note);
            ++$deleted;
        }

        return $this->jsonSuccess(['deleted' => $deleted]);
    }

    /**
     * The whole notebook, in Markdown, in a zip.
     *
     * **The way out.** A notebook in a database is a trap as long as you
     * cannot take it back: this returns `.md` files that a text editor opens
     * and Obsidian reads, in the notes tree, with the tags in front matter.
     * Nothing in it is specific to Aurora.
     *
     * The temporary file is deleted after sending: `deleteFileAfterSend` does
     * it once the response is written, not before, otherwise a big notebook
     * goes out into the void.
     */
    #[Route('/export', name: '_export', methods: [HttpMethodEnum::Get->value])]
    public function export(Request $request): Response
    {
        /** @var CoreUserInterface $user */
        $user = $this->getUser();

        // `spaceId` only takes that space, `folderId` that folder, if it is
        // readable: whoever reads a space can take it away, since they can
        // already read and copy all of it.
        $root = null;
        $spaceId = $request->query->get('spaceId');
        $folderId = $request->query->get('folderId');
        if (is_numeric($folderId)) {
            $root = $this->spaceAccess->readableFolder($user, (int) $folderId);
        } elseif (is_numeric($spaceId)) {
            $root = $this->spaceAccess->readableSpace($user, (int) $spaceId);
        }

        if ((is_numeric($folderId) || is_numeric($spaceId)) && null === $root) {
            throw $this->createNotFoundException();
        }

        $path = $this->archive->zipFor($user, $root);

        return $this->file($path, $this->archive->fileNameFor($root))->deleteFileAfterSend(true);
    }

    /** A single note, to take it away without taking the rest. */
    // `__id__` accepted as on `_show`: the view receives an address template
    // in which it substitutes the id, so the generator must be able to
    // produce the address with the marker in it.
    #[Route('/{id}/export', name: '_export_one', requirements: ['id' => '\d+|__id__'], methods: [HttpMethodEnum::Get->value])]
    public function exportOne(int $id): Response
    {
        /** @var CoreUserInterface $user */
        $user = $this->getUser();

        // Taking a note away means reading it: the team's notes can be taken too.
        $note = $this->spaceAccess->readableNote($user, $id);

        if (!$note instanceof MarkdownNoteInterface) {
            throw $this->createNotFoundException();
        }

        $response = new Response($this->archive->fileFor($note));
        $response->headers->set('Content-Type', 'text/markdown; charset=UTF-8');
        // An ASCII fallback is required: a note's title is written by
        // someone, so it has accents, and `makeDisposition` refuses to guess
        // one on its own. The accented name stays, in the parameter browsers
        // have read for fifteen years.
        $name = $this->archive->nameOf($note).'.md';

        $response->headers->set(
            'Content-Disposition',
            $response->headers->makeDisposition(
                ResponseHeaderBag::DISPOSITION_ATTACHMENT,
                $name,
                $this->asciiName($name),
            ),
        );

        return $response;
    }

    /**
     * Markdown files, or a zip, turned back into notes.
     *
     * In the named folder, or at the root. Nothing is overwritten: a note
     * with the same name gives a second note, because merging would mean
     * deciding what wins and nobody asked for that here.
     */
    #[Route('/import', name: '_import', methods: [HttpMethodEnum::Post->value])]
    public function import(Request $request): JsonResponse
    {
        /** @var CoreUserInterface $user */
        $user = $this->getUser();

        $files = $request->files->all()['files'] ?? [];
        $files = is_array($files) ? $files : [$files];
        $files = array_values(array_filter($files, static fn (mixed $file): bool => $file instanceof UploadedFile));

        if ([] === $files) {
            return $this->jsonInvalidInput(['files' => 'notes.markdown.import.errors.required']);
        }

        $folderId = $request->request->get('folderId');
        $folder = null;

        if (is_numeric($folderId)) {
            $folder = $this->folderRepository->findOneByUserAndId($user, (int) $folderId);

            if (!$folder instanceof NoteFolderInterface) {
                return $this->jsonNotFound();
            }
        }

        // Without a folder, the root of a space you write in; your own otherwise.
        $space = null;
        $spaceId = $request->request->get('spaceId');
        if (!$folder instanceof NoteFolderInterface && is_numeric($spaceId)) {
            $space = $this->spaceAccess->writableSpace($user, (int) $spaceId);

            if (!$space instanceof NoteSpaceInterface) {
                return $this->jsonNotFound();
            }
        }

        $created = 0;

        foreach ($files as $file) {
            $refusal = $this->uploadPolicyProvider->forStaffDocuments()->refusalFor($file);

            if ($refusal instanceof UploadRefusalEnum) {
                return $this->jsonInvalidInput(['files' => match ($refusal) {
                    UploadRefusalEnum::TooLarge => 'suite.ged.documents.errors.upload_too_large',
                    UploadRefusalEnum::TypeRefused => 'suite.ged.documents.errors.upload_type_refused',
                    UploadRefusalEnum::Broken => 'suite.ged.documents.errors.upload_failed',
                }]);
            }

            $created += $this->importer->import($user, $file, $folder, $space);
        }

        return $this->jsonSuccess(['created' => $created]);
    }

    /**
     * The same name, reduced to what an old client can read.
     *
     * Transliterated rather than truncated: "Séance en extérieur" becomes
     * "Seance en exterieur" and stays recognizable, where a blunt filter
     * would return "S ance en ext rieur".
     */
    private function asciiName(string $name): string
    {
        $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT', $name);

        if (false === $ascii) {
            $ascii = $name;
        }

        return (string) preg_replace('/[^\x20-\x7E]+/', '-', $ascii);
    }

    #[Route('/create', name: '_create', methods: [HttpMethodEnum::Post->value])]
    public function create(Request $request): JsonResponse
    {
        /** @var CoreUserInterface $user */
        $user = $this->getUser();

        $input = $this->inputFactory->fromArray($this->decodeJson($request));

        // Where you create, you must be able to write: the requested folder,
        // the root of the requested space, or your personal space.
        $folderId = $input->getFolderId();
        $spaceId = $input->getSpaceId();
        $allowed = match (true) {
            null !== $folderId => $this->spaceAccess->writableFolder($user, $folderId) instanceof NoteFolderInterface,
            null !== $spaceId => $this->spaceAccess->writableSpace($user, $spaceId) instanceof NoteSpaceInterface,
            default => true,
        };
        if (!$allowed) {
            return $this->jsonNotFound();
        }

        $errors = $this->payloadValidator->errors($input);
        if ([] !== $errors) {
            return $this->jsonInvalidInput($errors);
        }

        $note = $this->manager->create($user, $input);

        return $this->jsonSuccess(['note' => $this->serializer->serializeDetail($note)]);
    }

    #[Route('/{id}/update', name: '_update', methods: [HttpMethodEnum::Post->value])]
    public function update(int $id, Request $request): JsonResponse
    {
        /** @var CoreUserInterface $user */
        $user = $this->getUser();

        $note = $this->spaceAccess->writableNote($user, $id);
        if (!$note instanceof MarkdownNoteInterface) {
            return $this->jsonNotFound();
        }

        $input = $this->inputFactory->fromArray($this->decodeJson($request));

        // Started from an outdated version: someone wrote in the meantime,
        // and saving now would erase their text without them knowing. We
        // refuse - and hand back **the note as it stands**, so the page can
        // try to put the two texts together instead of asking somebody to
        // choose between them. Only when that fails does the person choose.
        // A call that does not state its version goes through, as before.
        if (!$input->isForce() && null !== $input->getVersion() && $input->getVersion() !== $note->getVersion()) {
            return $this->jsonFailure('conflict', HttpStatusEnum::Conflict->value, [
                'conflict' => true,
                'version' => $note->getVersion(),
                'note' => $this->serializer->serializeDetail($note),
            ]);
        }

        $errors = $this->payloadValidator->errors($input);
        if ([] !== $errors) {
            return $this->jsonInvalidInput($errors);
        }

        // The state about to be replaced becomes a version, if the last one
        // is old enough (settings > Notes): a way to go back.
        $this->history->beforeChange($note, $input->getTitle(), $input->getContent(), $user);

        $this->manager->update($note, $input);

        // Told to whoever else has the note open, after the row is committed
        // and never before: a hub that is down must not be able to fail a
        // save. The version only - the page asks for the note itself the
        // ordinary way once it knows it is behind.
        $this->liveHub->publishChanged($note, $user->getName());

        // The excerpt travels with the saved note: the library card follows
        // the text without waiting for a reload.
        $excerpt = $this->repository->excerptOf((string) $note->getContent());
        $serializer = $this->serializer->withFavorites($this->favorites->mapFor($user)['notes']);
        if ('' !== $excerpt) {
            $serializer = $serializer->withExcerpts([(int) $note->getId() => $excerpt]);
        }

        return $this->jsonSuccess(['note' => $serializer->serializeDetail($note)]);
    }

    #[Route('/{id}/delete', name: '_delete', methods: [HttpMethodEnum::Post->value])]
    public function delete(int $id): JsonResponse
    {
        /** @var CoreUserInterface $user */
        $user = $this->getUser();

        $note = $this->spaceAccess->administrableNote($user, $id);
        if (!$note instanceof MarkdownNoteInterface) {
            return $this->jsonNotFound();
        }

        $this->manager->delete($note);

        return $this->jsonSuccess();
    }

    /** The past versions of a note you can read, most recent first. */
    #[Route('/{id}/revisions', name: '_revisions', methods: [HttpMethodEnum::Get->value])]
    public function revisions(int $id): JsonResponse
    {
        /** @var CoreUserInterface $user */
        $user = $this->getUser();

        $note = $this->spaceAccess->readableNote($user, $id);
        if (!$note instanceof MarkdownNoteInterface) {
            return $this->jsonNotFound();
        }

        // Who may be told which link a version came through. The history is
        // open to anybody the note is open to - a member of its space,
        // somebody it was handed to as a reader - and a link's recipient
        // address belongs to whoever created the link, not to them. Those who
        // administer the note already read that address on the share screen.
        $namesTheLink = $this->spaceAccess->canAdministerNote($user, $note);

        // A guest of a live link wrote alongside: said in this reader's
        // language, the way the room says it.
        $guestLabel = $this->translator->trans('notes.markdown.live.guest');

        return $this->jsonSuccess(['revisions' => array_map(
            static fn (MarkdownNoteRevision $revision): array => [
                'id' => $revision->getId(),
                'createdAt' => $revision->getCreatedAt()->format(DateTimeInterface::ATOM),
                'authorName' => $revision->getAuthor()?->getName()
                    ?? ($namesTheLink ? $revision->getLinkLabel() : null),
                // So the screen can say "through a share link" rather than
                // leaving a version with no author at all, which reads like a
                // gap in the record.
                'viaShareLink' => $revision->wasWrittenThroughLink(),
                // The names of everybody who was writing, when several were.
                // Open to anybody the history is open to, like `authorName`
                // and for the same reason: these are colleagues of the note's
                // space, not an outside address somebody was mailed at.
                'writtenBy' => array_values(array_filter(array_map(
                    static fn (array $hand): ?string => $hand['name'] ?? (true === ($hand['guest'] ?? false) ? $guestLabel : null),
                    $revision->getWrittenBy(),
                ))),
                'title' => $revision->getTitle(),
            ],
            $this->markdownNoteRevisionRepository->findForNote($note),
        )]);
    }

    /** A past version, its title and its text. */
    #[Route('/{id}/revisions/{revisionId}', name: '_revision', requirements: ['revisionId' => '\d+|__revisionId__'], methods: [HttpMethodEnum::Get->value])]
    public function revision(int $id, int $revisionId): JsonResponse
    {
        /** @var CoreUserInterface $user */
        $user = $this->getUser();

        $note = $this->spaceAccess->readableNote($user, $id);
        $revision = $note instanceof MarkdownNoteInterface ? $this->markdownNoteRevisionRepository->findOneForNote($note, $revisionId) : null;
        if (!$revision instanceof MarkdownNoteRevision) {
            return $this->jsonNotFound();
        }

        return $this->jsonSuccess(['revision' => [
            'id' => $revision->getId(),
            'createdAt' => $revision->getCreatedAt()->format(DateTimeInterface::ATOM),
            'authorName' => $revision->getAuthor()?->getName(),
            'title' => $revision->getTitle(),
            'content' => $revision->getContent(),
        ]]);
    }

    /**
     * Go back to a version: the current state first becomes a version in
     * turn, so that restoring loses nothing. You must be able to write the
     * note.
     */
    #[Route('/{id}/revisions/{revisionId}/restore', name: '_revision_restore', requirements: ['revisionId' => '\d+|__revisionId__'], methods: [HttpMethodEnum::Post->value])]
    public function restoreRevision(int $id, int $revisionId): JsonResponse
    {
        /** @var CoreUserInterface $user */
        $user = $this->getUser();

        $note = $this->spaceAccess->writableNote($user, $id);
        $revision = $note instanceof MarkdownNoteInterface ? $this->markdownNoteRevisionRepository->findOneForNote($note, $revisionId) : null;
        if (!$note instanceof MarkdownNoteInterface || !$revision instanceof MarkdownNoteRevision) {
            return $this->jsonNotFound();
        }

        $this->history->restore($note, $revision, $user);

        return $this->jsonSuccess(['note' => $this->serializer->serializeDetail($note)]);
    }

    /**
     * A copy of the note, right below it: same folder, same text, same
     * appearance, named "Copie de …". You must be able to read the note and
     * write where it is filed.
     */
    #[Route('/{id}/duplicate', name: '_duplicate', methods: [HttpMethodEnum::Post->value])]
    public function duplicate(int $id): JsonResponse
    {
        /** @var CoreUserInterface $user */
        $user = $this->getUser();

        $note = $this->spaceAccess->administrableNote($user, $id);
        if (!$note instanceof MarkdownNoteInterface || $note->isTrashed()) {
            return $this->jsonNotFound();
        }

        $title = $this->translator->trans('notes.markdown.duplicate.title', ['{title}' => $note->getTitle() ?? '']);
        $copy = $this->manager->duplicate($user, $note, $title);

        return $this->jsonSuccess(['note' => $this->serializer->serializeDetail($copy)]);
    }

    /** Make a note a template, or turn it back into an ordinary one. */
    #[Route('/{id}/template', name: '_template', methods: [HttpMethodEnum::Post->value])]
    public function template(int $id, Request $request): JsonResponse
    {
        /** @var CoreUserInterface $user */
        $user = $this->getUser();

        $note = $this->spaceAccess->administrableNote($user, $id);
        if (!$note instanceof MarkdownNoteInterface) {
            return $this->jsonNotFound();
        }

        $this->manager->markTemplate($note, true === ($this->decodeJson($request)['template'] ?? false));

        return $this->jsonSuccess(['note' => $this->serializer->serializeDetail($note)]);
    }

    /**
     * A new note from a template you can read, filed where you can write:
     * the requested folder, the root of the requested space, or your personal
     * space. `{{date}}` becomes today's date in it.
     */
    #[Route('/from-template/{id}', name: '_from_template', methods: [HttpMethodEnum::Post->value])]
    public function fromTemplate(int $id, Request $request): JsonResponse
    {
        /** @var CoreUserInterface $user */
        $user = $this->getUser();

        $template = $this->spaceAccess->readableNote($user, $id);
        if (!$template instanceof MarkdownNoteInterface || !$template->isTemplate() || $template->isTrashed()) {
            return $this->jsonNotFound();
        }

        $data = $this->decodeJson($request);
        $folder = null;
        $space = null;
        if (isset($data['folderId']) && is_numeric($data['folderId'])) {
            $folder = $this->spaceAccess->writableFolder($user, (int) $data['folderId']);
            if (!$folder instanceof NoteFolderInterface || $folder->isTrashed()) {
                return $this->jsonNotFound();
            }

            $space = $folder->getSpace();
        } elseif (isset($data['spaceId']) && is_numeric($data['spaceId'])) {
            $space = $this->spaceAccess->writableSpace($user, (int) $data['spaceId']);
            if (!$space instanceof NoteSpaceInterface) {
                return $this->jsonNotFound();
            }
        }

        $space ??= $this->spaceAccess->personalSpace($user);

        $title = mb_trim((string) ($data['title'] ?? ''));
        $note = $this->manager->createFromTemplate(
            $user,
            $template,
            $folder,
            $space,
            '' !== $title ? $title : $template->getTitle() ?? '',
            ['{{date}}' => $this->dateFormatter->date(new DateTimeImmutable())],
        );

        return $this->jsonSuccess(['note' => $this->serializer->serializeDetail($note)]);
    }

    /**
     * Today's note, in the "Journal" folder of your personal space: the one
     * already written today, or a new one (from the "Note du jour" template
     * when you can read one). Nothing to check beyond the module's right:
     * it only ever writes into your own space.
     */
    #[Route('/daily', name: '_daily', methods: [HttpMethodEnum::Post->value])]
    public function daily(MarkdownDailyNote $dailyNote): JsonResponse
    {
        /** @var CoreUserInterface $user */
        $user = $this->getUser();

        return $this->jsonSuccess(['note' => $this->serializer->serializeDetail($dailyNote->open($user))]);
    }

    #[Route('/{id}/move', name: '_move', methods: [HttpMethodEnum::Post->value])]
    public function move(int $id, Request $request): JsonResponse
    {
        /** @var CoreUserInterface $user */
        $user = $this->getUser();

        $note = $this->spaceAccess->administrableNote($user, $id);
        if (!$note instanceof MarkdownNoteInterface) {
            return $this->jsonNotFound();
        }

        $data = $this->decodeJson($request);
        $raw = $data['folderId'] ?? null;

        // A folder you can write in, or the root of a space you can write
        // in: `spaceId` says which, and defaults to the one where the note
        // already is.
        $folder = null;
        $space = $note->getSpace();
        if (null !== $raw && '' !== $raw) {
            $folder = $this->spaceAccess->writableFolder($user, (int) $raw);
            if (!$folder instanceof NoteFolderInterface || $folder->isTrashed()) {
                return $this->jsonNotFound();
            }
        } elseif (isset($data['spaceId']) && is_numeric($data['spaceId'])) {
            $space = $this->spaceAccess->writableSpace($user, (int) $data['spaceId']);
            if (!$space instanceof NoteSpaceInterface) {
                return $this->jsonNotFound();
            }
        }

        $this->manager->move($note, $folder, $space);

        return $this->jsonSuccess(['note' => $this->serializer->withFavorites($this->favorites->mapFor($user)['notes'])->serializeListItem($note)]);
    }

    /**
     * Add a note to your favorites, or remove it.
     *
     * Reading is enough: favorites belong to the person, not to the note.
     */
    #[Route('/{id}/favorite', name: '_favorite', requirements: ['id' => '\d+|__id__'], methods: [HttpMethodEnum::Post->value])]
    public function favorite(int $id): JsonResponse
    {
        /** @var CoreUserInterface $user */
        $user = $this->getUser();

        $note = $this->spaceAccess->readableNote($user, $id);
        if (!$note instanceof MarkdownNoteInterface) {
            return $this->jsonNotFound();
        }

        return $this->jsonSuccess(['favorite' => $this->favorites->toggle($user, $note)]);
    }

    #[Route('/{id}/backlinks', name: '_backlinks', methods: [HttpMethodEnum::Get->value])]
    public function backlinks(int $id): JsonResponse
    {
        /** @var CoreUserInterface $user */
        $user = $this->getUser();

        $note = $this->spaceAccess->readableNote($user, $id);
        if (!$note instanceof MarkdownNoteInterface) {
            return $this->jsonNotFound();
        }

        return $this->jsonSuccess(['backlinks' => $this->manager->backlinks($user, $note)]);
    }

    #[Route('/{id}/unlinked-mentions', name: '_unlinked_mentions', methods: [HttpMethodEnum::Get->value])]
    public function unlinkedMentions(int $id): JsonResponse
    {
        /** @var CoreUserInterface $user */
        $user = $this->getUser();

        $note = $this->spaceAccess->readableNote($user, $id);
        if (!$note instanceof MarkdownNoteInterface) {
            return $this->jsonNotFound();
        }

        return $this->jsonSuccess(['mentions' => $this->manager->unlinkedMentions($user, $note)]);
    }

    #[Route('/graph', name: '_graph', methods: [HttpMethodEnum::Get->value])]
    public function graph(): JsonResponse
    {
        /** @var CoreUserInterface $user */
        $user = $this->getUser();

        return $this->jsonSuccess($this->manager->graph($user));
    }

    #[Route('/search', name: '_search', methods: [HttpMethodEnum::Get->value])]
    public function search(Request $request): JsonResponse
    {
        /** @var CoreUserInterface $user */
        $user = $this->getUser();
        $query = (string) $request->query->get('q', '');

        return $this->jsonSuccess(['ids' => $this->manager->searchContent($user, $query)]);
    }

    #[Route('/reorder', name: '_reorder', methods: [HttpMethodEnum::Post->value])]
    public function reorder(Request $request): JsonResponse
    {
        /** @var CoreUserInterface $user */
        $user = $this->getUser();

        $input = $this->reorderInputFactory->fromArray($this->decodeJson($request));

        $this->manager->reorder($user, $input->entries);

        return $this->jsonSuccess();
    }

    /**
     * Declared after the static GET routes (/list, /graph) so the router
     * matches those first - otherwise /{id} with id="graph" would shadow them.
     */
    /**
     * One address, two answers: the note's content for the page's own XHR, and
     * the whole page for somebody arriving from a link or the side menu.
     *
     * `X-Requested-With` is the contract - the same one `AuditController` uses,
     * and the reason `convention_no_raw_fetch` exists: every call through
     * `useRequest` sends it, so the JSON callers here are unaffected while a
     * plain navigation now gets a page instead of a JSON blob.
     */
    #[Route('/{id}', name: '_show', requirements: ['id' => '\d+|__id__'], methods: [HttpMethodEnum::Get->value])]
    public function show(int $id, Request $request): Response
    {
        /** @var CoreUserInterface $user */
        $user = $this->getUser();

        // The editor opens on what you can write. A note you can read
        // without writing it - the team without the right, a colleague's
        // share - opens in the reader, which is made for that.
        $note = $this->spaceAccess->writableNote($user, $id);
        if ($note instanceof MarkdownNoteInterface && $note->isTrashed()) {
            $note = null;
        }

        if (!$request->isXmlHttpRequest()) {
            if (!$note instanceof MarkdownNoteInterface) {
                if ($this->spaceAccess->readableNote($user, $id) instanceof MarkdownNoteInterface) {
                    return $this->redirectToRoute('suite_notes_markdown_read', ['id' => $id]);
                }

                throw $this->createNotFoundException();
            }

            // The note's folder travels with it: the breadcrumb shows it,
            // and going back to the library returns to where the note is
            // filed rather than to the root.
            return $this->render(
                '@Notes/suite/markdown/index.html.twig',
                $this->viewBuilder->indexView($user, $id, $note->getFolder()),
            );
        }

        if (!$note instanceof MarkdownNoteInterface) {
            return $this->jsonNotFound();
        }

        return $this->jsonSuccess(['note' => $this->serializer->serializeDetail($note)]);
    }
}
