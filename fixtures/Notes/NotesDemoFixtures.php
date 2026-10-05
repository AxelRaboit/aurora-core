<?php

declare(strict_types=1);

namespace Aurora\Fixtures\Notes;

use Aurora\Fixtures\Core\AppFixtures;
use Aurora\Fixtures\Core\CoreDemoFixtures;
use Aurora\Module\Notes\Favorite\Entity\NoteFavorite;
use Aurora\Module\Notes\Folder\Entity\NoteFolder;
use Aurora\Module\Notes\Markdown\Entity\MarkdownNote;
use Aurora\Module\Notes\Markdown\Entity\MarkdownNoteRevision;
use Aurora\Module\Notes\Markdown\Enum\NoteAppearanceEnum;
use Aurora\Module\Notes\Markdown\Service\MarkdownNoteImageService;
use Aurora\Module\Notes\Share\Manager\MarkdownNoteShareLinkManagerInterface;
use Aurora\Module\Notes\Share\Repository\MarkdownNoteShareLinkRepository;
use Aurora\Module\Notes\Space\Entity\NoteSpace;
use Aurora\Module\Notes\Space\Entity\NoteSpaceMember;
use Aurora\Module\Notes\Space\Enum\NoteSpaceAccessEnum;
use Aurora\Module\Notes\Space\Enum\NoteSpaceRoleEnum;
use Aurora\Module\Notes\Space\Service\NoteSpaceAccess;
use Aurora\Module\Platform\User\Entity\CoreUserInterface;
use Aurora\Module\Platform\User\Entity\User;
use Aurora\Module\Platform\User\Enum\UserTypeEnum;
use Aurora\Module\Platform\User\Repository\UserRepository;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Bundle\FixturesBundle\FixtureGroupInterface;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ObjectManager;
use ReflectionProperty;
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
        private readonly NoteSpaceAccess $spaces,
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
            'type' => UserTypeEnum::Suite->value,
        ]);

        if (!$owner instanceof User) {
            throw new RuntimeException('The demo suite account is missing - run the core fixtures first.');
        }

        // Tout le carnet de démo vit dans l'espace personnel du compte.
        $space = $this->spaces->personalSpace($owner);

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

        // Dossiers et notes d'un même dossier partagent un seul ordre depuis
        // la 0.9.331 : un compteur par dossier, que les deux font avancer.
        // Une note marquée `first` passe devant tout, dossiers compris :
        // c'est ce que l'arborescence sait montrer, et la démonstration doit
        // le montrer aussi.
        $positions = [];
        $next = static function (?string $group) use (&$positions): int {
            $key = $group ?? '';

            return $positions[$key] = ($positions[$key] ?? 0) + 1;
        };

        /** @var list<array{string, NoteFolder|MarkdownNote, DateTimeImmutable}> $pinned */
        $pinned = [];

        // Les parents sont déclarés avant leurs enfants, donc une seule
        // passe suffit : un dossier ne peut pointer que vers un dossier
        // déjà construit.
        foreach ($this->folders() as $key => $definition) {
            $folder = $existingFolders[$definition['name']] ?? new NoteFolder();
            $folder
                ->setUser($owner)
                ->setSpace($space)
                ->setName($definition['name'])
                ->setColor($definition['color'] ?? null)
                ->setParent(isset($definition['parent']) ? $folders[$definition['parent']] : null)
                ->setPosition($next($definition['parent'] ?? null));

            $manager->persist($folder);
            $folders[$key] = $folder;

            if ($definition['favorite'] ?? false) {
                $pinned[] = ['folder', $folder, new DateTimeImmutable('-3 days')];
            }
        }

        $notes = [];

        foreach ($this->notes() as $key => $definition) {
            $note = $existing[$definition['title']] ?? new MarkdownNote();

            $note
                ->setUser($owner)
                ->setSpace($space)
                ->setTitle($definition['title'])
                ->setContent($this->withImages($definition, $note, $owner))
                ->setTags($definition['tags'])
                ->setPosition(($definition['first'] ?? false) ? 0 : $next($definition['folder'] ?? null))
                ->setTemplate($definition['template'] ?? false)
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

            if ($definition['favorite'] ?? false) {
                $pinned[] = ['note', $note, new DateTimeImmutable('-2 days')];
            }

            // Une note à la corbeille, pour que l'écran global en montre
            // une. Reposée à chaque exécution : elle est le décor, pas le
            // résultat d'un geste qu'on voudrait conserver.
            $note->setDeletedAt(
                ($definition['trashed'] ?? false) ? new DateTimeImmutable('-1 day') : null,
            );

            $manager->persist($note);
            $notes[$key] = $note;
        }

        if (isset($notes['verrier'])) {
            $this->history($manager, $owner, $notes['verrier']);
        }

        $manager->flush();

        $this->age($manager, $notes, $this->notes());

        $this->pin($manager, $owner, $pinned);

        $this->shareLinkFor($notes['clients'] ?? null);

        $this->teamSpace($manager, $owner);
    }

    /**
     * Un espace partagé, pour que le panneau montre ses sections et que la
     * lecture publique ait quelque chose à lire.
     *
     * Le guide d'une petite agence : ouvert à tout le back-office en lecture,
     * Marie y écrit, Jean le lit, et il est publié sur le web. Retrouvé par
     * son adresse publique à chaque exécution, donc jamais en double.
     */
    private function teamSpace(EntityManagerInterface $manager, User $owner): void
    {
        $space = $manager->getRepository(NoteSpace::class)->findOneBy(['slug' => 'guide-agence']) ?? new NoteSpace();
        $space->setOwner($owner)
            ->setName("Guide de l'agence")
            ->setColor('#14b8a6')
            ->setAccess(NoteSpaceAccessEnum::Backoffice)
            ->setDefaultRole(NoteSpaceRoleEnum::Reader)
            ->setSlug('guide-agence')
            ->setIndexable(false)
            ->setDeletedAt(null);

        if (null === $space->getPublishedAt()) {
            $space->setPublishedAt(new DateTimeImmutable('-2 days'));
        }

        $manager->persist($space);

        foreach (['marie.dupont@aurora.app' => NoteSpaceRoleEnum::Editor, 'jean.martin@aurora.app' => NoteSpaceRoleEnum::Reader] as $email => $role) {
            $user = $this->userRepository->findOneBy(['email' => $email, 'type' => UserTypeEnum::Suite->value]);
            if (!$user instanceof User) {
                continue;
            }

            $member = $manager->getRepository(NoteSpaceMember::class)->findOneBy(['space' => $space, 'user' => $user]) ?? new NoteSpaceMember();
            $member->setSpace($space)->setUser($user)->setRole($role);
            $manager->persist($member);
        }

        $manager->flush();

        $folder = $manager->getRepository(NoteFolder::class)->findOneBy(['space' => $space, 'parent' => null]) ?? new NoteFolder();
        $folder->setUser($owner)->setSpace($space)->setName('Procédures')->setColor('#14b8a6')->setPosition(1)->setParent(null);
        $manager->persist($folder);

        $existing = [];
        foreach ($manager->getRepository(MarkdownNote::class)->findBy(['space' => $space]) as $note) {
            $existing[(string) $note->getTitle()] = $note;
        }

        // Le sommaire passe devant le dossier, à la racine de l'espace ; les
        // procédures se suivent dans leur dossier.
        $position = 1;
        $team = [];
        $definitions = [];
        foreach ($this->teamNotes() as $index => $definition) {
            $note = $existing[$definition['title']] ?? new MarkdownNote();
            $note->setUser($owner)
                ->setSpace($space)
                ->setTitle($definition['title'])
                ->setContent($definition['content'])
                ->setTags($definition['tags'])
                ->setPosition(($definition['first'] ?? false) ? 0 : $position++)
                ->setFolder($definition['inFolder'] ? $folder : null)
                ->setDeletedAt(null);
            $manager->persist($note);
            $team[$index] = $note;
            $definitions[$index] = $definition;
        }

        $manager->flush();

        $this->age($manager, $team, $definitions);
    }

    /**
     * Le guide d'une petite agence, lu par toute l'équipe et publié sur le web.
     *
     * Chaque note montre une forme différente de ce que le rendu sait faire :
     * un sommaire avec ses encadrés, une procédure numérotée, un tableau de
     * rituels, une liste à cocher, et une procédure technique avec ses blocs
     * de code. Le sommaire passe devant le dossier (`first`) : l'ordre libre
     * se voit dès l'arborescence.
     *
     * @return list<array{title: string, age: string, tags: list<string>, inFolder: bool, first?: bool, content: string}>
     */
    private function teamNotes(): array
    {
        return [
            [
                'title' => "Bienvenue dans l'agence",
                'age' => '-10 days',
                'tags' => ['onboarding'],
                'inFolder' => false,
                'first' => true,
                'content' => <<<'MD'
                    # Bienvenue dans l'agence

                    Ce guide rassemble ce qu'on se répète : comment on accueille un client, comment on produit, comment on livre. Il est ouvert à toute l'équipe en lecture ; Marie le tient à jour.

                    > [!tip] Par où commencer
                    > Lisez les trois procédures dans l'ordre : elles suivent la vie d'un projet, du premier appel à la mise en ligne.

                    ## Les procédures

                    1. [[Accueillir un nouveau client]] : la première semaine, jour par jour.
                    2. [[Les rituels de la semaine]] : ce qui revient chaque lundi, mercredi et vendredi.
                    3. [[Livrer une série de contenus]] : la liste à cocher avant d'envoyer.
                    4. [[Mettre un site en ligne]] : la procédure technique, commandes comprises.

                    ## Qui fait quoi

                    | Domaine | Référente | Joignable |
                    | --- | --- | --- |
                    | Développement web | Axel | soir et week-end |
                    | Photographie | Marie | du mardi au samedi |
                    | Réseaux sociaux | Jean | en semaine |

                    > [!question] Une question sans réponse ici ?
                    > Elle mérite une note. Écrivez-la dans ce guide, même en trois lignes : la suivante qui se la posera vous remerciera.
                    MD,
            ],
            [
                'title' => 'Accueillir un nouveau client',
                'age' => '-6 days 3 hours',
                'tags' => ['onboarding', 'client'],
                'inFolder' => true,
                'content' => <<<'MD'
                    # Accueillir un nouveau client

                    La première semaine décide du reste du projet : un client qui sait à qui parler et quand il sera relu ne relance pas.

                    ## Jour 1

                    1. Ouvrir son espace client et lui envoyer le lien d'accès.
                    2. Y poser le contrat signé et la facture d'acompte.

                    ## Jours 2 à 5

                    1. Caler l'appel de lancement, une heure, en visio.
                    2. Poser le brief et le calendrier du premier mois, à partir du modèle « Brief de projet ».
                    3. Rappeler qui valide, et sous quel délai.

                    > [!warning] Le délai de validation
                    > Sans réponse sous cinq jours ouvrés, une proposition est réputée validée. Le dire dès l'appel de lancement, pas au premier retard.

                    Le rythme de production est décrit dans [[Les rituels de la semaine]].
                    MD,
            ],
            [
                'title' => 'Les rituels de la semaine',
                'age' => '-2 days',
                'tags' => ['organisation'],
                'inFolder' => true,
                'content' => <<<'MD'
                    # Les rituels de la semaine

                    Trois rendez-vous courts plutôt qu'une longue réunion. Chacun a son heure, sa durée et ce qu'on en sort.

                    | Jour | Rituel | Durée | Ce qu'on en sort |
                    | --- | --- | ---: | --- |
                    | Lundi 9 h | Point de production | 20 min | les priorités de la semaine |
                    | Mercredi 14 h | Relecture croisée | 45 min | les textes et visuels validés en interne |
                    | Vendredi 16 h | Envoi des validations | 15 min | un message par client, pas plus |

                    ## Avant le point du lundi

                    - [ ] Relire les retours clients de la semaine passée
                    - [ ] Mettre à jour les fiches dans les espaces clients
                    - [ ] Noter ce qui bloque, et qui peut le débloquer

                    > [!note] Absente un jour de rituel ?
                    > On ne déplace pas : on lit le compte rendu, rangé le jour même dans le dossier du client.

                    Le sommaire du guide : [[Bienvenue dans l'agence]].
                    MD,
            ],
            [
                'title' => 'Livrer une série de contenus',
                'age' => '-1 day 4 hours',
                'tags' => ['production', 'client'],
                'inFolder' => true,
                'content' => <<<'MD'
                    # Livrer une série de contenus

                    Une série part quand chaque ligne est cochée. Pas avant, même quand le client attend.

                    ## Les textes

                    - [x] Relus par une deuxième personne
                    - [x] Orthographe et liens vérifiés
                    - [ ] Accroches testées sur mobile

                    ## Les visuels

                    - [x] Exportés aux formats de chaque réseau
                    - [ ] Textes alternatifs écrits
                    - [ ] Crédits photo vérifiés

                    ## L'envoi

                    - [ ] Fiches posées dans l'espace du client, à la bonne date
                    - [ ] Validation demandée, avec le délai rappelé

                    > [!danger] Une reprise demandée par message
                    > Elle se note sur la fiche, jamais dans un message : sinon elle se perd, et c'est la version d'avant qui part. Voir [[Accueillir un nouveau client]].
                    MD,
            ],
            [
                'title' => 'Mettre un site en ligne',
                'age' => '-3 hours',
                'tags' => ['web', 'procédure'],
                'inFolder' => true,
                'content' => <<<'MD'
                    # Mettre un site en ligne

                    La procédure de chaque livraison d'un site, de la branche validée au site qui répond. Elle se suit dans l'ordre, sans en sauter une étape.

                    > [!abstract] En résumé
                    > On ne déploie qu'un tag, jamais une branche. Le tag dit exactement ce qui tourne, et revenir en arrière ne demande qu'une commande.

                    ## 1. Préparer la version

                    ```bash
                    git switch main && git pull
                    make test
                    git tag v2.4.1 && git push --tags
                    ```

                    ## 2. Déployer sur le serveur

                    ```bash
                    ssh client-verrier
                    cd /var/www/site && git fetch --tags
                    git checkout v2.4.1 && make deploy
                    ```

                    ## 3. Vérifier

                    - [ ] La page d'accueil répond en moins de deux secondes
                    - [ ] Le formulaire de contact envoie bien son message
                    - [ ] La version affichée en pied de page est la bonne

                    > [!failure] Si quelque chose casse
                    > On revient au tag précédent d'abord, on comprend ensuite : `git checkout v2.4.0 && make deploy`. Le client voit un site qui marche pendant qu'on cherche.

                    Le cadre de la maintenance est dans la fiche du client, rangée dans le carnet de chacun. Retour au [[Bienvenue dans l'agence|sommaire du guide]].
                    MD,
            ],
        ];
    }

    /**
     * Les versions passées de la fiche du cabinet, pour que l'historique
     * s'ouvre sur quelque chose : trois états, du plus ancien au plus récent,
     * chacun avec ce qui a changé depuis.
     *
     * Reposées à chaque exécution : le décor, pas des versions qu'on voudrait
     * garder d'une démonstration à l'autre. Chaque version se construit en
     * retirant au texte courant ce qui lui a été ajouté depuis, pour que les
     * trois restent d'accord avec lui quand on le retouche.
     */
    private function history(EntityManagerInterface $manager, User $owner, MarkdownNote $note): void
    {
        if (null !== $note->getId()) {
            $manager->createQuery(sprintf('DELETE FROM %s r WHERE r.note = :note', MarkdownNoteRevision::class))
                ->setParameter('note', $note)
                ->execute();
        }

        $current = (string) $note->getContent();

        // La veille : tout, sauf l'encadré sur les accès.
        $yesterday = (string) preg_replace('/\n> \[!info\] Accès\n> [^\n]*\n/', "\n", $current);
        // Il y a cinq jours : pas encore de section technique.
        $fiveDays = (string) preg_replace('/## Technique\n.*?(?=\nLe cadre contractuel)/s', '', $yesterday);
        // Il y a douze jours : les fiches chantier en cours, et pas encore
        // l'avertissement de Paul.
        $twelveDays = str_replace(
            '- [x] Reprise des fiches chantier, 24 au total',
            '- [ ] Reprise des fiches chantier, 18 sur 24',
            (string) preg_replace('/> \[!warning\] Avant toute mise en ligne\n(> [^\n]*\n)+\n/', '', $fiveDays),
        );

        $createdAt = new ReflectionProperty(MarkdownNoteRevision::class, 'createdAt');
        // Des heures de Paris : la colonne est en UTC, et l'écran les rend
        // dans le fuseau du site. Sans fuseau, « 21:40 » s'affichait 23:40.
        $paris = new DateTimeZone('Europe/Paris');

        foreach ([[$twelveDays, '-12 days 10:30'], [$fiveDays, '-5 days 18:05'], [$yesterday, '-1 day 21:40']] as [$content, $when]) {
            $note->setContent($content);
            $revision = new MarkdownNoteRevision($note, $owner);
            $createdAt->setValue($revision, new DateTimeImmutable($when, $paris)->setTimezone(new DateTimeZone('UTC')));
            $manager->persist($revision);
        }

        $note->setContent($current);
    }

    /**
     * L'âge de chaque note : la date de modification que la liste et
     * « Récemment modifiées » affichent.
     *
     * Toutes portaient l'heure du chargement, la même à la minute près, et la
     * vue en liste montrait une colonne de dates identiques. Posées après
     * coup, en base : l'horodatage de l'entité se réécrit à chaque
     * enregistrement.
     *
     * @param array<array-key, MarkdownNote>        $notes
     * @param array<array-key, array{age?: string}> $definitions
     */
    private function age(EntityManagerInterface $manager, array $notes, array $definitions): void
    {
        foreach ($notes as $key => $note) {
            $at = new DateTimeImmutable($definitions[$key]['age'] ?? '-20 days');

            $manager->createQuery(sprintf('UPDATE %s n SET n.updatedAt = :at, n.createdAt = :created WHERE n.id = :id', MarkdownNote::class))
                ->setParameter('at', $at)
                ->setParameter('created', $at->modify('-9 days'))
                ->setParameter('id', $note->getId())
                ->execute();
        }
    }

    /**
     * Les favoris du compte de démo : ils sont à la personne, dans leur
     * table, et reposés à chaque exécution comme le reste du décor.
     *
     * @param list<array{string, NoteFolder|MarkdownNote, DateTimeImmutable}> $pinned
     */
    private function pin(EntityManagerInterface $manager, User $owner, array $pinned): void
    {
        $repository = $manager->getRepository(NoteFavorite::class);

        foreach ($pinned as [$kind, $item, $at]) {
            $favorite = $repository->findOneBy(['user' => $owner, $kind => $item]) ?? new NoteFavorite();
            $favorite->setUser($owner)->setCreatedAt($at);
            if ($item instanceof MarkdownNote) {
                $favorite->setNote($item);
            } else {
                $favorite->setFolder($item);
            }

            $manager->persist($favorite);
        }

        $manager->flush();
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
            // Les modèles d'une fiche : « Partir de » les propose dans
            // « Ajouter ».
            'modeles' => ['name' => 'Modèles', 'color' => '#ec4899'],
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
                '/suite/notes/markdown/images/'.$filename,
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
     * Le carnet d'un petit studio : développement web, photographie, réseaux
     * sociaux.
     *
     * Réécrit le 03/10/2026 pour le tour : les notes tenaient en quatre lignes
     * et montraient surtout que le module était vide. Chacune montre
     * maintenant une forme que le rendu sait faire, et le carnet entier les
     * réunit toutes : encadrés de plusieurs couleurs, tableaux alignés, listes
     * à cocher par section, blocs de code colorés, images à leur taille,
     * titres sur trois niveaux pour le plan, liens `[[…]]` pour le graphe.
     *
     * - « Cabinet Verrier » est la vitrine de l'éditeur : le plan y a de quoi
     *   se déplier, et c'est elle qui porte l'historique (`history()`).
     * - « Sommaire des clients » passe devant le dossier « Studio Lumen »
     *   (`first`) : l'ordre libre se voit dans l'arborescence.
     * - Les deux notes du dossier « Modèles » sont des modèles : « Ajouter »
     *   les propose sous « Partir de ».
     * - `age` donne à chaque note sa date de modification.
     *
     * @return array<string, array{title: string, content: string, tags: list<string>, folder?: string, first?: bool, template?: bool, age?: string, cover?: int, coverCredit?: string, coverPosition?: int, appearance?: string, favorite?: bool, trashed?: bool, images?: list<int>}>
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
                'first' => true,
                'favorite' => true,
                'age' => '-2 hours',
                // Un atelier clair et ses tables de travail. La photo d'avant
                // montrait une affiche au logo de Pexels en plein milieu, et
                // c'est elle qui ouvrait la page de partage et la lecture.
                'cover' => 4348298,
                'coverCredit' => 'Antoni Shkraba',
                'coverPosition' => 60,
                'appearance' => 'paper',
                'content' => <<<'MD'
                    # Sommaire des clients

                    Le point d'entrée du carnet : chaque client a sa fiche, et chaque fiche renvoie ici. Le cadre contractuel part du [[Contrat type]].

                    > [!abstract] Cette semaine
                    > **Trois clients actifs**, deux projets en cours, une relance à faire. Prochaine échéance : la séance catalogue du [[Studio Lumen]], le 14 novembre.

                    ## Clients actifs

                    | Client | Métier | Depuis | Ce qui revient |
                    | --- | --- | ---: | --- |
                    | [[Studio Lumen]] | photographie | 2024 | deux séances, un catalogue |
                    | [[Cabinet Verrier]] | site vitrine | 2025 | maintenance mensuelle |
                    | Boulangerie Fournier | réseaux sociaux | 2026 | douze publications par mois |

                    ## À relancer

                    - [x] Facture de septembre, Studio Lumen
                    - [ ] Photos de la galerie, Cabinet Verrier
                    - [ ] Devis de la Boulangerie Fournier, à partir des [[Tarifs 2024]] revus

                    > [!tip] Une nouvelle fiche
                    > « Ajouter », puis « Partir de » le [[Brief de projet]] : la fiche arrive avec ses sections, et la date du jour déjà écrite.
                    MD,
            ],
            'lumen' => [
                'title' => 'Studio Lumen',
                'tags' => ['client', 'photo'],
                'folder' => 'lumen',
                'age' => '-3 days',
                'content' => <<<'MD'
                    # Studio Lumen

                    Un studio de design à Lyon, six personnes. Deux séances par an : le catalogue au printemps, les portraits d'équipe à l'automne. Interlocutrice : Camille, qui décide vite.

                    > [!quote] Ce que Camille a dit au premier rendez-vous
                    > « On veut des images qui ressemblent à l'atelier un mardi matin, pas à une publicité. »

                    ## Les séances

                    | Séance | Date | Lieu | État |
                    | --- | --- | --- | --- |
                    | Catalogue printemps | 18 avril | atelier | livrée |
                    | Portraits d'équipe | 14 novembre | extérieur | à venir |

                    ## Les conditions

                    Reprises du [[Contrat type]], avec une clause de cession élargie aux réseaux sociaux. Le chiffrage de l'année est dans le [[Devis Lumen 2026]], et le lieu de la prochaine séance dans le [[Repérage Lumen]].

                    Voir aussi [[Séance en extérieur]] : c'est le format qu'ils redemandent.
                    MD,
            ],
            'verrier' => [
                'title' => 'Cabinet Verrier',
                'tags' => ['client', 'web'],
                'folder' => 'clients',
                'age' => '-25 minutes',
                'cover' => 923307,
                'coverCredit' => 'Julien Bachelet',
                // La vitrine de l'éditeur, et c'est délibéré : c'est elle que
                // la première image de la carte photographie, en écriture avec
                // son rendu à côté, et c'est elle que le plan et l'historique
                // montrent. Des titres sur trois niveaux, un encadré, un
                // tableau aligné, un bloc de code et une image à sa taille :
                // tout ce que le rendu sait faire, dans une vraie fiche.
                'content' => <<<'MD'
                    # Cabinet Verrier

                    Trois architectes associés à Nantes, et un site vitrine qui montre leurs chantiers livrés. En ligne depuis mars, maintenance au forfait. Interlocuteur : Paul, qui relit tout.

                    ![Le dernier chantier livré, en tête de la page d'accueil|520]({{image:0}})

                    > [!warning] Avant toute mise en ligne
                    > Paul veut être prévenu, même pour une correction de texte : le cabinet répond à des appels d'offres, et le site lui sert de référence.

                    ## Où ça en est

                    ### Livré

                    - [x] Refonte de la page d'accueil
                    - [x] Reprise des fiches chantier, 24 au total

                    ### En cours

                    - [ ] Galerie avant / après, en attente des photos
                    - [ ] Formulaire de contact en trois langues

                    ## Le forfait

                    | Poste | Rythme | Montant |
                    | --- | --- | ---: |
                    | Maintenance | mensuel | 180 € |
                    | Sauvegardes | quotidien | inclus |
                    | Petites évolutions | 2 h par mois | inclus |

                    Au-delà des deux heures, c'est du temps facturé au tarif courant, annoncé avant d'être engagé.

                    ## Technique

                    Hébergé chez eux, déployé depuis un tag :

                    ```bash
                    git fetch --tags
                    git checkout v2.4.1 && make deploy
                    ```

                    > [!info] Accès
                    > Les identifiants sont dans le coffre partagé, jamais dans une note.

                    Le cadre contractuel est celui du [[Contrat type]], et la fiche remonte au [[Sommaire des clients]].
                    MD,
                'images' => [9458996],
            ],
            'contrat' => [
                'title' => 'Contrat type',
                'tags' => ['contrat', 'client'],
                'appearance' => 'sepia',
                'age' => '-16 days',
                'content' => <<<'MD'
                    # Contrat type

                    Le socle commun de chaque mission, à adapter par client. Ce qui change d'un contrat à l'autre est en italique.

                    ## 1. Commande

                    - Acompte de **30 %** à la signature, le solde à la livraison.
                    - Devis valable *un mois*, au-delà les tarifs sont revus.

                    ## 2. Allers-retours

                    Deux allers-retours inclus sur chaque livrable. Le troisième se facture au temps passé, annoncé avant d'être engagé.

                    ## 3. Droits

                    Cession des droits à la livraison et au paiement du solde, pour *l'usage précisé au cas par cas* : site, réseaux, impression.

                    > [!note] Ce qui ne se négocie pas
                    > Le délai de validation de cinq jours ouvrés, et la mention de l'auteur des photos quand elles sont publiées.

                    Retour au [[Sommaire des clients]].
                    MD,
            ],
            'brief' => [
                'title' => 'Brief de projet',
                'tags' => ['modèle'],
                'folder' => 'modeles',
                'template' => true,
                'age' => '-8 days',
                'cover' => 5668471,
                'coverCredit' => 'Sora Shimazaki',
                // Un modèle : « Partir de » le propose, et la date du jour
                // remplace le repère à la création.
                'content' => <<<'MD'
                    # Brief de projet

                    Rédigé le {{date}}.

                    ## Le client

                    - **Qui** :
                    - **Interlocuteur** :
                    - **Ce qu'il fait, en une phrase** :

                    ## Le besoin

                    > [!question] La question à poser en premier
                    > Qu'est-ce qui doit changer pour vous dans six mois, si ce projet réussit ?

                    ## Les livrables

                    | Livrable | Format | Échéance |
                    | --- | --- | --- |
                    |  |  |  |

                    ## Avant de commencer

                    - [ ] Devis signé
                    - [ ] Acompte reçu
                    - [ ] Espace client ouvert
                    - [ ] Rendez-vous de lancement calé
                    MD,
            ],
            'compte-rendu' => [
                'title' => 'Compte rendu de rendez-vous',
                'tags' => ['modèle'],
                'folder' => 'modeles',
                'template' => true,
                'age' => '-8 days 2 hours',
                'content' => <<<'MD'
                    # Compte rendu du {{date}}

                    **Présents** :

                    ## Ce qu'on a décidé

                    > [!success] Décisions
                    > Une décision par ligne, avec sa raison.

                    ## Qui fait quoi

                    - [ ] Qui : quoi, pour quand
                    - [ ] Qui : quoi, pour quand

                    ## Prochain rendez-vous

                    La date, le lieu, et ce qu'on y apporte.
                    MD,
            ],
            'seance' => [
                'title' => 'Séance en extérieur',
                'tags' => ['photo', 'méthode'],
                'folder' => 'photo',
                'age' => '-6 days',
                // Une photo en hauteur, coupée haut : c'est le cas qui
                // justifie le réglage de cadrage, un portrait montrant un
                // menton quand on le centre.
                'cover' => 35256272,
                'coverCredit' => 'Alef Morais',
                'coverPosition' => 30,
                'content' => <<<'MD'
                    # Séance en extérieur

                    La méthode qu'on suit pour chaque séance hors de l'atelier : un repérage la veille, la lumière de fin de journée, et une heure de battement pour la météo.

                    Le Studio Lumen redemande ce format à chaque fois, noté ici sans lien exprès pour voir ce que donne une mention non liée.

                    ## Le déroulé

                    | Heure | Étape | Durée |
                    | --- | --- | ---: |
                    | 16 h 30 | Arrivée, réglages, essais de lumière | 30 min |
                    | 17 h | Portraits individuels | 1 h |
                    | 18 h | Photo de groupe | 20 min |
                    | 18 h 30 | Battement pour la météo | 1 h |

                    > [!tip] La lumière
                    > L'heure qui précède le coucher du soleil donne une lumière chaude et douce, sans ombre dure sous les yeux. On la prévoit d'après l'éphéméride, pas d'après l'heure habituelle.

                    ![Le repérage de la veille, même heure|420]({{image:0}})

                    Le sac est décrit dans [[Matériel]].
                    MD,
                'images' => [1181244],
            ],
            'materiel' => [
                'title' => 'Matériel',
                'tags' => ['photo'],
                'folder' => 'photo',
                'age' => '-11 days',
                'cover' => 18880006,
                'coverCredit' => 'Amar Preciado',
                'content' => <<<'MD'
                    # Matériel

                    Ce qui part dans le sac pour une [[Séance en extérieur]], et rien de plus : un sac léger se porte jusqu'au bon endroit.

                    ## Les boîtiers

                    - [x] Boîtier principal
                    - [x] Second boîtier, en secours
                    - [x] Quatre batteries chargées la veille

                    ## Les optiques

                    - [x] 35 mm, pour les groupes et le décor
                    - [x] 85 mm, pour les portraits
                    - [ ] Rien d'autre en extérieur

                    ## La lumière

                    - [x] Réflecteur pliant, plus utile qu'un flash
                    - [ ] Diffuseur, si le ciel est trop clair

                    > [!warning] Les batteries
                    > Le froid les vide deux fois plus vite. En hiver, elles voyagent dans une poche intérieure, pas dans le sac.
                    MD,
            ],
            'idees' => [
                'title' => 'Idées d\'articles',
                'tags' => ['éditorial'],
                'folder' => 'editorial',
                'favorite' => true,
                'age' => '-4 days',
                'content' => <<<'MD'
                    # Idées d'articles

                    Les sujets en vrac, avant qu'ils passent au [[Calendrier éditorial]].

                    ## Photographie

                    - Ce qu'on regarde dans un devis de photographe
                    - Le repérage, cette étape qu'on saute toujours

                    ## Web

                    - Pourquoi un site lent coûte des clients
                    - Ce que le pied de page d'un site dit de son sérieux

                    > [!question] À trancher
                    > Un article par mois, ou deux plus courts ? On décide au point du lundi.
                    MD,
            ],
            'devis' => [
                'title' => 'Devis Lumen 2026',
                'tags' => ['client', 'devis'],
                'folder' => 'lumen',
                'age' => '-9 days',
                'content' => <<<'MD'
                    # Devis Lumen 2026

                    Deux séances, le catalogue au printemps et les portraits à l'automne. Le cadre est celui du [[Contrat type]].

                    | Poste | Quantité | Prix |
                    | --- | ---: | ---: |
                    | Séance catalogue | 1 | 1 400 € |
                    | Portraits d'équipe | 12 | 900 € |
                    | Retouche | forfait | 300 € |
                    | **Total** |  | **2 600 €** |

                    > [!success] Accepté le 18 mars
                    > Acompte reçu le 21. Le solde se facture après chaque séance, au prorata.
                    MD,
            ],
            'reperage' => [
                'title' => 'Repérage Lumen',
                'tags' => ['photo', 'méthode', 'client'],
                'folder' => 'lumen',
                'age' => '-7 days',
                'content' => <<<'MD'
                    # Repérage Lumen

                    Leur atelier donne au nord : lumière égale toute la journée, aucune ombre dure. Le mur de briques du fond fait un décor à lui seul.

                    ## Les trois endroits retenus

                    1. **Le mur de briques**, pour les portraits individuels.
                    2. **La grande table**, pour la photo de groupe au travail.
                    3. **La cour**, si le temps le permet, pour finir dehors.

                    > [!info] Accès
                    > L'atelier ouvre à 8 h 30. Camille prévient l'équipe la veille pour que les bureaux soient rangés.

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
                'age' => '-1 day',
                'content' => <<<'MD'
                    # Checklist de livraison

                    - [x] Sélection validée par le client
                    - [x] Retouche des portraits
                    - [ ] Export web et impression
                    - [ ] Galerie en ligne
                    - [ ] Facture du solde

                    > [!danger] Rien ne part avant la facture
                    > La ligne « facture du solde » se coche avant l'envoi de la galerie, pas après.

                    Le cadre contractuel est dans le [[Contrat type]].
                    MD,
            ],
            'calendrier' => [
                'title' => 'Calendrier éditorial',
                'tags' => ['éditorial', 'planning'],
                'folder' => 'editorial',
                'appearance' => 'slate',
                'age' => '-5 hours',
                'cover' => 15635240,
                'coverCredit' => 'Walls.io',
                'content' => <<<'MD'
                    # Calendrier éditorial

                    Ce qui sort, où et quand. Les sujets viennent des [[Idées d'articles]].

                    ## Novembre

                    | Semaine | Instagram | LinkedIn | Blog |
                    | --- | --- | --- | --- |
                    | 3 au 7 | coulisses d'une séance | le devis expliqué | |
                    | 10 au 14 | avant / après retouche | | le repérage |
                    | 17 au 21 | portrait d'équipe | un chantier livré | |

                    ## Décembre

                    - [ ] Le bilan de l'année, en carrousel
                    - [ ] Les vœux, avec une photo de l'atelier

                    > [!note] Le repérage
                    > L'article sort la semaine du 10, tiré de la [[Séance en extérieur]].
                    MD,
            ],
            'archives' => [
                'title' => 'Tarifs 2024',
                'tags' => ['archive'],
                'folder' => 'archives',
                'appearance' => 'midnight',
                'age' => '-40 days',
                'content' => <<<'MD'
                    # Tarifs 2024

                    > [!failure] Plus appliqués
                    > Gardés pour mémoire : les tarifs ont été revus en janvier.

                    | Prestation | Prix |
                    | --- | ---: |
                    | Séance courte | 450 € |
                    | Journée | 1 200 € |
                    | Site vitrine | 1 500 € |
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
