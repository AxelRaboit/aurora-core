<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\Deliverable\View;

use Aurora\Core\Routing\PathTemplateGenerator;
use Aurora\Module\Studio\CustomerSpace\Entity\CustomerSpaceInterface;
use Aurora\Module\Studio\Deliverable\Entity\DeliverableInterface;
use Aurora\Module\Studio\Deliverable\Security\DeliverableAccess;
use Aurora\Module\Studio\Deliverable\Slides\Serializer\SlidesSerializer;
use Aurora\Module\Studio\Deliverable\Slides\SlideEditorOptions;
use LogicException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

use function array_map;
use function count;
use function sprintf;

use const DATE_ATOM;

/**
 * Ce que reçoivent les écrans d'un livrable au format diaporama : l'éditeur de
 * diapositives, la vue présentateur, l'impression et la page de lecture.
 *
 * **La forme qu'avaient les présentations** avant d'être des livrables :
 * l'éditeur, le lecteur et l'impression sont les composants des
 * présentations, et ils lisent un « deck ». Les adresses sont celles du
 * livrable, les droits ceux de {@see DeliverableAccess}, et le partage passe
 * par les liens de lecture du livrable.
 *
 * A presentation lives in Studio or in a client space: each has its editor
 * view ({@see self::editorView()}, {@see self::spaceEditorView()}) over the
 * same slide paths, under that place's routes.
 */
final readonly class DeliverableSlidesViewBuilder
{
    public function __construct(
        private SlidesSerializer $slidesSerializer,
        private DeliverableAccess $access,
        private SlideEditorOptions $editorOptions,
        private UrlGeneratorInterface $urlGenerator,
        private PathTemplateGenerator $pathTemplates,
        private AuthorizationCheckerInterface $authorizationChecker,
        private DeliverablesViewBuilder $deliverablesView,
        private SpaceDeliverablesViewBuilder $spaceDeliverablesView,
    ) {}

    /**
     * L'éditeur de diapositives d'un livrable de Studio.
     *
     * @return array<string, mixed>
     */
    public function editorView(DeliverableInterface $deliverable): array
    {
        $params = ['id' => $deliverable->getId()];
        $canWrite = $this->access->canWrite($deliverable);

        return [
            'deck' => $this->deck($deliverable),
            // Les réglages du document (titre, résumé, catégorie, modèle,
            // client, rayon) : la fenêtre « Réglages » de l'éditeur les écrit
            // par l'enregistrement d'un livrable, comme l'onglet d'une page.
            ...$this->deliverablesView->editorView($deliverable),
            ...$this->slidePaths('suite_studio_deliverables_slides_', $params, $canWrite),
            'backPath' => $this->urlGenerator->generate('suite_studio_deliverables').'?scope='.$deliverable->getScope()->value,
        ];
    }

    /**
     * The slide editor of a presentation kept in a client space: the space's
     * addresses, its rights, and what the space shell expects for its header
     * and tabs.
     *
     * A space deliverable's settings have no shelf, category, customer or
     * template switch to choose: the space says all of that. They do have the
     * « Visible par le client » toggle, under the right to share the space,
     * like a page's editor.
     *
     * @return array<string, mixed>
     */
    public function spaceEditorView(DeliverableInterface $deliverable): array
    {
        $space = $deliverable->getSpace();
        if (!$space instanceof CustomerSpaceInterface) {
            throw new LogicException('A deliverable without a space opens in Studio, not in a space.');
        }

        $page = $this->spaceDeliverablesView->editorView($deliverable);
        $canWrite = $this->access->canWrite($deliverable);

        return [
            'deck' => $this->deck($deliverable),
            'deliverable' => $page['deliverable'],
            'space' => $page['space'],
            'locales' => $page['locales'],
            'canEdit' => $canWrite,
            'canShare' => $this->access->canShare($deliverable),
            'canChangeScope' => false,
            'categories' => [],
            'customers' => [],
            'canPickCustomer' => false,
            'updatePath' => $page['updatePath'],
            'linksPath' => $page['linksPath'],
            ...$this->slidePaths('workspace_space_deliverables_slides_', ['id' => $space->getId(), 'deliverableId' => $deliverable->getId()], $canWrite),
            // The editor's back link leads to the space's Deliverables tab;
            // the shell's, to the list of spaces.
            'deliverablesPath' => $page['deliverablesPath'],
            'backPath' => $page['backPath'],
            'boardPath' => $page['boardPath'],
            'accessPath' => $page['accessPath'],
        ];
    }

    /**
     * The editor's addresses and the options it offers, under the routes of
     * its place: Studio's or a space's.
     *
     * @param array<string, int|null> $params
     *
     * @return array<string, mixed>
     */
    private function slidePaths(string $prefix, array $params, bool $canWrite): array
    {
        $route = fn (string $action): string => $this->urlGenerator->generate($prefix.$action, $params);
        $slide = fn (string $action): string => $this->pathTemplates->generate($prefix.$action, [...$params, 'slideId' => '__slideId__']);

        return [
            ...$this->editorOptions->all(),
            // Un fichier de police devient un document de la médiathèque : il
            // faut aussi le droit d'y en déposer un.
            'fontUploadPath' => $canWrite && $this->authorizationChecker->isGranted('ged.documents.create')
                ? $route('font_upload')
                : '',
            'appearancePath' => $route('appearance'),
            'printPath' => $route('print'),
            'presenterPath' => $route('presenter'),
            'slideCreatePath' => $route('create'),
            'slideUpdatePath' => $slide('update'),
            'slideDeletePath' => $slide('delete'),
            'slideDuplicatePath' => $slide('duplicate'),
            'slideReorderPath' => $route('reorder'),
        ];
    }

    /**
     * Le livrable sous la forme qu'attendent les composants des
     * présentations : titre, résumé, thème et diapositives.
     *
     * `channel` nomme le canal par lequel l'éditeur et la vue présentateur se
     * parlent : préfixé, il ne croise pas celui d'une autre fenêtre qui
     * numéroterait autre chose.
     *
     * @return array<string, mixed>
     */
    public function deck(DeliverableInterface $deliverable): array
    {
        $slideshow = $this->slidesSerializer->slideshow($deliverable);

        return [
            'id' => $deliverable->getId(),
            'channel' => sprintf('deliverable-%d', (int) $deliverable->getId()),
            'title' => $deliverable->getTitle(),
            'description' => $deliverable->getSummary(),
            'isTemplate' => $deliverable->isTemplate(),
            'theme' => $deliverable->getSlideTheme()->value,
            'slideCount' => count($slideshow['slides']),
            'updatedAt' => $deliverable->getUpdatedAt()->format(DATE_ATOM),
            ...$slideshow,
        ];
    }

    /**
     * Ce que lit le destinataire d'un lien : les diapositives, sans les notes
     * de l'orateur. Elles sont à qui présente, jamais au public : retirées
     * ici plutôt que confiées au gabarit.
     *
     * @return array<string, mixed>
     */
    public function readerDeck(DeliverableInterface $deliverable): array
    {
        $deck = $this->deck($deliverable);
        $deck['slides'] = array_map(
            static function (array $slide): array {
                unset($slide['speakerNotes']);

                return $slide;
            },
            $deck['slides'],
        );

        return $deck;
    }

    /**
     * L'apparence après son enregistrement, sans les diapositives : le
     * panneau change des couleurs, pas le contenu.
     *
     * @return array<string, mixed>
     */
    public function appearancePayload(DeliverableInterface $deliverable): array
    {
        return [
            'theme' => $deliverable->getSlideTheme()->value,
            'style' => $deliverable->getSlideStyle(),
            'appearance' => $this->slidesSerializer->appearanceOf($deliverable),
        ];
    }
}
