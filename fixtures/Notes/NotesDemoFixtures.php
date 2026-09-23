<?php

declare(strict_types=1);

namespace Aurora\Fixtures\Notes;

use Aurora\Fixtures\Core\AppFixtures;
use Aurora\Fixtures\Core\CoreDemoFixtures;
use Aurora\Module\Notes\Folder\Entity\NoteFolder;
use Aurora\Module\Notes\Markdown\Entity\MarkdownNote;
use Aurora\Module\Notes\Share\Manager\MarkdownNoteShareLinkManagerInterface;
use Aurora\Module\Notes\Share\Repository\MarkdownNoteShareLinkRepository;
use Aurora\Module\Platform\User\Entity\User;
use Aurora\Module\Platform\User\Enum\UserTypeEnum;
use Aurora\Module\Platform\User\Repository\UserRepository;
use DateTimeImmutable;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Bundle\FixturesBundle\FixtureGroupInterface;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ObjectManager;
use RuntimeException;

use function assert;

/**
 * Un carnet de notes qui se tient debout tout seul.
 *
 * La démo n'en avait aucune, et l'écran des notes s'ouvrait sur un éditeur
 * vide : les huit pages de documentation de la rubrique montraient toutes la
 * même capture d'un carnet neuf, et le graphe des liens était un rectangle
 * noir. Ce qu'il faut pour que ces écrans disent quelque chose :
 *
 *  - des liens `[[Titre]]` réels, donc un graphe qui a des arêtes ;
 *  - une note citée par deux autres, pour la liste « ce qui pointe ici » ;
 *  - un titre mentionné sans crochets, pour les mentions non liées ;
 *  - des étiquettes, pour le filtre et la recherche ;
 *  - un dossier avec des notes dedans, pour la bibliothèque.
 *
 * Dev/test only, groupe `demo`.
 */
class NotesDemoFixtures extends Fixture implements DependentFixtureInterface, FixtureGroupInterface
{
    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly MarkdownNoteShareLinkManagerInterface $shareLinks,
        private readonly MarkdownNoteShareLinkRepository $shareLinkRepository,
    ) {}

    public static function getGroups(): array
    {
        return ['demo'];
    }

    public function getDependencies(): array
    {
        return [AppFixtures::class, CoreDemoFixtures::class];
    }

    public function load(ObjectManager $manager): void
    {
        assert($manager instanceof EntityManagerInterface);

        // Le compte que la documentation photographie. Les notes sont
        // personnelles : rangées sous un autre compte, l'écran s'ouvre vide
        // pour qui prend la capture, ce qui est exactement ce qui s'est
        // passé.
        $owner = $this->userRepository->findOneBy([
            'email' => 'dev@aurora.app',
            'type' => UserTypeEnum::Backend->value,
        ]);

        if (!$owner instanceof User) {
            throw new RuntimeException('The demo backend account is missing - run the core fixtures first.');
        }

        $repository = $manager->getRepository(MarkdownNote::class);

        // Les titres sont chiffrés en base, donc `findOneBy(['title' => …])`
        // ne trouve jamais rien : la comparaison porterait sur du texte clair
        // contre du chiffré. Les notes de l'utilisateur sont chargées une
        // fois et indexées après déchiffrement, ce qui est le seul endroit où
        // le titre existe en clair.
        $existing = [];
        foreach ($repository->findBy(['user' => $owner]) as $note) {
            $existing[(string) $note->getTitle()] = $note;
        }

        $folderRepository = $manager->getRepository(NoteFolder::class);

        $existingFolders = [];
        foreach ($folderRepository->findBy(['user' => $owner]) as $folder) {
            $existingFolders[(string) $folder->getName()] = $folder;
        }

        $folders = [];
        $folderPosition = 0;

        foreach ($this->folders() as $key => $name) {
            $folder = $existingFolders[$name] ?? new NoteFolder();
            $folder
                ->setUser($owner)
                ->setName($name)
                ->setPosition($folderPosition++);

            $manager->persist($folder);
            $folders[$key] = $folder;
        }

        $notes = [];
        $position = 0;

        foreach ($this->notes() as $key => $definition) {
            $note = $existing[$definition['title']] ?? new MarkdownNote();

            $note
                ->setUser($owner)
                ->setTitle($definition['title'])
                ->setContent($definition['content'])
                ->setTags($definition['tags'])
                ->setPosition($position++)
                ->setFolder(isset($definition['folder']) ? $folders[$definition['folder']] : null);

            $manager->persist($note);
            $notes[$key] = $note;
        }

        $manager->flush();

        $this->shareLinkFor($notes['clients'] ?? null);
    }

    /**
     * Une note partagée, pour que l'écran des partages ait quelque chose.
     *
     * Il s'ouvrait toujours sur une liste vide, si bien que la fonctionnalité
     * la plus visible du module - une note lisible sans compte - ne se voyait
     * nulle part. La note d'index avec ses liens suivis, parce que c'est le
     * cas que l'option « inclure les notes liées » existe pour : partager un
     * sommaire seul donne au destinataire une liste de titres et rien
     * derrière.
     */
    private function shareLinkFor(?MarkdownNote $note): void
    {
        // Rejoué à chaque `make demo` sinon : la note est retrouvée, le lien
        // non, et l'écran se remplirait d'un partage de plus par exécution.
        if (!$note instanceof MarkdownNote || [] !== $this->shareLinkRepository->findForNote($note)) {
            return;
        }

        $this->shareLinks->create(
            $note,
            includeLinked: true,
            recipientEmail: 'camille@studio-lumen.fr',
            label: 'Carnet clients - lecture seule',
            expiresAt: new DateTimeImmutable('+30 days'),
        );
    }

    /**
     * Les dossiers de la démo, par clé.
     *
     * @return array<string, string>
     */
    private function folders(): array
    {
        return ['clients' => 'Clients'];
    }

    /**
     * @return array<string, array{title: string, content: string, tags: list<string>, folder?: string}>
     */
    private function notes(): array
    {
        return [
            'clients' => [
                // Le sommaire ne porte pas le nom de son dossier : deux
                // lignes « Clients » l'une sous l'autre, un dossier et une
                // note, est exactement l'ambiguïté que les dossiers ont
                // supprimée.
                'title' => 'Sommaire des clients',
                'tags' => ['index'],
                'folder' => 'clients',
                'content' => <<<'MD'
                    # Sommaire des clients

                    La porte d'entrée du dossier : chaque client a sa note, et
                    chaque note renvoie ici.

                    - [[Studio Lumen]] : photo, en cours
                    - [[Cabinet Verrier]] : site vitrine, livré

                    Tout ce qui est contractuel part de [[Contrat type]].
                    MD,
            ],
            'lumen' => [
                'title' => 'Studio Lumen',
                'tags' => ['client', 'photo'],
                'folder' => 'clients',
                'content' => <<<'MD'
                    # Studio Lumen

                    Deux séances par an, catalogue et portraits d'équipe.
                    Interlocutrice : Camille, qui décide vite.

                    Conditions reprises de [[Contrat type]], avec une clause
                    de cession élargie pour les réseaux.

                    Voir aussi [[Séance en extérieur]] : c'est le format
                    qu'ils redemandent.
                    MD,
            ],
            'verrier' => [
                'title' => 'Cabinet Verrier',
                'tags' => ['client', 'web'],
                'folder' => 'clients',
                'content' => <<<'MD'
                    # Cabinet Verrier

                    Trois architectes, un site qui montre les chantiers
                    livrés. Livré en mars, maintenance au forfait.

                    Le cadre est celui de [[Contrat type]].
                    MD,
            ],
            'contrat' => [
                'title' => 'Contrat type',
                'tags' => ['modèle'],
                'content' => <<<'MD'
                    # Contrat type

                    Le socle commun, à adapter par client.

                    - Acompte de 30 % à la commande
                    - Deux allers-retours inclus
                    - Cession des droits à la livraison, usage précisé au cas
                      par cas

                    Retour à [[Clients]].
                    MD,
            ],
            'seance' => [
                'title' => 'Séance en extérieur',
                'tags' => ['photo', 'méthode'],
                'content' => <<<'MD'
                    # Séance en extérieur

                    Repérage la veille, lumière de fin de journée, une heure
                    de battement pour la météo.

                    Le Studio Lumen redemande ce format à chaque fois, noté ici
                    sans lien exprès pour voir ce que donne une mention non
                    liée.

                    Matériel : voir [[Matériel]].
                    MD,
            ],
            'materiel' => [
                'title' => 'Matériel',
                'tags' => ['photo'],
                'content' => <<<'MD'
                    # Matériel

                    - Boîtier principal + second boîtier en secours
                    - 35 mm et 85 mm, rien d'autre en extérieur
                    - Réflecteur pliant, plus utile qu'un flash

                    Sert surtout pour [[Séance en extérieur]].
                    MD,
            ],
            'idees' => [
                'title' => 'Idées d\'articles',
                'tags' => ['éditorial'],
                'content' => <<<'MD'
                    # Idées d'articles

                    - Ce qu'on regarde dans un devis de photographe
                    - Pourquoi un site lent coûte des clients
                    - Le repérage, cette étape qu'on saute toujours

                    Rien de commencé, tout est à écrire.
                    MD,
            ],
        ];
    }
}
