<?php

declare(strict_types=1);

namespace Aurora\Fixtures\Notes;

use Aurora\Fixtures\Core\AppFixtures;
use Aurora\Fixtures\Core\CoreDemoFixtures;
use Aurora\Module\Notes\Comment\Entity\NoteComment;
use Aurora\Module\Notes\Favorite\Entity\NoteFavorite;
use Aurora\Module\Notes\Folder\Entity\NoteFolder;
use Aurora\Module\Notes\Markdown\Entity\MarkdownNote;
use Aurora\Module\Notes\Markdown\Entity\MarkdownNoteRevision;
use Aurora\Module\Notes\Markdown\Enum\NoteAppearanceEnum;
use Aurora\Module\Notes\Markdown\Service\MarkdownNoteImageService;
use Aurora\Module\Notes\Share\Entity\MarkdownNoteMember;
use Aurora\Module\Notes\Share\Enum\NoteMemberRoleEnum;
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

use function array_map;
use function assert;
use function is_string;
use function preg_replace_callback;

/**
 * A notebook that stands on its own.
 *
 * The demo had none, and the notes screen opened on an empty editor: the
 * eight documentation pages of the section all showed the same capture of a
 * new notebook, and the link graph was a black rectangle. What it takes for
 * these screens to say something:
 *
 *  - real `[[Title]]` links, so a graph that has edges;
 *  - a note cited by two others, for the "what points here" list;
 *  - a title mentioned without brackets, for unlinked mentions;
 *  - tags, for the filter and the search;
 *  - a folder with notes in it, for the library.
 *
 * And since the folders redesign, enough to show what it added: a folder in
 * a folder (breadcrumb, depth, flat view), folder colours, favourites,
 * appearances, banners, a task list so a card's thumbnail shows something
 * other than text, and a note in the trash so the trash screen is not empty.
 *
 * And since a note can be handed over on its own: one note shared with
 * Marie as an editor and with Jean as a reader, so the guest list is not an
 * empty panel and so those two accounts have a "Partagées avec moi" group
 * with something in it; plus a share link opened for writing, so the badge
 * exists on the share screen and the guest page shows its pencil. A feature
 * nobody can see on screen is a feature nobody knows the shape of.
 *
 * Dev/test only, group `demo`.
 */
class NotesDemoFixtures extends Fixture implements DependentFixtureInterface, FixtureGroupInterface
{
    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly MarkdownNoteShareLinkManagerInterface $shareLinks,
        private readonly MarkdownNoteShareLinkRepository $shareLinkRepository,
        private readonly MarkdownNoteImageService $imageService,
        private readonly NoteSpaceAccess $spaceAccess,
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

        // The account the documentation captures. Notes are personal: filed
        // under another account, the screen opens empty for whoever takes the
        // capture, which is exactly what happened.
        $owner = $this->userRepository->findOneBy([
            'email' => 'dev@aurora.app',
            'type' => UserTypeEnum::Suite->value,
        ]);

        if (!$owner instanceof User) {
            throw new RuntimeException('The demo suite account is missing - run the core fixtures first.');
        }

        // The whole demo notebook lives in the account's personal space.
        $space = $this->spaceAccess->personalSpace($owner);

        $repository = $manager->getRepository(MarkdownNote::class);

        // Titles are encrypted in the database, so `findOneBy(['title' => …])`
        // never finds anything: the comparison would be plain text against
        // ciphertext. The user's notes are loaded once and indexed after
        // decryption, which is the only place the title exists in plain text.
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

        // Folders and notes of the same folder share a single order since
        // 0.9.331: one counter per folder, which both move forward. A note
        // marked `first` goes ahead of everything, folders included: it is
        // what the tree can show, and the demo must show it too.
        $positions = [];
        $next = static function (?string $group) use (&$positions): int {
            $key = $group ?? '';

            return $positions[$key] = ($positions[$key] ?? 0) + 1;
        };

        /** @var list<array{string, NoteFolder|MarkdownNote, DateTimeImmutable}> $pinned */
        $pinned = [];

        // Parents are declared before their children, so a single pass is
        // enough: a folder can only point to a folder already built.
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
                ->setContent($this->withDates($this->withImages($definition, $note, $owner)))
                // The emoji over the banner and the properties under the title
                // (4.6.0): the table view of the agency's folder sorts by them.
                ->setIcon($definition['icon'] ?? null)
                ->setProperties($this->properties($definition['properties'] ?? []))
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

            // A note in the trash, so the global screen shows one. Set again
            // on every run: it is scenery, not the result of an action worth
            // keeping.
            $note->setDeletedAt(
                ($definition['trashed'] ?? false) ? new DateTimeImmutable('-1 day') : null,
            );

            $manager->persist($note);
            $notes[$key] = $note;
        }

        if (isset($notes['site'])) {
            $this->history($manager, $owner, $notes['site']);
        }

        $manager->flush();

        $this->age($manager, $notes, $this->notes());

        $this->pin($manager, $owner, $pinned);

        $this->shareLinkFor($notes['sommaire'] ?? null);

        $this->handedToPeople($manager, $notes['site'] ?? null);

        $this->writableLinkFor($manager, $notes['site'] ?? null);

        $this->comments($manager, $notes['site'] ?? null);

        $this->teamSpace($manager, $owner);
    }

    /**
     * A shared space, so the panel shows its sections and the public reading
     * has something to read.
     *
     * A small agency's handbook: open to the whole back office for reading,
     * Marie writes in it, Jean reads it, and it is published on the web. Found
     * by its public address on every run, so never duplicated.
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

        // The index goes ahead of the folder, at the root of the space; the
        // procedures follow one another in their folder.
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
     * A small agency's handbook, read by the whole team and published on the web.
     *
     * Each note shows a different form of what the rendering can do: an index
     * with its callouts, a numbered procedure, a table of rituals, a checklist,
     * and a technical procedure with its code blocks. The index goes ahead of
     * the folder (`first`): the free order shows right in the tree.
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
                'tags' => ['onboarding'],
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
                'tags' => ['production'],
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
                    ssh site-agence
                    cd /var/www/site && git fetch --tags
                    git checkout v2.4.1 && make deploy
                    ```

                    ## 3. Vérifier

                    - [ ] La page d'accueil répond en moins de deux secondes
                    - [ ] Le formulaire de contact envoie bien son message
                    - [ ] La version affichée en pied de page est la bonne

                    > [!failure] Si quelque chose casse
                    > On revient au tag précédent d'abord, on comprend ensuite : `git checkout v2.4.0 && make deploy`. Le client voit un site qui marche pendant qu'on cherche.

                    Le cadre de la maintenance d'un client est noté dans son espace client, section Notes. Retour au [[Bienvenue dans l'agence|sommaire du guide]].
                    MD,
            ],
        ];
    }

    /**
     * The past versions of the firm's sheet, so the history opens on
     * something: three states, from oldest to newest, each with what changed
     * since.
     *
     * Set again on every run: scenery, not versions worth keeping from one
     * demo to the next. Each version is built by removing from the current
     * text what was added to it since, so the three stay consistent with it
     * when it is edited.
     *
     * **The middle one was written by two people**, so the history shows what
     * a co-editing session leaves behind: one version every few minutes, named
     * after everybody who was typing rather than after the single browser that
     * sent the save. A state nobody ever sees is a state nobody knows the look
     * of, and this one only happens when two people are on the same note at
     * the same moment - which no amount of clicking around a demo produces.
     */
    private function history(EntityManagerInterface $manager, User $owner, MarkdownNote $note): void
    {
        if (null !== $note->getId()) {
            $manager->createQuery(sprintf('DELETE FROM %s r WHERE r.note = :note', MarkdownNoteRevision::class))
                ->setParameter('note', $note)
                ->execute();
        }

        $current = (string) $note->getContent();

        // The day before: everything, except the callout about access.
        $yesterday = (string) preg_replace('/\n> \[!info\] Accès\n> [^\n]*\n/', "\n", $current);
        // Five days ago: no technical section yet.
        $fiveDays = (string) preg_replace('/## Technique\n.*?(?=\nLa fiche remonte)/s', '', $yesterday);
        // Twelve days ago: the project sheets in progress, and not yet
        // Marie's warning.
        $twelveDays = str_replace(
            '- [x] Reprise des fiches réalisation, 24 au total',
            '- [ ] Reprise des fiches réalisation, 18 sur 24',
            (string) preg_replace('/> \[!warning\] Avant toute mise en ligne\n(> [^\n]*\n)+\n/', '', $fiveDays),
        );

        $createdAt = new ReflectionProperty(MarkdownNoteRevision::class, 'createdAt');
        // Paris times: the column is in UTC, and the screen renders them in
        // the site's time zone. Without a time zone, "21:40" showed as 23:40.
        $paris = new DateTimeZone('Europe/Paris');

        $marie = $this->userRepository->findOneBy(['email' => 'marie.dupont@aurora.app', 'type' => UserTypeEnum::Suite->value]);
        $together = $marie instanceof User
            ? [['id' => (int) $owner->getId(), 'name' => $owner->getName()], ['id' => (int) $marie->getId(), 'name' => $marie->getName()]]
            : null;

        foreach ([
            [$twelveDays, '-12 days 10:30', null],
            [$fiveDays, '-5 days 18:05', $together],
            [$yesterday, '-1 day 21:40', null],
        ] as [$content, $when, $writtenBy]) {
            $note->setContent($content);
            $revision = new MarkdownNoteRevision($note, $owner, null, $writtenBy);
            $createdAt->setValue($revision, new DateTimeImmutable($when, $paris)->setTimezone(new DateTimeZone('UTC')));
            $manager->persist($revision);
        }

        $note->setContent($current);
    }

    /**
     * Each note's age: the modification date that the list and "Récemment
     * modifiées" display.
     *
     * They all carried the load time, the same to the minute, and the list
     * view showed a column of identical dates. Set afterwards, in the
     * database: the entity's timestamp is rewritten on every save.
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
     * The demo account's favourites: they belong to the person, in their own
     * table, and are set again on every run like the rest of the scenery.
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
     * A shared note, so the shares screen has something.
     *
     * It always opened on an empty list, so the module's most visible feature
     * - a note readable without an account - showed nowhere. The index note
     * with its links followed, because it is the case the "inclure les notes
     * liées" option exists for: sharing an index alone gives the recipient a
     * list of titles and nothing behind them.
     */
    private function shareLinkFor(?MarkdownNote $note): void
    {
        // Otherwise replayed on every `make demo`: the note is found again,
        // the link is not, and the screen would fill with one more share per
        // run.
        if (!$note instanceof MarkdownNote || [] !== $this->shareLinkRepository->findForNote($note)) {
            return;
        }

        $this->shareLinks->create(
            $note,
            includeLinked: true,
            recipientEmail: 'associee@agence.test',
            label: "Sommaire de l'agence - lecture seule",
            expiresAt: new DateTimeImmutable('+30 days'),
        );
    }

    /**
     * One note handed to two people, one who writes and one who reads.
     *
     * **Not a shared space**: that is the other half of the question and the
     * demo already shows it. This is the half that says "just this page,
     * just these two" - the note stays in a personal notebook neither of them
     * can see, and it is the only thing of it they get.
     *
     * Marie writes, Jean reads: an exemplar of each role, because a role
     * nobody ever sees on screen is a role nobody knows the effect of.
     */
    private function handedToPeople(EntityManagerInterface $manager, ?MarkdownNote $note): void
    {
        if (!$note instanceof MarkdownNote) {
            return;
        }

        $roles = [
            'marie.dupont@aurora.app' => NoteMemberRoleEnum::Editor,
            'jean.martin@aurora.app' => NoteMemberRoleEnum::Reader,
        ];

        foreach ($roles as $email => $role) {
            $person = $this->userRepository->findOneBy(['email' => $email, 'type' => UserTypeEnum::Suite->value]);
            if (!$person instanceof User) {
                continue;
            }

            // Found again on every run rather than added: `make demo` is
            // idempotent, and a second row would fail the unique key anyway.
            $member = $manager->getRepository(MarkdownNoteMember::class)
                ->findOneBy(['note' => $note, 'user' => $person]) ?? new MarkdownNoteMember();

            $member->setNote($note)->setUser($person)->setRole($role);
            $manager->persist($member);
        }

        $manager->flush();
    }

    /**
     * A share link that writes, live, so the screen shows one.
     *
     * The write badge in the list and the pencil on the guest page both only
     * exist when a link carries the switch; without one, the only way to see
     * either is to create a link by hand, which is exactly the kind of thing
     * nobody does before a capture. Live co-editing too (4.3.0): the "En
     * direct" badge, the guest's caret and face only exist on a link that
     * opens it, and a demo replayed on top of an older one turns it on for
     * the link it already has.
     */
    private function writableLinkFor(EntityManagerInterface $manager, ?MarkdownNote $note): void
    {
        if (!$note instanceof MarkdownNote) {
            return;
        }

        foreach ($this->shareLinkRepository->findForNote($note) as $existing) {
            if ($existing->canWrite()) {
                if (!$existing->allowsCoediting()) {
                    $existing->setCoediting(true);
                    $manager->flush();
                }

                return;
            }
        }

        $this->shareLinks->create(
            $note,
            includeLinked: false,
            recipientEmail: 'traduction@example.test',
            label: 'Relecture des traductions - écriture',
            expiresAt: new DateTimeImmutable('+14 days'),
            canWrite: true,
            coediting: true,
        );
    }

    /**
     * The demo's folders, parents first.
     *
     * A folder in a folder is not scenery: it is what makes the breadcrumb,
     * the depth, and the difference between the filed view and the flat view
     * exist. The colours show at a glance what the card and the menu tree do
     * with them.
     *
     * @return array<string, array{name: string, parent?: string, color?: string, favorite?: bool}>
     */
    private function folders(): array
    {
        return [
            // The agency's own projects. Clients have no folder here since
            // 10/10/2026: their notes live in their client space, and the Notes
            // module does not show them.
            'agence' => ['name' => 'Agence', 'color' => '#22c55e', 'favorite' => true],
            'studio' => ['name' => 'Studio photo', 'parent' => 'agence', 'color' => '#3b82f6'],
            'photo' => ['name' => 'Photographie', 'color' => '#f59e0b'],
            'editorial' => ['name' => 'Éditorial', 'color' => '#8b5cf6'],
            'archives' => ['name' => 'Archives'],
            // A sheet's templates: "Partir de" offers them in "Ajouter".
            'modeles' => ['name' => 'Modèles', 'color' => '#ec4899'],
        ];
    }

    /**
     * A Pexels photo's address, as the note keeps it.
     *
     * **Nothing is downloaded, here no more than anywhere else**: the demo
     * writes the address served by their CDN, exactly what the picker writes
     * when a photo is chosen. It is the most faithful demonstration of the
     * design choice - the image lives outside, the note only has its address
     * and its credit.
     *
     * The ids and names come from a real search (`aurora:ged:pexels:search`,
     * run where the key is configured), and every address was checked.
     * Making up ids would have given empty frames under a false credit, which
     * is worse than a notebook without banners.
     *
     * The credit link points to the photo's page: the licence requires naming
     * the author, and that is where one traces back to them.
     */
    /**
     * Replaces the `{{image:0}}` markers in the text with real pasted images.
     *
     * The demo notebook showed everything in the module except this: a note
     * could carry an image, but none did, so neither the library thumbnail
     * nor the public site capture showed it.
     *
     * **The photos are not in the repository.** They are pulled from the
     * Pexels CDN on load, as the banners take their address from the same
     * place - except that here the bytes are needed, since a pasted image is a
     * file on our side. Committing photos into a public repository to
     * decorate a test data set is a weight never taken back, and one of them
     * carried an "All Rights Reserved" in its metadata that has no business
     * there.
     *
     * **Without network, the note keeps its text.** A demo data set that
     * refuses to load because a CDN is slow is a broken demo data set. The
     * marker disappears, the image with it, and the rest of the notebook
     * arrives.
     *
     * **Idempotent**: if the note already carries images, the files it cites
     * are reused rather than uploading new ones on every `make demo`. Without
     * this, ten reloads would leave ten copies of the same photo in storage,
     * with nothing claiming them.
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

        $already = $this->imageService->extractFilenames($note->getContent());

        foreach ($wanted as $index => $photoId) {
            $filename = $already[$index] ?? $this->fetchImage($photoId, $owner);

            if (null === $filename) {
                // The marker goes with the line that carries it: an empty
                // `![légende]()` would show a broken icon.
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

    /**
     * `{{date:+3}}` in a note's text: a day relative to the load, so a task
     * due "in three days" is still in three days after `make demo-reset`.
     */
    private function withDates(string $content): string
    {
        return (string) preg_replace_callback(
            '/\{\{date:([+-]\d+)\}\}/',
            static fn (array $match): string => new DateTimeImmutable($match[1].' days')->format('Y-m-d'),
            $content,
        );
    }

    /**
     * A note's properties, with relative dates (`+12`) and people named by
     * their address, which the demo accounts keep from one load to the next.
     *
     * @param list<array{key: string, type: string, value: mixed}> $properties
     *
     * @return list<array{key: string, type: string, value: mixed}>
     */
    private function properties(array $properties): array
    {
        return array_map(function (array $property): array {
            $value = $property['value'];
            if ('date' === $property['type'] && is_string($value) && 1 === preg_match('/^[+-]\d+$/', $value)) {
                $value = new DateTimeImmutable($value.' days')->format('Y-m-d');
            }

            if ('person' === $property['type'] && is_string($value)) {
                // The suite account: the public site has its own accounts, some
                // with the same address.
                $value = $this->userRepository->findOneBy(['email' => $value, 'type' => UserTypeEnum::Suite->value])?->getId();
            }

            return ['key' => $property['key'], 'type' => $property['type'], 'value' => $value];
        }, $properties);
    }

    /**
     * Two threads on the firm's note (4.6.0): Marie asks about a passage and
     * the account answers, mentioning her; a guest of the writing link
     * confirms the next one. Rewritten on every run, so never duplicated.
     */
    private function comments(EntityManagerInterface $manager, ?MarkdownNote $note): void
    {
        if (!$note instanceof MarkdownNote || null === $note->getId()) {
            return;
        }

        foreach ($manager->getRepository(NoteComment::class)->findBy(['note' => $note]) as $old) {
            $manager->remove($old);
        }

        $manager->flush();

        $owner = $note->getUser();
        $marie = $this->userRepository->findOneBy(['email' => 'marie.dupont@aurora.app', 'type' => UserTypeEnum::Suite->value]);

        $question = new NoteComment();
        $question->setNote($note)->setAuthor($marie)
            ->setQuote('Galerie avant / après, en attente des photos')
            ->setBody('Léa a envoyé les photos de l\'atelier hier soir. On les met en ligne cette semaine ?');
        $manager->persist($question);

        $answer = new NoteComment();
        $answer->setNote($note)->setAuthor($owner)->setParent($question)
            ->setBody(sprintf('Oui, jeudi. @[%s](user:%d) peux-tu préparer les recadrages en 4:3 ?', $marie?->getName() ?? 'Marie', (int) $marie?->getId()));
        $manager->persist($answer);

        $guest = new NoteComment();
        $guest->setNote($note)->setGuestName('Paul (traduction)')
            ->setQuote('Formulaire de contact en trois langues')
            ->setBody('Les traductions anglaise et allemande sont prêtes, je les envoie demain.');
        $manager->persist($guest);

        $manager->flush();
    }

    /** A Pexels photo, uploaded as if it had been pasted. */
    private function fetchImage(int $photoId, CoreUserInterface $owner): ?string
    {
        $temporaire = (string) tempnam(sys_get_temp_dir(), 'aurora-demo-image-');

        try {
            $octets = $this->http->request('GET', $this->pexels($photoId))->getContent();
            $this->filesystem->dumpFile($temporaire, $octets);

            return $this->imageService->store(
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
     * A small studio's notebook: web development, photography, social media.
     *
     * Rewritten on 03/10/2026 for the tour: the notes were four lines long and
     * mostly showed that the module was empty. Each one now shows a form the
     * rendering can do, and the whole notebook brings them all together:
     * callouts in several colours, aligned tables, checklists per section,
     * highlighted code blocks, images at their size, headings on three levels
     * for the outline, `[[…]]` links for the graph.
     *
     * - "Site de l'agence" is the editor's showcase: the outline has enough
     *   there to unfold, and it is the one carrying the history (`history()`).
     * - "Sommaire de l'agence" goes ahead of the "Studio photo" folder
     *   (`first`): the free order shows in the tree.
     * - No client of any kind (10/10/2026): a client's notes are written in
     *   its client space, which the Notes module does not show, so a client
     *   here would teach the opposite of how the suite works.
     * - The two notes of the "Modèles" folder are templates: "Ajouter" offers
     *   them under "Partir de".
     * - `age` gives each note its modification date.
     *
     * @return array<string, array{title: string, content: string, tags: list<string>, folder?: string, first?: bool, template?: bool, age?: string, cover?: int, coverCredit?: string, coverPosition?: int, appearance?: string, favorite?: bool, trashed?: bool, images?: list<int>, icon?: string, properties?: list<array{key: string, type: string, value: mixed}>}>
     */
    private function notes(): array
    {
        return [
            'sommaire' => [
                // The index does not carry its folder's name: two "Agence"
                // rows one under the other, a folder and a note, is exactly
                // the ambiguity folders removed.
                'title' => "Sommaire de l'agence",
                'tags' => ['index', 'agence'],
                'icon' => '🗂️',
                'properties' => [
                    ['key' => 'Statut', 'type' => 'status', 'value' => 'À jour'],
                    ['key' => 'Suivi par', 'type' => 'person', 'value' => 'dev@aurora.app'],
                ],
                'folder' => 'agence',
                'first' => true,
                'favorite' => true,
                'age' => '-2 hours',
                // A bright workshop and its work tables. The previous photo
                // showed a poster with the Pexels logo right in the middle,
                // and it was the one opening the share page and the reading.
                'cover' => 4348298,
                'coverCredit' => 'Antoni Shkraba',
                'coverPosition' => 60,
                'appearance' => 'paper',
                'content' => <<<'MD'
                    # Sommaire de l'agence

                    Le point d'entrée du carnet : chaque projet de l'agence a sa fiche, et chaque fiche renvoie ici. Les notes sur un client, elles, s'écrivent dans son espace client.

                    > [!abstract] Cette semaine
                    > **Trois projets en cours**, une commande à passer. Prochaine échéance : l'installation du [[Studio photo]], le 14 novembre.

                    ## Projets en cours

                    | Projet | Domaine | Depuis | Ce qui reste |
                    | --- | --- | ---: | --- |
                    | [[Studio photo]] | photographie | 2026 | les éclairages, le fond |
                    | [[Site de l'agence]] | web | 2025 | la galerie, les traductions |
                    | Formation vidéo | réseaux sociaux | 2026 | deux sessions |

                    ## À faire

                    - [x] Commander le fond papier
                    - [ ] Photos de la galerie du site
                    - [ ] Revoir les [[Tarifs 2024]] avant la saison

                    > [!tip] Une nouvelle fiche
                    > « Ajouter », puis « Partir de » le [[Brief de projet]] : la fiche arrive avec ses sections, et la date du jour déjà écrite.
                    MD,
            ],
            'studio' => [
                'title' => 'Studio photo',
                'icon' => '📸',
                'properties' => [
                    ['key' => 'Statut', 'type' => 'status', 'value' => 'En cours'],
                    ['key' => 'Échéance', 'type' => 'date', 'value' => '+36'],
                    ['key' => 'Budget', 'type' => 'number', 'value' => 4200],
                    ['key' => 'Suivi par', 'type' => 'person', 'value' => 'marie.dupont@aurora.app'],
                    ['key' => 'Fournisseur', 'type' => 'url', 'value' => 'https://materiel-photo.example'],
                ],
                'tags' => ['agence', 'photo'],
                'folder' => 'studio',
                'age' => '-3 days',
                'content' => <<<'MD'
                    # Studio photo

                    Un coin de l'atelier transformé en studio : un fond, deux éclairages, de quoi faire les portraits et les photos de produits sans louer un lieu. Suivi par Marie, qui décide vite.

                    > [!quote] Ce qu'on s'est dit au lancement
                    > « On veut des images qui ressemblent à l'atelier un mardi matin, pas à une publicité. »

                    ## Les étapes

                    | Étape | Date | Où | État |
                    | --- | --- | --- | --- |
                    | Fond papier et supports | 18 avril | atelier | fait |
                    | Éclairages continus | 14 novembre | atelier | à venir |

                    ## Le cadre

                    Le chiffrage de l'année est dans le [[Budget du studio 2026]], et l'endroit retenu dans le [[Repérage du local]].

                    Voir aussi [[Séance en extérieur]] : c'est le format qu'on garde quand le temps le permet.
                    MD,
            ],
            'site' => [
                'title' => "Site de l'agence",
                'tags' => ['agence', 'web'],
                'icon' => '🏛️',
                'properties' => [
                    ['key' => 'Statut', 'type' => 'status', 'value' => 'Maintenance'],
                    ['key' => 'Échéance', 'type' => 'date', 'value' => '+5'],
                    ['key' => 'Budget', 'type' => 'number', 'value' => 2160],
                    ['key' => 'Suivi par', 'type' => 'person', 'value' => 'dev@aurora.app'],
                    ['key' => 'Hébergement payé', 'type' => 'checkbox', 'value' => true],
                    ['key' => 'Site', 'type' => 'url', 'value' => 'https://agence.example'],
                ],
                'folder' => 'agence',
                'age' => '-25 minutes',
                'cover' => 923307,
                'coverCredit' => 'Julien Bachelet',
                // The editor's showcase, on purpose: it is the one the card's
                // first image captures, being written with its rendering next
                // to it, and the one the outline and the history show.
                // Headings on three levels, a callout, an aligned table, a
                // code block and an image at its size: everything the
                // rendering can do, in a real sheet.
                'content' => <<<'MD'
                    # Site de l'agence

                    Notre site vitrine, refait au printemps : il montre nos réalisations, en photo, en web et en réseaux sociaux. Relu par Marie avant chaque mise en ligne.

                    ![La dernière réalisation, en tête de la page d'accueil|520]({{image:0}})

                    > [!warning] Avant toute mise en ligne
                    > Marie veut être prévenue, même pour une correction de texte : le site sert de référence quand on répond à une demande de devis.

                    ## Où ça en est

                    ### Livré

                    - [x] Refonte de la page d'accueil
                    - [x] Reprise des fiches réalisation, 24 au total

                    ### En cours

                    - [ ] Galerie avant / après, en attente des photos 📅 {{date:+3}}
                    - [ ] Formulaire de contact en trois langues 📅 {{date:-2}}

                    ## Ce que coûte le site

                    | Poste | Rythme | Montant |
                    | --- | --- | ---: |
                    | Hébergement | mensuel | 18 € |
                    | Sauvegardes | quotidien | inclus |
                    | Nom de domaine | annuel | 15 € |

                    Les évolutions se font sur le temps de l'agence, le vendredi après-midi.

                    ## Technique

                    Hébergé chez nous, déployé depuis un tag :

                    ```bash
                    git fetch --tags
                    git checkout v2.4.1 && make deploy
                    ```

                    > [!info] Accès
                    > Les identifiants sont dans le coffre partagé, jamais dans une note.

                    La fiche remonte au [[Sommaire de l'agence]].
                    MD,
                'images' => [9458996],
            ],
            'contrat' => [
                'title' => 'Contrat type',
                'tags' => ['contrat'],
                'appearance' => 'sepia',
                'age' => '-16 days',
                'content' => <<<'MD'
                    # Contrat type

                    Le socle commun de chaque mission, à adapter à chacune. Ce qui change d'un contrat à l'autre est en italique.

                    ## 1. Commande

                    - Acompte de **30 %** à la signature, le solde à la livraison.
                    - Devis valable *un mois*, au-delà les tarifs sont revus.

                    ## 2. Allers-retours

                    Deux allers-retours inclus sur chaque livrable. Le troisième se facture au temps passé, annoncé avant d'être engagé.

                    ## 3. Droits

                    Cession des droits à la livraison et au paiement du solde, pour *l'usage précisé au cas par cas* : site, réseaux, impression.

                    > [!note] Ce qui ne se négocie pas
                    > Le délai de validation de cinq jours ouvrés, et la mention de l'auteur des photos quand elles sont publiées.

                    Retour au [[Sommaire de l'agence]].
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
                // A template: "Partir de" offers it, and today's date replaces
                // the placeholder on creation.
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
                // A portrait-format photo, cropped high: it is the case that
                // justifies the framing setting, a portrait showing a chin
                // when centred.
                'cover' => 35256272,
                'coverCredit' => 'Alef Morais',
                'coverPosition' => 30,
                'content' => <<<'MD'
                    # Séance en extérieur

                    La méthode qu'on suit pour chaque séance hors de l'atelier : un repérage la veille, la lumière de fin de journée, et une heure de battement pour la météo.

                    Le studio photo en reprendra le déroulé une fois monté, noté ici sans lien exprès pour voir ce que donne une mention non liée.

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

                    - Pourquoi un site lent perd ses visiteurs
                    - Ce que le pied de page d'un site dit de son sérieux

                    > [!question] À trancher
                    > Un article par mois, ou deux plus courts ? On décide au point du lundi.
                    MD,
            ],
            'devis' => [
                'title' => 'Budget du studio 2026',
                'tags' => ['agence', 'budget'],
                'folder' => 'studio',
                'age' => '-9 days',
                'content' => <<<'MD'
                    # Budget du studio 2026

                    Deux temps : le fond et les supports au printemps, les éclairages à l'automne. Au-delà, on loue au cas par cas.

                    | Poste | Quantité | Prix |
                    | --- | ---: | ---: |
                    | Fond papier et supports | 1 | 1 400 € |
                    | Éclairages continus | 2 | 900 € |
                    | Réflecteurs et pinces | forfait | 300 € |
                    | **Total** |  | **2 600 €** |

                    > [!success] Validé le 18 mars
                    > Première commande passée le 21. La seconde attend la fin de la saison.
                    MD,
            ],
            'reperage' => [
                'title' => 'Repérage du local',
                'tags' => ['photo', 'méthode'],
                'folder' => 'studio',
                'age' => '-7 days',
                'content' => <<<'MD'
                    # Repérage du local

                    L'atelier donne au nord : lumière égale toute la journée, aucune ombre dure. Le mur de briques du fond fait un décor à lui seul.

                    ## Les trois endroits retenus

                    1. **Le mur de briques**, pour les portraits individuels.
                    2. **La grande table**, pour les photos de produits.
                    3. **La cour**, si le temps le permet, pour finir dehors.

                    > [!info] Accès
                    > L'atelier ouvre à 8 h 30. Prévenir l'équipe la veille pour que les bureaux soient rangés.

                    Méthode complète dans [[Séance en extérieur]].
                    MD,
            ],
            'bilan' => [
                // The rendering's showcase since 4.6.0: a table of contents,
                // highlights, formulas, a diagram, foldable callouts, dated
                // tasks, a footnote, an included section and a paragraph
                // another note cites. The tour's « rendu » and the slides
                // are taken on it.
                'title' => 'Bilan de la saison',
                'tags' => ['photo', 'bilan'],
                'folder' => 'photo',
                'age' => '-3 hours',
                'icon' => '📊',
                'cover' => 18880006,
                'coverCredit' => 'Amar Preciado',
                'coverPosition' => 45,
                'properties' => [
                    ['key' => 'Statut', 'type' => 'status', 'value' => 'À relire'],
                    ['key' => 'Période', 'type' => 'text', 'value' => 'Juillet à septembre'],
                    ['key' => 'Échéance', 'type' => 'date', 'value' => '+7'],
                ],
                'content' => <<<'MD'
                    # Bilan de la saison

                    [[toc]]

                    ## En bref

                    Une saison :sunny: chargée : ==dix-huit séances==, =={vert}deux nouveaux clients== et {rouge}un report pour la météo{/}. Les projets de l'agence sont dans le [[Sommaire de l'agence]]. #bilan

                    > [!tip] À retenir
                    > Les séances de fin de journée ont donné les meilleures images : on garde le déroulé de la [[Séance en extérieur]].

                    ## Les chiffres

                    | Mois | Séances | Chiffre d'affaires |
                    | --- | ---: | ---: |
                    | Juillet | 6 | 4 200 € |
                    | Août | 4 | 3 100 € |
                    | Septembre | 8 | 5 600 € |

                    La marge de la saison : $marge = \frac{CA - charges}{CA}$, soit 38 %, et sur l'année :

                    $$
                    \sum_{m=1}^{12} CA_m \approx 52\,000\ €
                    $$

                    ## Le parcours d'un client

                    ```mermaid
                    graph LR
                      A[Premier contact] --> B[Devis]
                      B --> C{Signé ?}
                      C -->|Oui| D[Séance]
                      C -->|Non| E[Relance]
                      D --> F[Galerie livrée]
                    ```

                    ## La suite

                    - [x] Envoyer les galeries de septembre :white_check_mark:
                    - [ ] Relancer les devis en attente 📅 {{date:+2}}
                    - [ ] Installer les éclairages du [[Studio photo]] 📅 {{date:+9}}
                    - [ ] Clore les comptes de la saison 📅 {{date:-3}}

                    > [!warning]- Ce qui a coincé (cliquer pour déplier)
                    > - Deux reports pour la pluie, rattrapés la semaine suivante.
                    > - Un objectif en réparation pendant dix jours.

                    > [!toggle] Le matériel de la saison
                    > Deux boîtiers, le 35 mm et le 85 mm, un réflecteur : voir le sac décrit dans [[Matériel]].

                    ## Le déroulé qui marche

                    ![[Séance en extérieur#Le déroulé]]

                    ## Ce qu'en disent les clients

                    > « Les photos sont superbes, on les a déjà toutes imprimées. »[^avis]

                    La saison prochaine garde le même rythme, avec une séance de plus par mois. ^cap

                    [^avis]: Message reçu le lendemain d'une livraison.
                    MD,
            ],
            'livraison' => [
                // Checkboxes, so a card's thumbnail shows something other
                // than a paragraph: it is the form that makes a note
                // recognisable at a glance.
                'title' => 'Checklist de livraison',
                'tags' => ['méthode'],
                'folder' => 'photo',
                'appearance' => 'mint',
                'age' => '-1 day',
                'content' => <<<'MD'
                    # Checklist de livraison

                    - [x] Sélection validée par le client
                    - [x] Retouche des portraits
                    - [ ] Export web et impression 📅 {{date:+1}}
                    - [ ] Galerie en ligne 📅 {{date:+4}}
                    - [ ] Facture du solde 📅 {{date:-1}}

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
                // In the trash: the global screen showed an empty list, so
                // nobody saw what it can do.
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
