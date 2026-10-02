<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\SpaceDeliverable\Manager;

use Aurora\Core\Locale\Service\LocaleContextInterface;
use Aurora\Module\Editorial\Post\Grid\GridNormalizer;
use Aurora\Module\Studio\CustomerSpace\Entity\CustomerSpaceInterface;
use Aurora\Module\Studio\SpaceDeliverable\Entity\SpaceDeliverable;
use Aurora\Module\Studio\SpaceDeliverable\Entity\SpaceDeliverableInterface;
use Aurora\Module\Studio\SpaceDeliverable\Service\DeliverableAppearance;
use Aurora\Module\Studio\SpaceDeliverable\Service\DeliverableReadingHeader;
use Doctrine\ORM\EntityManagerInterface;

use function in_array;
use function is_array;
use function is_string;
use function mb_strlen;
use function mb_trim;

/**
 * Écrit les livrables : création, enregistrement, copie, suppression.
 *
 * La grille passe par le normaliseur des pages du site : c'est la même
 * construction, avec les mêmes zones et les mêmes garde-fous, et ce qui est
 * accepté ici est ce qui sait se rendre là-bas.
 */
final readonly class SpaceDeliverableManager
{
    public const int TITLE_MAX = 255;

    public function __construct(
        private EntityManagerInterface $entityManager,
        private GridNormalizer $gridNormalizer,
        private LocaleContextInterface $localeContext,
    ) {}

    /**
     * Un livrable neuf, prêt à composer.
     *
     * La grille est allumée d'emblée : un livrable n'a pas d'autre corps, et
     * un interrupteur à basculer avant d'écrire la première ligne serait un
     * geste pour rien. « Préparé pour » reprend la raison sociale du client.
     */
    public function create(CustomerSpaceInterface $space, string $title): SpaceDeliverableInterface
    {
        $deliverable = new SpaceDeliverable($space, $title, $this->localeContext->getDefaultLocale());
        $layout = $this->gridNormalizer->normalizeLayout(['enabled' => true]);

        $deliverable
            ->setGridLayout($layout)
            ->setGridContent($this->gridNormalizer->normalizeContent([], $layout))
            ->setAppearance(DeliverableAppearance::normalize([]))
            ->setReadingHeader(DeliverableReadingHeader::normalize(['preparedFor' => $space->getCustomer()->getLegalName()]));

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
    public function update(SpaceDeliverableInterface $deliverable, array $data): array
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
            ->setVisibleToClient(true === ($data['visibleToClient'] ?? false))
            ->touch();

        $this->entityManager->flush();

        return [];
    }

    /** Ouvert ou fermé au client, sans rouvrir l'éditeur : c'est le geste de la liste. */
    public function setVisibleToClient(SpaceDeliverableInterface $deliverable, bool $visible): void
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
    public function duplicate(SpaceDeliverableInterface $source, string $title): SpaceDeliverableInterface
    {
        $copy = new SpaceDeliverable($source->getSpace(), $title, $source->getLocale());
        $copy
            ->setSummary($source->getSummary())
            ->setGridLayout($source->getGridLayout())
            ->setGridContent($source->getGridContent())
            ->setAppearance($source->getAppearance())
            ->setReadingHeader($source->getReadingHeader());

        $this->entityManager->persist($copy);
        $this->entityManager->flush();

        return $copy;
    }

    public function delete(SpaceDeliverableInterface $deliverable): void
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
            $errors['title'] = 'backend.studio.space_deliverables.errors.title_required';
        } elseif (mb_strlen($title) > self::TITLE_MAX) {
            $errors['title'] = 'backend.studio.space_deliverables.errors.title_too_long';
        }

        if (!in_array($data['locale'] ?? null, $this->localeContext->getActiveLocales(), true)) {
            $errors['locale'] = 'backend.studio.space_deliverables.errors.locale_invalid';
        }

        if (isset($data['gridLayout']) && !is_array($data['gridLayout'])) {
            $errors['gridLayout'] = 'backend.studio.space_deliverables.errors.grid_invalid';
        }

        return $errors;
    }
}
