<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\SpaceContent\Service;

use Aurora\Module\Ged\Document\Entity\DocumentInterface;
use Aurora\Module\Studio\CustomerSpace\Entity\CustomerSpaceInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Ce que plus rien n'utilise, proposé plutôt que jeté.
 *
 * Retirer un fichier d'une fiche, supprimer une note, retirer un fichier d'un
 * espace : trois gestes, une seule suite. Le registre d'usages dit ce qui est
 * devenu orphelin, et la réponse porte de quoi l'envoyer à la corbeille d'un
 * clic, sans jamais le faire à la place de quelqu'un.
 *
 * **Le droit reste au contrôleur.** L'offre n'est faite qu'à qui peut déjà
 * supprimer un document, et c'est `$mayTrash` qui le dit : un bouton qui
 * répondrait 403 serait pire que pas de bouton, et accorder le privilège au
 * passage serait un privilège entré par la porte de service. Ce service ne
 * sait pas qui regarde, et c'est voulu - trois contrôleurs en portaient une
 * copie chacun, et l'un des trois avait déjà oublié la garde une fois.
 */
final readonly class SpaceOrphanedDocumentOffer
{
    public function __construct(
        private SpaceOrphanedDocumentFinder $finder,
        private UrlGeneratorInterface $urlGenerator,
    ) {}

    /**
     * @param list<DocumentInterface> $documents
     *
     * @return array{orphanedDocuments: list<array{id: int, title: string, trashPath: string}>}
     */
    public function payload(CustomerSpaceInterface $space, array $documents, bool $mayTrash): array
    {
        if (!$mayTrash) {
            return ['orphanedDocuments' => []];
        }

        $offered = [];

        foreach ($this->finder->among($space, $documents) as $document) {
            $offered[] = $document + [
                'trashPath' => $this->urlGenerator->generate('backend_ged_documents_delete', ['id' => $document['id']]),
            ];
        }

        return ['orphanedDocuments' => $offered];
    }
}
