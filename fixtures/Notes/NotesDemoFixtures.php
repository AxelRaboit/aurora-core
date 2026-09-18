<?php

declare(strict_types=1);

namespace Aurora\Fixtures\Notes;

use Aurora\Fixtures\Core\AppFixtures;
use Aurora\Fixtures\Core\CoreDemoFixtures;
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
 *  - une note fille, pour l'arborescence.
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

        $notes = [];
        $position = 0;

        foreach ($this->notes() as $key => $definition) {
            $note = $existing[$definition['title']] ?? new MarkdownNote();

            $note
                ->setUser($owner)
                ->setTitle($definition['title'])
                ->setContent($definition['content'])
                ->setTags($definition['tags'])
                ->setPosition($position++);

            $manager->persist($note);
            $notes[$key] = $note;
        }

        // Les parents après coup : une note fille désigne une note qui doit
        // déjà exister, et l'ordre de la liste ci-dessus est celui de
        // lecture, pas celui des dépendances.
        foreach ($this->notes() as $key => $definition) {
            if (isset($definition['parent'])) {
                $notes[$key]->setParent($notes[$definition['parent']]);
            }
        }

        $manager->flush();

        $this->shareBranch($notes['clients'] ?? null);
    }

    /**
     * Une branche partagée, pour que l'écran des partages ait quelque chose.
     *
     * Il s'ouvrait toujours sur une liste vide, si bien que la fonctionnalité
     * la plus visible du module - une note lisible sans compte - ne se voyait
     * nulle part. La branche des clients avec ses filles, parce que c'est le
     * cas que l'option « inclure les sous-notes » existe pour : partager une
     * note d'index seule donne au destinataire un sommaire et rien dessous.
     */
    private function shareBranch(?MarkdownNote $note): void
    {
        // Rejoué à chaque `make demo` sinon : la note est retrouvée, le lien
        // non, et l'écran se remplirait d'un partage de plus par exécution.
        if (!$note instanceof MarkdownNote || [] !== $this->shareLinkRepository->findForNote($note)) {
            return;
        }

        $this->shareLinks->create(
            $note,
            includeDescendants: true,
            includeLinked: false,
            recipientEmail: 'camille@studio-lumen.fr',
            label: 'Carnet clients - lecture seule',
            expiresAt: new DateTimeImmutable('+30 days'),
        );
    }

    /**
     * @return array<string, array{title: string, content: string, tags: list<string>, parent?: string}>
     */
    private function notes(): array
    {
        return [
            'clients' => [
                'title' => 'Clients',
                'tags' => ['index'],
                'content' => <<<'MD'
                    # Clients

                    La porte d'entrée du carnet : chaque client a sa note, et
                    chaque note renvoie ici.

                    - [[Studio Lumen]] : photo, en cours
                    - [[Cabinet Verrier]] : site vitrine, livré

                    Tout ce qui est contractuel part de [[Contrat type]].
                    MD,
            ],
            'lumen' => [
                'title' => 'Studio Lumen',
                'tags' => ['client', 'photo'],
                'parent' => 'clients',
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
                'parent' => 'clients',
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
