<?php

declare(strict_types=1);

namespace Aurora\Module\Notes\Favorite\Manager;

use Aurora\Module\Notes\Favorite\Entity\NoteFavorite;
use Aurora\Module\Notes\Favorite\Entity\NoteFavoriteInterface;
use Aurora\Module\Notes\Favorite\Repository\NoteFavoriteRepository;
use Aurora\Module\Notes\Folder\Entity\NoteFolderInterface;
use Aurora\Module\Notes\Markdown\Entity\MarkdownNoteInterface;
use Aurora\Module\Platform\User\Entity\CoreUserInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

/**
 * Les favoris d'une personne.
 *
 * **Ils sont à elle, pas à la note.** Une date posée sur la note épinglait
 * pour tout le monde : dans un espace partagé, épingler une procédure
 * l'aurait mise dans le panneau de chacun. Chaque personne épingle donc ce
 * qu'elle peut lire, pour elle seule, et l'ordre du panneau est celui de ses
 * gestes.
 */
#[AsAlias(NoteFavoriteManagerInterface::class)]
class NoteFavoriteManager implements NoteFavoriteManagerInterface
{
    public function __construct(
        protected readonly NoteFavoriteRepository $favorites,
        protected readonly EntityManagerInterface $entityManager,
    ) {}

    public function toggle(CoreUserInterface $user, MarkdownNoteInterface|NoteFolderInterface $item): bool
    {
        $criteria = $item instanceof MarkdownNoteInterface ? ['user' => $user, 'note' => $item] : ['user' => $user, 'folder' => $item];
        $existing = $this->favorites->findOneBy($criteria);

        if (null !== $existing) {
            $this->entityManager->remove($existing);
            $this->entityManager->flush();

            return false;
        }

        $favorite = $this->createFavorite();
        $favorite->setUser($user);
        if ($item instanceof MarkdownNoteInterface) {
            $favorite->setNote($item);
        } else {
            $favorite->setFolder($item);
        }

        $this->entityManager->persist($favorite);
        $this->entityManager->flush();

        return true;
    }

    public function mapFor(CoreUserInterface $user): array
    {
        return $this->favorites->mapFor($user);
    }

    protected function createFavorite(): NoteFavoriteInterface
    {
        return new NoteFavorite();
    }
}
