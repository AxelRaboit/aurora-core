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

use function assert;

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

            // A note in the trash, so the global screen shows one. Set again
            // on every run: it is scenery, not the result of an action worth
            // keeping.
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

        $this->handedToPeople($manager, $notes['verrier'] ?? null);

        $this->writableLinkFor($notes['verrier'] ?? null);

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
        $fiveDays = (string) preg_replace('/## Technique\n.*?(?=\nLe cadre contractuel)/s', '', $yesterday);
        // Twelve days ago: the site sheets in progress, and not yet Paul's
        // warning.
        $twelveDays = str_replace(
            '- [x] Reprise des fiches chantier, 24 au total',
            '- [ ] Reprise des fiches chantier, 18 sur 24',
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
            recipientEmail: 'camille@studio-lumen.fr',
            label: 'Carnet clients - lecture seule',
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
     * A share link that writes, so the screen shows one.
     *
     * The write badge in the list and the pencil on the guest page both only
     * exist when a link carries the switch; without one, the only way to see
     * either is to create a link by hand, which is exactly the kind of thing
     * nobody does before a capture.
     */
    private function writableLinkFor(?MarkdownNote $note): void
    {
        if (!$note instanceof MarkdownNote) {
            return;
        }

        foreach ($this->shareLinkRepository->findForNote($note) as $existing) {
            if ($existing->canWrite()) {
                return;
            }
        }

        $this->shareLinks->create(
            $note,
            includeLinked: false,
            recipientEmail: 'olivier@atelier-verrier.test',
            label: 'Relecture du devis - écriture',
            expiresAt: new DateTimeImmutable('+14 days'),
            canWrite: true,
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
            'clients' => ['name' => 'Clients', 'color' => '#22c55e', 'favorite' => true],
            'lumen' => ['name' => 'Studio Lumen', 'parent' => 'clients', 'color' => '#3b82f6'],
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
     * - "Cabinet Verrier" is the editor's showcase: the outline has enough
     *   there to unfold, and it is the one carrying the history (`history()`).
     * - "Sommaire des clients" goes ahead of the "Studio Lumen" folder
     *   (`first`): the free order shows in the tree.
     * - The two notes of the "Modèles" folder are templates: "Ajouter" offers
     *   them under "Partir de".
     * - `age` gives each note its modification date.
     *
     * @return array<string, array{title: string, content: string, tags: list<string>, folder?: string, first?: bool, template?: bool, age?: string, cover?: int, coverCredit?: string, coverPosition?: int, appearance?: string, favorite?: bool, trashed?: bool, images?: list<int>}>
     */
    private function notes(): array
    {
        return [
            'clients' => [
                // The index does not carry its folder's name: two "Clients"
                // rows one under the other, a folder and a note, is exactly
                // the ambiguity folders removed.
                'title' => 'Sommaire des clients',
                'tags' => ['index', 'client'],
                'folder' => 'clients',
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
                // The editor's showcase, on purpose: it is the one the card's
                // first image captures, being written with its rendering next
                // to it, and the one the outline and the history show.
                // Headings on three levels, a callout, an aligned table, a
                // code block and an image at its size: everything the
                // rendering can do, in a real sheet.
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
