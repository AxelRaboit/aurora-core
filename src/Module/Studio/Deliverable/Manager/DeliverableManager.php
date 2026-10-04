<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\Deliverable\Manager;

use Aurora\Core\Locale\Service\LocaleContextInterface;
use Aurora\Module\Editorial\Post\Grid\GridNormalizer;
use Aurora\Module\Ged\Document\Entity\DocumentInterface;
use Aurora\Module\Ged\Document\Repository\DocumentRepository;
use Aurora\Module\Platform\User\Entity\CoreUserInterface;
use Aurora\Module\Studio\CustomerSpace\Entity\CustomerSpaceInterface;
use Aurora\Module\Studio\Deliverable\Entity\Deliverable;
use Aurora\Module\Studio\Deliverable\Entity\DeliverableCategoryInterface;
use Aurora\Module\Studio\Deliverable\Entity\DeliverableInterface;
use Aurora\Module\Studio\Deliverable\Enum\DeliverableScopeEnum;
use Aurora\Module\Studio\Deliverable\Repository\DeliverableCategoryRepository;
use Aurora\Module\Studio\Deliverable\Service\DeliverableAppearance;
use Aurora\Module\Studio\Deliverable\Service\DeliverableReadingHeader;
use Doctrine\ORM\EntityManagerInterface;

use function ctype_digit;
use function in_array;
use function is_array;
use function is_int;
use function is_string;
use function mb_strlen;
use function mb_trim;
use function str_starts_with;

/**
 * Écrit les livrables : création, enregistrement, copie, suppression.
 *
 * La grille passe par le normaliseur des pages du site : c'est la même
 * construction, avec les mêmes zones et les mêmes garde-fous, et ce qui est
 * accepté ici est ce qui sait se rendre là-bas.
 */
final readonly class DeliverableManager
{
    public const int TITLE_MAX = 255;

    public function __construct(
        private EntityManagerInterface $entityManager,
        private GridNormalizer $gridNormalizer,
        private LocaleContextInterface $localeContext,
        private DeliverableCategoryRepository $categories,
        private DocumentRepository $documents,
    ) {}

    /**
     * Un livrable neuf, prêt à composer.
     *
     * La grille est allumée d'emblée : un livrable n'a pas d'autre corps, et
     * un interrupteur à basculer avant d'écrire la première ligne serait un
     * geste pour rien. Dans un espace, « Préparé pour » reprend la raison
     * sociale du client ; sans espace, il n'y a encore personne à nommer.
     *
     * La portée ne compte que sans espace : dans un espace, c'est l'équipe de
     * l'espace qui lit.
     */
    public function create(
        ?CustomerSpaceInterface $space,
        string $title,
        ?CoreUserInterface $owner = null,
        DeliverableScopeEnum $scope = DeliverableScopeEnum::Shared,
        ?DeliverableCategoryInterface $category = null,
    ): DeliverableInterface {
        $deliverable = new Deliverable($space, $title, $this->localeContext->getDefaultLocale());
        $deliverable
            ->setOwner($owner)
            ->setCategory($space instanceof CustomerSpaceInterface ? null : $category)
            ->setScope($space instanceof CustomerSpaceInterface ? DeliverableScopeEnum::Shared : $scope);
        $layout = $this->gridNormalizer->normalizeLayout(['enabled' => true]);

        $deliverable
            ->setGridLayout($layout)
            ->setGridContent($this->gridNormalizer->normalizeContent([], $layout))
            ->setAppearance(DeliverableAppearance::normalize([]))
            ->setReadingHeader(DeliverableReadingHeader::normalize(['preparedFor' => $space?->getCustomer()->getLegalName() ?? '']));

        $this->entityManager->persist($deliverable);
        $this->entityManager->flush();

        return $deliverable;
    }

    /**
     * Enregistre ce que l'éditeur envoie, entier.
     *
     * @param array<string, mixed> $data
     *
     * @return array<string, string> les erreurs par champ, vide quand tout est passé
     */
    public function update(DeliverableInterface $deliverable, array $data): array
    {
        $errors = $this->errors($data);
        if ([] !== $errors) {
            return $errors;
        }

        // Un livrable est toujours une grille : son éditeur n'offre pas de
        // l'éteindre, et une grille éteinte rendrait une page vide.
        $layout = $this->gridNormalizer->normalizeLayout([...(is_array($data['gridLayout'] ?? null) ? $data['gridLayout'] : []), 'enabled' => true]);
        $summary = is_string($data['summary'] ?? null) ? mb_trim($data['summary']) : '';

        $deliverable
            ->setTitle(mb_trim((string) $data['title']))
            ->setSummary('' === $summary ? null : $summary)
            ->setLocale((string) $data['locale'])
            ->setGridLayout($layout)
            ->setGridContent($this->gridNormalizer->normalizeContent($data['gridContent'] ?? [], $layout))
            ->setAppearance(DeliverableAppearance::normalize($data['appearance'] ?? []))
            ->setReadingHeader(DeliverableReadingHeader::normalize($data['readingHeader'] ?? []))
            // Sans espace, il n'y a pas de client pour le voir : la case reste
            // fermée, quoi que dise l'éditeur.
            ->setVisibleToClient(!$deliverable->isStandalone() && true === ($data['visibleToClient'] ?? false))
            ->setCategory($deliverable->isStandalone() ? $this->category($data['categoryId'] ?? null) : null)
            ->setThumbnail($this->thumbnail($data['thumbnailId'] ?? null))
            ->touch();

        $this->entityManager->flush();

        return [];
    }

    /** Ouvert ou fermé au client, sans rouvrir l'éditeur : c'est le geste de la liste. */
    public function setVisibleToClient(DeliverableInterface $deliverable, bool $visible): void
    {
        $deliverable->setVisibleToClient($visible);
        $this->entityManager->flush();
    }

    /**
     * Une copie, pour partir d'un livrable existant : le bilan du mois dernier
     * pour écrire celui-ci.
     *
     * Fermée au client, quoi qu'il en soit de l'original : une copie est un
     * travail en cours. Ses liens de lecture ne suivent pas, ils ont été donnés
     * pour l'original.
     */
    public function duplicate(DeliverableInterface $source, string $title, ?CoreUserInterface $author = null): DeliverableInterface
    {
        $copy = new Deliverable($source->getSpace(), $title, $source->getLocale());
        // La copie est à qui la fait, dans le même rayon que l'original : une
        // copie d'un livrable partagé reste à l'équipe.
        $copy
            ->setOwner($author ?? $source->getOwner())
            ->setScope($source->getScope())
            ->setCategory($source->getCategory())
            ->setReadingHeader($source->getReadingHeader());

        return $this->persistCopy($source, $copy);
    }

    /**
     * Un livrable de Studio recopié dans l'espace d'un client : le modèle
     * d'audit ou de stratégie qu'on remplit pour lui.
     *
     * La copie vit désormais dans l'espace, avec ses droits ; l'original
     * reste dans Studio, intact. Elle arrive fermée au client, comme toute
     * copie, et « Préparé pour » prend le nom du client de l'espace.
     */
    public function copyToSpace(DeliverableInterface $source, CustomerSpaceInterface $space, string $title, ?CoreUserInterface $author = null): DeliverableInterface
    {
        $copy = new Deliverable($space, $title, $source->getLocale());
        $copy
            ->setOwner($author)
            ->setScope(DeliverableScopeEnum::Shared)
            ->setReadingHeader(DeliverableReadingHeader::normalize([
                ...$source->getReadingHeader(),
                'preparedFor' => $space->getCustomer()->getLegalName(),
            ]));

        return $this->persistCopy($source, $copy);
    }

    /**
     * Un livrable d'espace recopié dans Studio, pour en faire un modèle : il
     * arrive dans les livrables perso de qui le copie, sans client à nommer.
     */
    public function copyToStudio(DeliverableInterface $source, string $title, ?CoreUserInterface $author = null): DeliverableInterface
    {
        $copy = new Deliverable(null, $title, $source->getLocale());
        $copy
            ->setOwner($author)
            ->setScope(DeliverableScopeEnum::Personal)
            ->setReadingHeader(DeliverableReadingHeader::normalize([...$source->getReadingHeader(), 'preparedFor' => '']));

        return $this->persistCopy($source, $copy);
    }

    /** Perso ou partagé, pour un livrable sans espace. */
    public function setScope(DeliverableInterface $deliverable, DeliverableScopeEnum $scope, ?CoreUserInterface $by = null): void
    {
        if (!$deliverable->isStandalone()) {
            return;
        }

        $deliverable->setScope($scope);
        // Un orphelin qui change de rayon a été recueilli : il revient à qui
        // en a décidé, plutôt que de rester sans auteur.
        if (!$deliverable->getOwner() instanceof CoreUserInterface && $by instanceof CoreUserInterface) {
            $deliverable->setOwner($by);
        }

        $this->entityManager->flush();
    }

    /**
     * La catégorie qu'envoie l'éditeur ou la fenêtre de création.
     *
     * Un identifiant que plus rien ne résout laisse le livrable sans catégorie
     * plutôt que de refuser l'enregistrement : il ne peut venir que d'une
     * catégorie supprimée entre l'ouverture de la page et l'enregistrement.
     */
    public function category(mixed $id): ?DeliverableCategoryInterface
    {
        $id = is_int($id) || (is_string($id) && ctype_digit($id)) ? (int) $id : null;

        return null === $id ? null : $this->categories->find($id);
    }

    /**
     * L'image qu'envoie l'éditeur : un document de la médiathèque, et une
     * image. Un identifiant qui ne résout rien, ou un PDF, laisse le livrable
     * sans image plutôt que de refuser l'enregistrement.
     */
    private function thumbnail(mixed $id): ?DocumentInterface
    {
        $id = is_int($id) || (is_string($id) && ctype_digit($id)) ? (int) $id : null;
        $document = null === $id ? null : $this->documents->find($id);

        return $document instanceof DocumentInterface && str_starts_with((string) $document->getMimeType(), 'image/') ? $document : null;
    }

    /** Le corps de l'original dans la copie, puis enregistrée. */
    private function persistCopy(DeliverableInterface $source, DeliverableInterface $copy): DeliverableInterface
    {
        $copy
            ->setSummary($source->getSummary())
            ->setGridLayout($source->getGridLayout())
            ->setGridContent($source->getGridContent())
            ->setAppearance($source->getAppearance())
            ->setThumbnail($source->getThumbnail());

        $this->entityManager->persist($copy);
        $this->entityManager->flush();

        return $copy;
    }

    public function delete(DeliverableInterface $deliverable): void
    {
        $this->entityManager->remove($deliverable);
        $this->entityManager->flush();
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, string>
     */
    private function errors(array $data): array
    {
        $errors = [];
        $title = is_string($data['title'] ?? null) ? mb_trim($data['title']) : '';

        if ('' === $title) {
            $errors['title'] = 'backend.studio.deliverables.errors.title_required';
        } elseif (mb_strlen($title) > self::TITLE_MAX) {
            $errors['title'] = 'backend.studio.deliverables.errors.title_too_long';
        }

        if (!in_array($data['locale'] ?? null, $this->localeContext->getActiveLocales(), true)) {
            $errors['locale'] = 'backend.studio.deliverables.errors.locale_invalid';
        }

        if (isset($data['gridLayout']) && !is_array($data['gridLayout'])) {
            $errors['gridLayout'] = 'backend.studio.deliverables.errors.grid_invalid';
        }

        return $errors;
    }
}
