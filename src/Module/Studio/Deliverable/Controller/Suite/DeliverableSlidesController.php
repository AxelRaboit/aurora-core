<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\Deliverable\Controller\Suite;

use Aurora\Core\Enum\HttpMethodEnum;
use Aurora\Core\Http\JsonRequestTrait;
use Aurora\Core\Http\JsonResponseTrait;
use Aurora\Core\Http\PrivateAddressResponseTrait;
use Aurora\Core\Storage\Access\UploadPolicyProvider;
use Aurora\Core\Storage\Access\UploadRefusalEnum;
use Aurora\Module\Ged\Document\Service\InlineImageUploader;
use Aurora\Module\Studio\Deck\Entity\SlideInterface;
use Aurora\Module\Studio\Deck\Enum\DeckThemeEnum;
use Aurora\Module\Studio\Deck\Enum\SlideLayoutEnum;
use Aurora\Module\Studio\Deck\Serializer\DeckSerializer;
use Aurora\Module\Studio\Deck\Service\DeckFonts;
use Aurora\Module\Studio\Deliverable\Entity\DeliverableInterface;
use Aurora\Module\Studio\Deliverable\Repository\DeliverableRepository;
use Aurora\Module\Studio\Deliverable\Security\DeliverableAccess;
use Aurora\Module\Studio\Deliverable\Slides\SlidesManager;
use Aurora\Module\Studio\Deliverable\View\DeliverableSlidesViewBuilder;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

use function array_filter;
use function array_values;
use function is_array;
use function is_int;
use function is_string;

/**
 * Les diapositives d'un livrable au format diaporama : la page qui les
 * compose, la vue présentateur, l'impression, et les écritures de l'éditeur.
 *
 * Les gestes sont ceux des présentations (`SlidesController`), écrits par le
 * même {@see SlidesManager} ; seule la règle d'accès change : celle des
 * livrables, {@see DeliverableAccess}. Un livrable qu'on ne lit pas, qui vit
 * dans un espace ou qui est une page répond le même 404 qu'un identifiant
 * inconnu ; un livrable qu'on lit sans pouvoir le modifier répond 403 aux
 * écritures.
 *
 * Toute diapositive est cherchée parmi celles du livrable de l'adresse : un
 * identifiant venu d'ailleurs ne s'écrit pas par un livrable qu'on a le droit
 * d'ouvrir.
 */
#[Route('/suite/studio/deliverables/{id}', name: 'suite_studio_deliverables_slides', requirements: ['id' => '\d+'])]
#[IsGranted(DeliverableAccess::VIEW)]
final class DeliverableSlidesController extends AbstractController
{
    use JsonRequestTrait;
    use JsonResponseTrait;
    use PrivateAddressResponseTrait;

    public function __construct(
        private readonly DeliverableRepository $deliverables,
        private readonly DeliverableAccess $access,
        private readonly SlidesManager $slides,
        private readonly DeckSerializer $deckSerializer,
        private readonly DeliverableSlidesViewBuilder $viewBuilder,
        private readonly EntityManagerInterface $entityManager,
        private readonly DeckFonts $fonts,
        private readonly InlineImageUploader $uploader,
        private readonly UploadPolicyProvider $uploadPolicies,
    ) {}

    /**
     * La vue présentateur : les notes de l'orateur, sur un second écran.
     *
     * Derrière un compte, comme tout le back-office : c'est toute la
     * différence avec un lien de lecture, qui retire les notes.
     */
    #[Route('/presenter', name: '_presenter', methods: [HttpMethodEnum::Get->value])]
    public function presenter(int $id): Response
    {
        return $this->privately($this->render('@Studio/suite/decks/presenter.html.twig', [
            'deck' => $this->viewBuilder->deck($this->readable($id)),
        ]));
    }

    /** Le diaporama sur papier ; `?print=1` ouvre la boîte d'impression. */
    #[Route('/print', name: '_print', methods: [HttpMethodEnum::Get->value])]
    public function print(int $id, Request $request): Response
    {
        return $this->privately($this->render('@Studio/suite/decks/print.html.twig', [
            'deck' => $this->viewBuilder->deck($this->readable($id)),
            'autoPrint' => $request->query->getBoolean('print'),
        ]));
    }

    /** Le thème des diapositives et ce qu'elles en retouchent, depuis le panneau de l'éditeur. */
    #[Route('/appearance', name: '_appearance', methods: [HttpMethodEnum::Post->value])]
    public function appearance(int $id, Request $request): JsonResponse
    {
        $deliverable = $this->writable($id);
        $payload = $this->decodeJson($request);

        $theme = DeckThemeEnum::tryFrom(is_string($payload['theme'] ?? null) ? $payload['theme'] : '');
        if (null === $theme) {
            return $this->jsonInvalidInput(['theme' => 'suite.studio.decks.errors.theme_unknown']);
        }

        $this->slides->writeAppearance($deliverable, $theme, is_array($payload['style'] ?? null) ? $payload['style'] : []);
        $deliverable->touch();
        $this->entityManager->flush();

        return $this->jsonSuccess($this->viewBuilder->appearancePayload($deliverable));
    }

    #[Route('/slides/create', name: '_create', methods: [HttpMethodEnum::Post->value])]
    public function create(int $id, Request $request): JsonResponse
    {
        $deliverable = $this->writable($id);
        $payload = $this->decodeJson($request);

        $layout = SlideLayoutEnum::tryFrom(is_string($payload['layout'] ?? null) ? $payload['layout'] : '');
        if (null === $layout) {
            return $this->jsonInvalidInput(['layout' => 'suite.studio.decks.errors.layout_unknown']);
        }

        $slide = $this->slides->addSlide($deliverable, $layout);
        $deliverable->touch();
        $this->entityManager->flush();

        return $this->jsonSuccess(['slide' => $this->deckSerializer->slide($slide)]);
    }

    /**
     * Le contenu d'une diapositive, et sa disposition qui peut changer : le
     * contenu est alors filtré par les cases de la *nouvelle* disposition.
     */
    #[Route('/slides/{slideId}/update', name: '_update', requirements: ['slideId' => '\d+'], methods: [HttpMethodEnum::Post->value])]
    public function update(int $id, int $slideId, Request $request): JsonResponse
    {
        $deliverable = $this->writable($id);
        $slide = $this->slides->slideOf($deliverable, $slideId);
        if (!$slide instanceof SlideInterface) {
            return $this->jsonNotFound();
        }

        $payload = $this->decodeJson($request);

        $layout = SlideLayoutEnum::tryFrom(is_string($payload['layout'] ?? null) ? $payload['layout'] : '');
        if (null !== $layout) {
            $slide->setLayout($layout);
        }

        $this->slides->writeContent($slide, is_array($payload['content'] ?? null) ? $payload['content'] : []);
        $slide->setSpeakerNotes(is_string($payload['speakerNotes'] ?? null) && '' !== $payload['speakerNotes'] ? $payload['speakerNotes'] : null);
        $deliverable->touch();
        $this->entityManager->flush();

        return $this->jsonSuccess(['slide' => $this->deckSerializer->slide($slide)]);
    }

    /** Une copie de la diapositive, posée juste après elle. */
    #[Route('/slides/{slideId}/duplicate', name: '_duplicate', requirements: ['slideId' => '\d+'], methods: [HttpMethodEnum::Post->value])]
    public function duplicate(int $id, int $slideId): JsonResponse
    {
        $deliverable = $this->writable($id);
        $slide = $this->slides->slideOf($deliverable, $slideId);
        if (!$slide instanceof SlideInterface) {
            return $this->jsonNotFound();
        }

        $copy = $this->slides->duplicateSlide($slide);
        $deliverable->touch();
        $this->entityManager->flush();

        return $this->jsonSuccess(['slide' => $this->deckSerializer->slide($copy)]);
    }

    #[Route('/slides/{slideId}/delete', name: '_delete', requirements: ['slideId' => '\d+'], methods: [HttpMethodEnum::Post->value])]
    public function delete(int $id, int $slideId): JsonResponse
    {
        $deliverable = $this->writable($id);
        $slide = $this->slides->slideOf($deliverable, $slideId);
        if (!$slide instanceof SlideInterface) {
            return $this->jsonNotFound();
        }

        $this->slides->removeSlide($deliverable, $slide);
        $deliverable->touch();
        $this->entityManager->flush();

        return $this->jsonSuccess();
    }

    /** L'ordre entier, envoyé d'un coup : un glisser-déposer arrive n'importe où. */
    #[Route('/slides/reorder', name: '_reorder', methods: [HttpMethodEnum::Post->value])]
    public function reorder(int $id, Request $request): JsonResponse
    {
        $deliverable = $this->writable($id);
        $ids = $this->decodeJson($request)['orderedIds'] ?? null;

        $this->slides->reorderSlides($deliverable, is_array($ids) ? array_values(array_filter($ids, is_int(...))) : []);
        $deliverable->touch();
        $this->entityManager->flush();

        return $this->jsonSuccess(['deck' => $this->viewBuilder->deck($deliverable)]);
    }

    /**
     * Un fichier de police, rangé dans la médiathèque et proposé aussitôt.
     *
     * Deux droits : écrire ce livrable, et déposer un document dans la
     * médiathèque, puisque le fichier en devient un. Une police ne doit pas
     * être un détour autour du second.
     */
    #[Route('/fonts/upload', name: '_font_upload', methods: [HttpMethodEnum::Post->value])]
    #[IsGranted('ged.documents.create')]
    public function uploadFont(int $id, Request $request): JsonResponse
    {
        $this->writable($id);

        $file = $request->files->get('file');
        if (!$file instanceof UploadedFile) {
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

    /** Un diaporama de Studio que la personne peut lire, ou 404. */
    private function readable(int $id): DeliverableInterface
    {
        $deliverable = $this->deliverables->findStandalone($id);
        if (!$deliverable instanceof DeliverableInterface || !$deliverable->isSlides() || !$this->access->canRead($deliverable)) {
            throw new NotFoundHttpException();
        }

        return $deliverable;
    }

    /** Un diaporama de Studio que la personne peut modifier : 404 sans le lire, 403 sans l'écrire. */
    private function writable(int $id): DeliverableInterface
    {
        $deliverable = $this->readable($id);
        if (!$this->access->canWrite($deliverable)) {
            throw $this->createAccessDeniedException();
        }

        return $deliverable;
    }
}
