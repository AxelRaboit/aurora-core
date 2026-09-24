<?php

declare(strict_types=1);

namespace Aurora\Fixtures\Notes;

use Aurora\Fixtures\Core\AppFixtures;
use Aurora\Fixtures\Core\CoreDemoFixtures;
use Aurora\Module\Notes\Folder\Entity\NoteFolder;
use Aurora\Module\Notes\Markdown\Entity\MarkdownNote;
use Aurora\Module\Notes\Markdown\Enum\NoteAppearanceEnum;
use Aurora\Module\Notes\Markdown\Service\MarkdownNoteImageService;
use Aurora\Module\Notes\Share\Manager\MarkdownNoteShareLinkManagerInterface;
use Aurora\Module\Notes\Share\Repository\MarkdownNoteShareLinkRepository;
use Aurora\Module\Platform\User\Entity\CoreUserInterface;
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
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Throwable;

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
 * Et depuis la refonte en dossiers, de quoi montrer ce qu'elle a ajouté :
 * un dossier dans un dossier (fil d'Ariane, profondeur, vue à plat), des
 * couleurs de dossier, des favoris, des apparences, des bandeaux, une
 * liste de tâches pour que la vignette d'une carte montre autre chose que
 * du texte, et une note à la corbeille pour que l'écran de corbeille ne
 * soit pas vide.
 *
 * Dev/test only, groupe `demo`.
 */
class NotesDemoFixtures extends Fixture implements DependentFixtureInterface, FixtureGroupInterface
{
    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly MarkdownNoteShareLinkManagerInterface $shareLinks,
        private readonly MarkdownNoteShareLinkRepository $shareLinkRepository,
        private readonly MarkdownNoteImageService $images,
        private readonly HttpClientInterface $http,
        private readonly Filesystem $filesystem = new Filesystem(),
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

        // Les parents sont déclarés avant leurs enfants, donc une seule
        // passe suffit : un dossier ne peut pointer que vers un dossier
        // déjà construit.
        foreach ($this->folders() as $key => $definition) {
            $folder = $existingFolders[$definition['name']] ?? new NoteFolder();
            $folder
                ->setUser($owner)
                ->setName($definition['name'])
                ->setColor($definition['color'] ?? null)
                ->setParent(isset($definition['parent']) ? $folders[$definition['parent']] : null)
                ->setPosition($folderPosition++);

            if ($definition['favorite'] ?? false) {
                $folder->setFavoritedAt(new DateTimeImmutable('-3 days'));
            }

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
                ->setContent($this->withImages($definition, $note, $owner))
                ->setTags($definition['tags'])
                ->setPosition($position++)
                ->setAppearance(NoteAppearanceEnum::fromNullable($definition['appearance'] ?? null))
                ->setCoverUrl(isset($definition['cover']) ? $this->pexels($definition['cover']) : null)
                ->setCoverCreditName($definition['coverCredit'] ?? null)
                ->setCoverCreditUrl(
                    isset($definition['cover'])
                        ? sprintf('https://www.pexels.com/photo/%d/', $definition['cover'])
                        : null,
                )
                ->setCoverPosition($definition['coverPosition'] ?? 50)
                ->setFolder(isset($definition['folder']) ? $folders[$definition['folder']] : null);

            $note->setFavoritedAt(
                ($definition['favorite'] ?? false) ? new DateTimeImmutable('-2 days') : null,
            );

            // Une note à la corbeille, pour que l'écran global en montre
            // une. Reposée à chaque exécution : elle est le décor, pas le
            // résultat d'un geste qu'on voudrait conserver.
            $note->setDeletedAt(
                ($definition['trashed'] ?? false) ? new DateTimeImmutable('-1 day') : null,
            );

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
     * Les dossiers de la démo, parents d'abord.
     *
     * Un dossier dans un dossier n'est pas du décor : c'est ce qui fait
     * exister le fil d'Ariane, la profondeur, et la différence entre la vue
     * rangée et la vue à plat. Les couleurs servent à voir d'un coup d'œil
     * ce que la carte et l'arbre du menu en font.
     *
     * @return array<string, array{name: string, parent?: string, color?: string, favorite?: bool}>
     */
    private function folders(): array
    {
        return [
            'clients' => ['name' => 'Clients', 'color' => '#22c55e', 'favorite' => true],
            'lumen' => ['name' => 'Studio Lumen', 'parent' => 'clients', 'color' => '#3b82f6'],
            'photo' => ['name' => 'Photographie', 'color' => '#f59e0b'],
            'editorial' => ['name' => 'Éditorial', 'color' => '#8b5cf6'],
            'archives' => ['name' => 'Archives'],
        ];
    }

    /**
     * L'adresse d'une photo Pexels, telle que la note la garde.
     *
     * **Rien n'est téléchargé, ici pas plus qu'ailleurs** : la démo écrit
     * l'adresse servie par leur CDN, exactement ce que le sélecteur écrit
     * quand on choisit une photo. C'est la démonstration la plus fidèle du
     * choix de conception - l'image vit dehors, la note n'en a que
     * l'adresse et le crédit.
     *
     * Les identifiants et les noms viennent d'une vraie recherche
     * (`aurora:ged:pexels:search`, jouée là où la clé est configurée), et
     * chaque adresse a été vérifiée. Inventer des identifiants aurait donné
     * des cadres vides sous un crédit faux, ce qui est pire qu'un carnet
     * sans bandeau.
     *
     * Le lien de crédit pointe la page de la photo : la licence demande de
     * nommer l'auteur, et c'est de là qu'on remonte à lui.
     */
    /**
     * Remplace les `{{image:0}}` du texte par de vraies images collées.
     *
     * Le carnet de démonstration montrait tout du module sauf ça : une note
     * pouvait porter une image, mais aucune n'en portait, donc ni la vignette
     * de la bibliothèque ni la capture du site public ne le disaient.
     *
     * **Les photos ne sont pas dans le dépôt.** Elles sont tirées du CDN de
     * Pexels au chargement, comme les bandeaux tirent leur adresse du même
     * endroit - à ceci près qu'ici il faut les octets, puisqu'une image collée
     * est un fichier chez nous. Committer des photos dans un dépôt public
     * pour décorer un jeu d'essai est un poids qu'on ne reprend jamais, et
     * l'une d'elles portait dans ses métadonnées un « All Rights Reserved »
     * qui n'a rien à faire là.
     *
     * **Sans réseau, la note garde son texte.** Un jeu de démonstration qui
     * refuse de se charger parce qu'un CDN est lent est un jeu de
     * démonstration cassé. Le marqueur disparaît, l'image avec, et le reste
     * du carnet arrive.
     *
     * **Idempotente** : si la note porte déjà des images, on réutilise les
     * fichiers qu'elle cite plutôt que d'en téléverser de nouveaux à chaque
     * `make demo`. Sans ça, dix rechargements laisseraient dix copies de la
     * même photo dans le stockage, sans que rien ne les réclame.
     *
     * @param array{content: string, images?: list<int>} $definition
     */
    private function withImages(array $definition, MarkdownNote $note, CoreUserInterface $owner): string
    {
        $content = $definition['content'];
        $wanted = $definition['images'] ?? [];

        if ([] === $wanted) {
            return $content;
        }

        $already = $this->images->extractFilenames($note->getContent());

        foreach ($wanted as $index => $photoId) {
            $filename = $already[$index] ?? $this->fetchImage($photoId, $owner);

            if (null === $filename) {
                // Le marqueur part avec la ligne qui le porte : un
                // `![légende]()` vide afficherait une icône cassée.
                $content = (string) preg_replace('/^.*\{\{image:'.$index.'\}\}.*$\n?/m', '', $content);

                continue;
            }

            $content = str_replace(
                sprintf('{{image:%d}}', $index),
                '/backend/notes/markdown/images/'.$filename,
                $content,
            );
        }

        return $content;
    }

    /** Une photo de Pexels, téléversée comme si on l'avait collée. */
    private function fetchImage(int $photoId, CoreUserInterface $owner): ?string
    {
        $temporaire = (string) tempnam(sys_get_temp_dir(), 'aurora-demo-image-');

        try {
            $octets = $this->http->request('GET', $this->pexels($photoId))->getContent();
            $this->filesystem->dumpFile($temporaire, $octets);

            return $this->images->store(
                new UploadedFile($temporaire, sprintf('pexels-%d.jpg', $photoId), null, null, true),
                $owner,
            );
        } catch (Throwable) {
            return null;
        } finally {
            $this->filesystem->remove($temporaire);
        }
    }

    private function pexels(int $id): string
    {
        return sprintf(
            'https://images.pexels.com/photos/%d/pexels-photo-%d.jpeg?auto=compress&cs=tinysrgb&w=1200',
            $id,
            $id,
        );
    }

    /**
     * @return array<string, array{title: string, content: string, tags: list<string>, folder?: string, cover?: int, coverCredit?: string, coverPosition?: int, appearance?: string, favorite?: bool, trashed?: bool, images?: list<int>}>
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
                'tags' => ['index', 'client'],
                'folder' => 'clients',
                'favorite' => true,
                'cover' => 33714905,
                'coverCredit' => 'Matheus Bertelli',
                'appearance' => 'paper',
                // Le sommaire sert deux images de la carte publique : la page
                // de partage et la vue de lecture. Il tenait en quatre lignes,
                // ce qui laissait les deux à moitié vides. Un vrai sommaire de
                // dossier porte l'état de chaque client, ce qui est en attente
                // et où l'on en est, donc c'est ce qu'il porte.
                'content' => <<<'MD'
                    # Sommaire des clients

                    Chaque client a sa note, et chaque note renvoie ici. Le contractuel part de [[Contrat type]].

                    ## En cours

                    - [[Studio Lumen]] : deux séances par an, la prochaine en novembre.
                    - [[Cabinet Verrier]] : site livré en mars, maintenance au forfait.

                    ## Le rythme

                    | Client | Depuis | Ce qui revient |
                    | --- | --- | --- |
                    | Studio Lumen | 2024 | deux séances, un catalogue |
                    | Cabinet Verrier | 2025 | maintenance mensuelle |

                    ## À relancer

                    - [x] Facture de septembre, Studio Lumen
                    - [ ] Photos de la galerie, Cabinet Verrier ; tarifs dans [[Tarifs 2024]]
                    MD,
            ],
            'lumen' => [
                'title' => 'Studio Lumen',
                'tags' => ['client', 'photo'],
                'folder' => 'lumen',
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
                'cover' => 923307,
                'coverCredit' => 'Julien Bachelet',
                // La note la plus fournie de la démonstration, et c'est
                // délibéré : c'est elle que la carte publique du module
                // photographie, en vue partagée. Trois lignes remplissaient
                // un quart du volet et laissaient le reste blanc, ce qui
                // montre surtout que la note est vide. Une vraie fiche client
                // tient une trentaine de lignes, avec ses sections, sa liste
                // à cocher et son tableau : c'est ce que le module sert à
                // faire, donc c'est ce qu'il faut montrer.
                'content' => <<<'MD'
                    # Cabinet Verrier

                    Trois architectes associés, un site vitrine qui montre les chantiers livrés. Mise en ligne en mars, maintenance au forfait depuis. Interlocuteur : Paul, qui relit tout.

                    ![La page d'accueil, version validée en février]({{image:0}})

                    ## Où ça en est

                    - [x] Refonte de la page d'accueil
                    - [x] Reprise des fiches chantier, 24 au total
                    - [ ] Galerie avant / après, en attente des photos
                    - [ ] Formulaire de contact en trois langues

                    ## Le forfait

                    | Poste | Rythme | Montant |
                    | --- | --- | --- |
                    | Maintenance | mensuel | 180 € |
                    | Sauvegardes | mensuel | inclus |
                    | Petites évolutions | 2 h / mois | inclus |

                    Au-delà des deux heures, c'est du temps facturé au tarif courant, annoncé avant d'être engagé.

                    ## À ne pas oublier

                    Paul veut être prévenu **avant** toute mise en ligne, même pour une correction de texte : le cabinet répond à des appels d'offres et le site sert de référence.

                    Le cadre contractuel est celui de [[Contrat type]], et la fiche remonte au [[Sommaire des clients]].
                    MD,
                'images' => [3760067],
            ],
            'contrat' => [
                'title' => 'Contrat type',
                'tags' => ['modèle', 'client'],
                'appearance' => 'sepia',
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
                'folder' => 'photo',
                // Une photo en hauteur, coupée haut : c'est le cas qui
                // justifie le réglage de cadrage, un portrait montrant un
                // menton quand on le centre.
                'cover' => 35256272,
                'coverCredit' => 'Alef Morais',
                'coverPosition' => 30,
                'content' => <<<'MD'
                    # Séance en extérieur

                    Repérage la veille, lumière de fin de journée, une heure
                    de battement pour la météo.

                    Le Studio Lumen redemande ce format à chaque fois, noté ici
                    sans lien exprès pour voir ce que donne une mention non
                    liée.

                    ![Le repérage de la veille, même heure]({{image:0}})

                    Matériel : voir [[Matériel]].
                    MD,
                'images' => [1181244],
            ],
            'materiel' => [
                'title' => 'Matériel',
                'tags' => ['photo'],
                'folder' => 'photo',
                'cover' => 18880006,
                'coverCredit' => 'Amar Preciado',
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
                'folder' => 'editorial',
                'favorite' => true,
                'content' => <<<'MD'
                    # Idées d'articles

                    - Ce qu'on regarde dans un devis de photographe
                    - Pourquoi un site lent coûte des clients
                    - Le repérage, cette étape qu'on saute toujours

                    Rien de commencé, tout est à écrire.
                    MD,
            ],
            'devis' => [
                'title' => 'Devis Lumen 2026',
                'tags' => ['client', 'devis'],
                'folder' => 'lumen',
                'content' => <<<'MD'
                    # Devis Lumen 2026

                    Deux séances, catalogue au printemps et portraits à
                    l'automne. Le cadre est celui de [[Contrat type]].

                    | Poste | Quantité | Prix |
                    | --- | --- | --- |
                    | Séance catalogue | 1 | 1 400 € |
                    | Portraits équipe | 12 | 900 € |
                    | Retouche | forfait | 300 € |

                    Envoyé le 12 mars, relancé une fois.
                    MD,
            ],
            'reperage' => [
                'title' => 'Repérage Lumen',
                'tags' => ['photo', 'méthode', 'client'],
                'folder' => 'lumen',
                'content' => <<<'MD'
                    # Repérage Lumen

                    Leur atelier donne au nord : lumière égale toute la
                    journée, aucune ombre dure. Le mur de briques du fond
                    fait un décor à lui seul.

                    Méthode complète dans [[Séance en extérieur]].
                    MD,
            ],
            'livraison' => [
                // Des cases à cocher, pour que la vignette d'une carte
                // montre autre chose qu'un paragraphe : c'est la forme qui
                // fait reconnaître une note d'un coup d'œil.
                'title' => 'Checklist de livraison',
                'tags' => ['méthode'],
                'folder' => 'photo',
                'appearance' => 'mint',
                'content' => <<<'MD'
                    # Checklist de livraison

                    - [x] Sélection validée par le client
                    - [x] Retouche des portraits
                    - [ ] Export web et impression
                    - [ ] Galerie en ligne
                    - [ ] Facture du solde

                    > Rien ne part avant que la ligne « facture » soit
                    > cochée.

                    Le cadre contractuel est dans [[Contrat type]].
                    MD,
            ],
            'calendrier' => [
                'title' => 'Calendrier éditorial',
                'tags' => ['éditorial', 'planning'],
                'folder' => 'editorial',
                'appearance' => 'slate',
                'cover' => 15635240,
                'coverCredit' => 'Walls.io',
                'content' => <<<'MD'
                    # Calendrier éditorial

                    ## Avril
                    - Un article sur le repérage, tiré de [[Séance en extérieur]]
                    - Deux publications atelier

                    ## Mai
                    - Le devis expliqué, à partir de [[Contrat type]]
                    - Un avant/après de retouche

                    Les sujets en vrac restent dans [[Idées d'articles]].
                    MD,
            ],
            'archives' => [
                'title' => 'Tarifs 2024',
                'tags' => ['archive'],
                'folder' => 'archives',
                'appearance' => 'midnight',
                'content' => <<<'MD'
                    # Tarifs 2024

                    Gardés pour mémoire, plus appliqués depuis janvier.

                    - Séance courte : 450 €
                    - Journée : 1 200 €
                    MD,
            ],
            'brouillon' => [
                // À la corbeille : l'écran global en montrait une liste
                // vide, donc personne ne voyait ce qu'il sait faire.
                'title' => 'Brouillon abandonné',
                'tags' => [],
                'trashed' => true,
                'content' => <<<'MD'
                    # Brouillon abandonné

                    Trois lignes commencées un soir, jamais reprises.
                    MD,
            ],
        ];
    }
}
