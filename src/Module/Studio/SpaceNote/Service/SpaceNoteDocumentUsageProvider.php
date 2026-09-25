<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\SpaceNote\Service;

use Aurora\Module\Ged\Document\Contract\BatchDocumentUsageProviderInterface;
use Aurora\Module\Platform\User\Entity\CoreUserInterface;
use Aurora\Module\Studio\SpaceNote\Entity\SpaceNoteInterface;
use Aurora\Module\Studio\SpaceNote\Repository\SpaceNoteRepository;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Dit quelles notes portent une image, avant que quelqu'un ne la supprime.
 *
 * **Sans ça, une image supprimée viderait une note en silence.** Le corps
 * d'une note est du JSON : aucune clé étrangère ne relie ses images à la
 * médiathèque, donc rien ne se casse à la suppression - le bloc reste, et son
 * adresse ne répond plus. C'est le pire des deux cas, parce que la note a
 * toujours l'air entière.
 *
 * Balayé plutôt que joint, contrairement aux pièces jointes d'une fiche : la
 * référence vit dans la structure. Le dépôt la cherche en deux temps - un
 * filtre en SQL, puis la vérification exacte bloc par bloc - pour la même
 * raison que les publications.
 *
 * C'est aussi ce qui rend utile l'identifiant que l'éditeur range désormais à
 * côté de l'adresse : sans lui, il n'y aurait qu'une URL à reconnaître.
 *
 * **Les notes personnelles comptent, mais ne se nomment pas.** Le balayage les
 * traverse toutes, sinon supprimer une image viderait la note de quelqu'un
 * d'autre sans que rien ne s'y oppose ; leur titre, lui, est remplacé pour qui
 * n'en est pas l'auteur. Ce que l'écran a besoin de dire, c'est « quelque
 * chose s'en sert », pas quoi.
 */
final readonly class SpaceNoteDocumentUsageProvider implements BatchDocumentUsageProviderInterface
{
    public function __construct(
        private SpaceNoteRepository $notes,
        private UrlGeneratorInterface $urlGenerator,
        private TranslatorInterface $translator,
        private Security $security,
    ) {}

    /** @return list<array{type: string, label: string, detail?: ?string, href?: ?string}> */
    public function findUsages(int $documentId): array
    {
        $usages = [];

        foreach ($this->notes->findUsingDocument($documentId) as $note) {
            $usages[] = [
                'type' => 'studio.space_note',
                'label' => $this->labelOf($note),
                // Le titre d'une note ne suffit pas à la situer : « Brief » et
                // « Compte rendu » se répètent d'un espace à l'autre, et ce
                // qu'il faut savoir avant de supprimer, c'est chez quel client.
                'detail' => $this->translator->trans(
                    'backend.studio.spaces.usage_detail',
                    ['{space}' => $note->getSpace()->getName()],
                ),
                'href' => $this->urlGenerator->generate(
                    'workspace_space_content',
                    ['id' => $note->getSpace()->getId()],
                ),
            ];
        }

        return $usages;
    }

    /** Le titre, sauf si la note appartient a quelqu'un d'autre. */
    private function labelOf(SpaceNoteInterface $note): string
    {
        $reader = $this->security->getUser();

        if ($note->isVisibleTo($reader instanceof CoreUserInterface ? $reader : null)) {
            return $note->getTitle();
        }

        return $this->translator->trans('backend.studio.space_notes.someone_elses');
    }

    /**
     * The same answer for a page of documents, in one narrowing and one read.
     *
     * @param list<int> $documentIds
     *
     * @return array<int, int>
     */
    public function countUsagesFor(array $documentIds): array
    {
        return $this->notes->countUsagesByDocument($documentIds);
    }
}
