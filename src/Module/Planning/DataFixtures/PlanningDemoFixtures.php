<?php

declare(strict_types=1);

namespace Aurora\Module\Planning\DataFixtures;

use Aurora\Core\DataFixtures\AppFixtures;
use Aurora\Core\DataFixtures\CoreDemoFixtures;
use Aurora\Module\Planning\Attendee\Entity\PlanningEventAttendee;
use Aurora\Module\Planning\Attendee\Enum\PlanningAttendeeStatusEnum;
use Aurora\Module\Planning\Event\Entity\PlanningEvent;
use Aurora\Module\Planning\Event\Entity\PlanningEventAlert;
use Aurora\Module\Planning\Event\Entity\PlanningEventInterface;
use Aurora\Module\Planning\Event\Enum\PlanningAlertChannelEnum;
use Aurora\Module\Planning\Event\Enum\PlanningEventStatusEnum;
use Aurora\Module\Planning\Link\Entity\PlanningShareLinkModeEnum;
use Aurora\Module\Planning\Link\Manager\PlanningShareLinkManagerInterface;
use Aurora\Module\Planning\Planning\Entity\Planning;
use Aurora\Module\Planning\Planning\Entity\PlanningInterface;
use Aurora\Module\Planning\Planning\Enum\PlanningVisibilityEnum;
use Aurora\Module\Planning\Reminder\Entity\PlanningReminder;
use Aurora\Module\Planning\Share\Entity\PlanningShare;
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
use function sprintf;

/**
 * Demo content for the calendar: six calendars with enough on them to see.
 *
 * Written to be **looked at**, which is a different goal from the tests next
 * door. A test builds the two rows its assertion needs; this has to put every
 * shape the grids can draw on screen at once, because the defects it is meant to
 * surface are the ones only an eye finds - a bar that stops a day short, a chip
 * whose title truncates into nonsense, two events at 14:00 that draw on top of
 * each other, a colour that vanishes in dark mode.
 *
 * So the density is the point, not decoration. Each block below names the thing
 * it exists to expose.
 *
 * **Every date is relative to today.** A fixture with dates written into it shows
 * an empty month the following November, and a demo that is empty is worse than
 * no demo. The cost is that the data is not reproducible run to run, which is
 * fine for a demo and is why the tests build their own rows instead of leaning on
 * this.
 *
 * **Re-running it is safe.** `make demo` loads with `--append` and is documented
 * as idempotent, which this fixture used to break: it created its calendars
 * unconditionally, so a second run produced a second "Pro" holding a second copy
 * of everything. Calendars are now found by name and owner, and content is only
 * seeded into a calendar that this run just created - so a reload adds nothing and
 * destroys nothing, including events somebody added by hand.
 *
 * The trade-off is that a reload does not *refresh* the dates. To move the demo to
 * the current week, delete the calendars and load again - the cascade takes their
 * contents with them:
 *
 * ```
 * php bin/console dbal:run-sql "DELETE FROM core_plannings WHERE owner_id = <id>"
 * make demo
 * ```
 *
 * That is deliberately a manual step. The alternative - wiping a demo calendar's
 * contents on every load - would silently delete work somebody had put on it.
 *
 * Dev/test only, `demo` group.
 */
class PlanningDemoFixtures extends Fixture implements DependentFixtureInterface, FixtureGroupInterface
{
    /**
     * Everybody who can be invited to something.
     *
     * @var list<User>
     */
    private array $people = [];

    /**
     * Whether this run created the calendars, and may therefore fill them.
     *
     * False as soon as one of them already existed, and then nothing is seeded at
     * all - not even into the ones that are new. Partial seeding would be worse
     * than none: the demo's point is that the shapes sit next to each other, and
     * half of them against a calendar somebody has been using is not a demo, it is
     * clutter in their calendar.
     */
    private bool $fresh = true;

    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly PlanningShareLinkManagerInterface $shareLinks,
    ) {}

    public static function getGroups(): array
    {
        return ['demo'];
    }

    /**
     * `CoreDemoFixtures` and not just `AppFixtures`, which is where the
     * colleagues come from.
     *
     * Found by running this: without it the loader put this fixture first, so
     * `marie.dupont` and `jean.martin` did not exist yet, `guests()` came back
     * empty, and the demo quietly had no attendees and no shares - the two things
     * hardest to notice missing, because everything else still drew.
     */
    public function getDependencies(): array
    {
        return [AppFixtures::class, CoreDemoFixtures::class];
    }

    public function load(ObjectManager $manager): void
    {
        assert($manager instanceof EntityManagerInterface);

        $owner = $this->userRepository->findOneBy([
            'email' => 'dev@aurora.app',
            'type' => UserTypeEnum::Backend->value,
        ]);

        if (!$owner instanceof User) {
            throw new RuntimeException('The demo backend account is missing - run the core fixtures first.');
        }

        $this->people = $this->guests($owner);

        // Private and owned, which is what `findVisibleTo` needs to return them:
        // an ownerless private calendar is visible to nobody, a trap this module
        // has fallen into once already.
        $work = $this->calendar($manager, $owner, 'Pro', 2, 'Réunions, recettes, livraisons.');
        $personal = $this->calendar($manager, $owner, 'Perso', 3, 'Ce qui ne regarde pas le travail.');

        // Visible to everybody with the calendar screen, which is the other arm of
        // `findVisibleTo` and looks identical on screen to the one below. Having
        // both means the difference is visible in the modal rather than only in
        // the query.
        $team = $this->calendar($manager, $owner, 'Équipe', 5, 'Ce que tout le monde peut voir.');
        $team->setVisibility(PlanningVisibilityEnum::Shared);

        // Private, but named to two people at different levels - one who may only
        // look, one who may write.
        $onCall = $this->calendar($manager, $owner, 'Astreinte', 7, 'Qui décroche, et quand.');
        $this->shareWithEverybody($manager, $onCall);

        // Another timezone, which is the only way to see the display-zone shift do
        // anything: switch the screen to Paris and these have to move.
        $talks = $this->calendar($manager, $owner, 'Conférences', 4, 'Sur le fuseau de New York.');
        $talks->setTimezone('America/New_York');

        $courses = $this->calendar($manager, $owner, 'Formations', 6, 'Abonnable depuis un téléphone.');

        // Nothing else to do on a database that already has this demo on it. The
        // calendars above were found rather than made, and their settings are
        // refreshed by the setters either way - which is the one part it is safe to
        // reapply, since it overwrites a value rather than adding a row.
        if (!$this->fresh) {
            $manager->flush();

            return;
        }

        $this->shareLinks($work, $personal, $courses);
        $this->recurring($manager, $work, $team);
        $this->overlapping($manager, $work);
        $this->spanning($manager, $personal, $talks);
        $this->invited($manager, $team, $onCall);
        $this->alerted($manager, $work, $courses);
        $this->awkward($manager, $work, $personal, $talks);
        $this->density($manager, $work, $personal, $courses);
        $this->reminders($manager, $work, $personal, $onCall);

        $manager->flush();
    }

    /**
     * The other backend accounts, for invitations and shares.
     *
     * Empty is tolerated rather than fatal: somebody running only this module's
     * fixtures has no colleagues, and a demo calendar is still worth having
     * without attendees.
     *
     * @return list<User>
     */
    private function guests(User $owner): array
    {
        $found = [];

        foreach (['marie.dupont@aurora.app', 'jean.martin@aurora.app'] as $email) {
            $user = $this->userRepository->findOneBy([
                'email' => $email,
                'type' => UserTypeEnum::Backend->value,
            ]);

            if ($user instanceof User && $user->getId() !== $owner->getId()) {
                $found[] = $user;
            }
        }

        return $found;
    }

    /**
     * The demo calendar of that name, made if it is not there yet.
     *
     * Name plus owner is the key. Neither is unique in the schema - somebody may
     * legitimately have two calendars called "Perso" - but for a demo seeding
     * fixed names into one account it identifies the row, and it is what stops a
     * second run producing a second "Pro".
     */
    private function calendar(
        EntityManagerInterface $manager,
        User $owner,
        string $name,
        int $colourSlot,
        string $description,
    ): PlanningInterface {
        $planning = $manager->getRepository(Planning::class)
            ->findOneBy(['name' => $name, 'owner' => $owner]);

        if (!$planning instanceof Planning) {
            $planning = new Planning();
            $manager->persist($planning);
        } else {
            $this->fresh = false;
        }

        $planning->setName($name);
        $planning->setDescription($description);
        $planning->setColourSlot($colourSlot);
        $planning->setOwner($owner);
        $planning->setVisibility(PlanningVisibilityEnum::Private);

        return $planning;
    }

    /**
     * Named on the calendar, once.
     *
     * Guarded separately from `$fresh` because it runs before that is known to be
     * final, and because a share is the one thing here with a real natural key:
     * one row per person per calendar. Adding a second would be a bug the modal
     * would show as a duplicated name.
     */
    private function shareWithEverybody(EntityManagerInterface $manager, PlanningInterface $planning): void
    {
        foreach ($planning->getShares() as $existing) {
            if (null !== $existing->getId()) {
                return;
            }
        }

        foreach ($this->people as $index => $person) {
            $share = new PlanningShare();
            $share->setUser($person);
            // Alternating, so both levels are on screen: the modal draws them
            // differently and a list where every row says the same thing does not
            // show that.
            $share->setCanWrite(0 === $index % 2);
            $planning->addShare($share);

            $manager->persist($share);
        }
    }

    /**
     * Real series, since recurrence exists now.
     *
     * Three shapes because they fail differently: a weekly rule is the ordinary
     * case, a monthly one on the 31st has to skip the short months, and a counted
     * daily rule has to stop on its own. The weekly one also carries an excluded
     * date and a detached occurrence, which are the two ways a series stops being
     * uniform - and the only way to see that the grid draws a moved occurrence
     * once rather than twice.
     */
    private function recurring(
        EntityManagerInterface $manager,
        PlanningInterface $work,
        PlanningInterface $team,
    ): void {
        $monday = $this->startOfWeek();

        $weekly = $this->event(
            $manager,
            $work,
            'Point hebdo',
            $monday->setTime(9, 0),
            $monday->setTime(9, 45),
        );
        $weekly->setLocation('Salle du fond');
        $weekly->setRrule('FREQ=WEEKLY;BYDAY=MO');
        $this->alert($manager, $weekly, 15);

        // Skipped once: the week after next has no Monday meeting, and the grid
        // has to leave that day empty rather than drawing it faintly.
        $weekly->excludeOccurrence($monday->modify('+14 days')->setTime(9, 0));

        // And moved once. A detached occurrence is its own row pointing at the
        // date the rule would have produced, so the expander skips the generated
        // one and draws this instead. Getting this wrong shows the meeting twice,
        // which is exactly the bug this row exists to make visible.
        $moved = $this->event(
            $manager,
            $work,
            'Point hebdo (décalé)',
            $monday->modify('+7 days')->setTime(11, 30),
            $monday->modify('+7 days')->setTime(12, 15),
        );
        $moved->setMaster($weekly);
        $moved->setOccurrenceAt($monday->modify('+7 days')->setTime(9, 0));

        // The 31st, so the short months have to be skipped rather than rolled into
        // the 1st of the next one.
        $monthly = $this->event(
            $manager,
            $work,
            'Clôture mensuelle',
            $monday->modify('first day of this month')->setTime(17, 0),
            $monday->modify('first day of this month')->setTime(18, 30),
        );
        $monthly->setRrule('FREQ=MONTHLY;BYMONTHDAY=31');

        // Ends on its own after five, with no until date. A series that never
        // stops and one that stops by counting look the same in the form.
        $standup = $this->event(
            $manager,
            $team,
            'Daily',
            $monday->setTime(8, 45),
            $monday->setTime(9, 0),
        );
        $standup->setRrule('FREQ=DAILY;COUNT=5');

        // One that stops on a date instead, which is the third ending the form
        // offers and the one that needs `recurrenceUntil` to agree with the rule.
        $review = $this->event(
            $manager,
            $team,
            'Revue de sprint',
            $monday->modify('+2 days')->setTime(15, 0),
            $monday->modify('+2 days')->setTime(16, 0),
        );
        $review->setRrule('FREQ=WEEKLY;BYDAY=WE');
        $review->setRecurrenceUntil($monday->modify('+35 days')->setTime(23, 59));
    }

    /**
     * Three at once, which is what the hour grid's column packing is for.
     *
     * Two overlapping is the easy case and was all the demo had. Three, with one
     * fully inside another, is where a naive layout draws a block on top of a
     * block and the shorter one becomes invisible.
     */
    private function overlapping(EntityManagerInterface $manager, PlanningInterface $work): void
    {
        $tuesday = $this->startOfWeek()->modify('+1 day');

        $this->event($manager, $work, 'Recette client', $tuesday->setTime(14, 0), $tuesday->setTime(15, 30));
        $this->event($manager, $work, 'Appel fournisseur', $tuesday->setTime(14, 30), $tuesday->setTime(15, 0));
        $this->event($manager, $work, 'Point technique', $tuesday->setTime(14, 45), $tuesday->setTime(16, 0));

        // A fourth starting exactly when the first ends. Adjacent is not
        // overlapping, and a layout that treats it as one wastes half the width.
        $this->event($manager, $work, 'Debrief', $tuesday->setTime(15, 30), $tuesday->setTime(16, 0));
    }

    /**
     * Events measured in days rather than hours.
     *
     * The month view draws these as one bar across the cells they cover, and the
     * hourly views put them in the all-day band. Two of them overlap on purpose:
     * the band has to stack them, and a single-lane band hides the second.
     */
    private function spanning(
        EntityManagerInterface $manager,
        PlanningInterface $personal,
        PlanningInterface $talks,
    ): void {
        $monday = $this->startOfWeek();

        $leave = $this->event(
            $manager,
            $personal,
            'Congés',
            $monday->modify('+10 days')->setTime(0, 0),
            $monday->modify('+14 days')->setTime(23, 59, 59),
        );
        $leave->setAllDay(true);

        $conference = $this->event(
            $manager,
            $talks,
            'Conférence à Berlin',
            $monday->modify('+12 days')->setTime(0, 0),
            $monday->modify('+16 days')->setTime(23, 59, 59),
        );
        $conference->setAllDay(true);

        // A single all-day, which is the shortest bar there is and the one most
        // likely to be drawn a day wide in the wrong place.
        $holiday = $this->event(
            $manager,
            $personal,
            'Jour férié',
            $monday->modify('+3 days')->setTime(0, 0),
            $monday->modify('+3 days')->setTime(23, 59, 59),
        );
        $holiday->setAllDay(true);

        // Crossing a week boundary, so the same event has to be drawn as two bars
        // on two rows of the month grid.
        $move = $this->event(
            $manager,
            $personal,
            'Déménagement',
            $monday->modify('+5 days')->setTime(0, 0),
            $monday->modify('+9 days')->setTime(23, 59, 59),
        );
        $move->setAllDay(true);
    }

    /**
     * People invited, in all four answers.
     *
     * The badge is a different colour for each, and "invited and silent" is the
     * one that must not look like a problem - so all four have to be on screen
     * together to judge that.
     */
    private function invited(
        EntityManagerInterface $manager,
        PlanningInterface $team,
        PlanningInterface $onCall,
    ): void {
        if ([] === $this->people) {
            return;
        }

        $monday = $this->startOfWeek();
        $answers = PlanningAttendeeStatusEnum::cases();

        foreach ($answers as $index => $status) {
            $event = $this->event(
                $manager,
                0 === $index % 2 ? $team : $onCall,
                sprintf('Entretien %d', $index + 1),
                $monday->modify(sprintf('+%d days', $index))->setTime(10, 30),
                $monday->modify(sprintf('+%d days', $index))->setTime(11, 15),
            );

            foreach ($this->people as $person) {
                $attendee = new PlanningEventAttendee();
                $attendee->setUser($person);
                if (PlanningAttendeeStatusEnum::NeedsAction !== $status) {
                    $attendee->respond($status, $monday->setTime(8, 0));
                }

                $event->addAttendee($attendee);

                $manager->persist($attendee);
            }
        }

        // One meeting with everybody on it, so the modal's attendee list is long
        // enough to show how it wraps.
        $allHands = $this->event(
            $manager,
            $team,
            'Réunion générale',
            $monday->modify('+4 days')->setTime(17, 0),
            $monday->modify('+4 days')->setTime(18, 0),
        );

        foreach ($this->people as $person) {
            $attendee = new PlanningEventAttendee();
            $attendee->setUser($person);
            $attendee->respond(PlanningAttendeeStatusEnum::Accepted, $monday->setTime(8, 0));
            $allHands->addAttendee($attendee);

            $manager->persist($attendee);
        }
    }

    /**
     * Alerts of every kind the form can produce.
     *
     * An offset and a fixed moment are stored in the same table and read
     * differently, and the channel decides whether the worker notifies or mails.
     * Two on one event, because "tell me and mail me" is ordinary and is why the
     * channel is part of what identifies an alert.
     */
    private function alerted(
        EntityManagerInterface $manager,
        PlanningInterface $work,
        PlanningInterface $courses,
    ): void {
        $monday = $this->startOfWeek();

        $rehearsal = $this->event(
            $manager,
            $work,
            'Répétition générale',
            $monday->modify('+8 days')->setTime(10, 0),
            $monday->modify('+8 days')->setTime(12, 0),
        );
        // Pinned to a moment rather than an offset: "the evening before", which no
        // number of minutes says.
        $pinned = new PlanningEventAlert();
        $rehearsal->addAlert($pinned);
        $pinned->setAbsoluteAt($monday->modify('+7 days')->setTime(18, 0));
        $manager->persist($pinned);

        $training = $this->event(
            $manager,
            $courses,
            'Formation accessibilité',
            $monday->modify('+3 days')->setTime(9, 30),
            $monday->modify('+3 days')->setTime(12, 30),
        );
        $this->alert($manager, $training, 60);
        $this->alert($manager, $training, 1440, PlanningAlertChannelEnum::Email);

        // Already sent, so the modal shows the state an alert reaches and never
        // leaves - and editing the event must not resurrect it.
        $past = $this->event(
            $manager,
            $courses,
            'Formation terminée',
            $monday->modify('-4 days')->setTime(14, 0),
            $monday->modify('-4 days')->setTime(16, 0),
        );
        $sent = new PlanningEventAlert();
        $past->addAlert($sent);
        $sent->setMinutesBefore(30);
        $sent->markSent($monday->modify('-4 days')->setTime(13, 30));

        $manager->persist($sent);
    }

    /**
     * The cases that break a layout rather than fill it.
     *
     * Every one of these is here because it has a plausible way of drawing wrong,
     * and none of them is visible in a calendar of tidy one-hour meetings.
     */
    private function awkward(
        EntityManagerInterface $manager,
        PlanningInterface $work,
        PlanningInterface $personal,
        PlanningInterface $talks,
    ): void {
        $monday = $this->startOfWeek();
        $friday = $monday->modify('+4 days');

        // Crossing midnight: neither an all-day event nor something one column can
        // draw.
        $this->event(
            $manager,
            $personal,
            'Vol de nuit',
            $friday->setTime(22, 30),
            $friday->modify('+1 day')->setTime(1, 15),
        );

        // A quarter of an hour, which is the shortest thing the grid draws and the
        // one whose label has nowhere to go.
        $this->event($manager, $work, 'Café', $monday->modify('+2 days')->setTime(11, 0), $monday->modify('+2 days')->setTime(11, 15));

        // A title with nowhere to fit, to see where truncation lands in a month
        // cell, a week block and the popover.
        $this->event(
            $manager,
            $work,
            'Comité de pilotage trimestriel sur la refonte du parcours d\'inscription',
            $monday->modify('+2 days')->setTime(13, 0),
            $monday->modify('+2 days')->setTime(14, 30),
        );

        // Its own colour, against its calendar's. The two have to be
        // distinguishable or the override is pointless.
        $offPalette = $this->event(
            $manager,
            $work,
            'Astreinte exceptionnelle',
            $monday->modify('+9 days')->setTime(20, 0),
            $monday->modify('+9 days')->setTime(23, 0),
        );
        $offPalette->setColourSlot(8);

        // Cancelled and tentative, which the grid has to distinguish from
        // confirmed without relying on colour alone.
        $cancelled = $this->event($manager, $work, 'Atelier annulé', $monday->modify('+1 day')->setTime(9, 0), $monday->modify('+1 day')->setTime(10, 0));
        $cancelled->setStatus(PlanningEventStatusEnum::Cancelled);

        $maybe = $this->event($manager, $work, 'Déjeuner à confirmer', $monday->modify('+3 days')->setTime(12, 30), $monday->modify('+3 days')->setTime(13, 30));
        $maybe->setStatus(PlanningEventStatusEnum::Tentative);

        // Owned by another module, so the screen must refuse to drag, edit or
        // delete it. Nothing else in the demo is read-only, and a permission that
        // is never exercised is a permission nobody notices is broken.
        $invoiced = $this->event(
            $manager,
            $work,
            'Échéance facture F-2043',
            $monday->modify('+6 days')->setTime(8, 0),
            $monday->modify('+6 days')->setTime(8, 30),
        );
        $invoiced->setSource('billing', 2043, 'Facture F-2043');

        // Early and late in the day, so the grid's scroll has something at both
        // ends rather than everything in office hours.
        $this->event($manager, $personal, 'Course à pied', $monday->modify('+1 day')->setTime(6, 15), $monday->modify('+1 day')->setTime(7, 0));
        $this->event($manager, $personal, 'Film tard', $monday->modify('+5 days')->setTime(23, 0), $monday->modify('+5 days')->setTime(23, 59));

        // On the New York calendar, at a time that lands on a different day in
        // Paris - which is the shift made visible.
        $this->event(
            $manager,
            $talks,
            'Keynote (New York)',
            $monday->modify('+2 days')->setTime(23, 30),
            $monday->modify('+3 days')->setTime(0, 30),
        );
    }

    /**
     * Enough ordinary events to make the month look used.
     *
     * A grid holding twelve careful edge cases and nothing else does not look like
     * anybody's calendar, and the thing it fails to show is crowding: how a cell
     * with five events behaves, where "+2 more" appears, whether the week view
     * stays readable when every column is full.
     *
     * Spread over five weeks either side of today so paging back and forward both
     * land on something, and skipping most weekends because a demo where Sunday
     * looks like Wednesday is not showing the weekend styling either.
     */
    private function density(
        EntityManagerInterface $manager,
        PlanningInterface $work,
        PlanningInterface $personal,
        PlanningInterface $courses,
    ): void {
        $monday = $this->startOfWeek()->modify('-14 days');

        $titles = [
            'Suivi de projet', 'Entretien candidat', 'Point budget', 'Revue de code',
            'Rendez-vous client', 'Livraison', 'Rétrospective', 'Formation interne',
            'Appel partenaire', 'Maintenance serveur', 'Comité éditorial', 'Démo produit',
        ];
        $calendars = [$work, $personal, $courses];

        // A fixed pattern rather than random: a demo that differs every run cannot
        // be talked about, and "the Thursday of the second week" has to mean the
        // same thing to two people looking at two machines.
        for ($day = 0; $day < 35; ++$day) {
            $date = $monday->modify(sprintf('+%d days', $day));
            $weekday = (int) $date->format('N');

            if ($weekday >= 6 && 0 !== $day % 7) {
                continue;
            }

            // One to three a day, varying so some cells crowd and others breathe.
            $count = 1 + ($day % 3);

            for ($slot = 0; $slot < $count; ++$slot) {
                $hour = 9 + (($day + $slot * 3) % 9);
                $start = $date->setTime($hour, 0 === ($day + $slot) % 2 ? 0 : 30);

                $this->event(
                    $manager,
                    $calendars[($day + $slot) % 3],
                    $titles[($day * 2 + $slot) % 12],
                    $start,
                    $start->modify(0 === $slot % 2 ? '+1 hour' : '+30 minutes'),
                );
            }
        }
    }

    /**
     * Reminders in every state the list draws differently.
     *
     * Overdue, due, done and all-day are four styles, and the channel decides
     * whether it arrives as a notification or a mail. All of them together,
     * because each is only wrong relative to the others.
     */
    private function reminders(
        EntityManagerInterface $manager,
        PlanningInterface $work,
        PlanningInterface $personal,
        PlanningInterface $onCall,
    ): void {
        $monday = $this->startOfWeek();

        $late = $this->reminder($manager, $work, 'Relancer le devis', $monday->modify('-2 days')->setTime(11, 0));
        $late->setNotes('Sans réponse depuis la semaine dernière.');

        // Well overdue, so the list has more than one late row and their order is
        // visible.
        $veryLate = $this->reminder($manager, $work, 'Envoyer les justificatifs', $monday->modify('-9 days')->setTime(9, 0));
        $veryLate->setChannel(PlanningAlertChannelEnum::Email);

        $this->reminder($manager, $work, 'Préparer la présentation', $monday->modify('+2 days')->setTime(16, 0));

        // Today, which is the row a reader looks for first and the only one whose
        // styling depends on the clock rather than the date.
        $this->reminder($manager, $onCall, 'Vérifier les sauvegardes', $monday->modify('+0 days')->setTime(18, 0));

        $done = $this->reminder($manager, $personal, 'Prendre le rendez-vous chez le dentiste', $monday->modify('+1 day')->setTime(9, 0));
        $done->complete($monday->setTime(8, 30));

        // Done but late, which is a fifth state the other four do not cover: it
        // must read as finished and not as a problem.
        $doneLate = $this->reminder($manager, $personal, 'Renouveler le passeport', $monday->modify('-5 days')->setTime(10, 0));
        $doneLate->complete($monday->modify('-1 day')->setTime(15, 0));

        $birthday = $this->reminder($manager, $personal, 'Anniversaire de Camille', $monday->modify('+16 days')->setTime(0, 0));
        $birthday->setAllDay(true);

        // A title with nowhere to fit, for the same reason an event has one.
        $this->reminder(
            $manager,
            $work,
            'Reprendre contact avec le prestataire au sujet du renouvellement du certificat',
            $monday->modify('+8 days')->setTime(14, 0),
        );

        // Spread further out, so the agenda view has rows below the fold.
        for ($week = 1; $week <= 4; ++$week) {
            $this->reminder(
                $manager,
                0 === $week % 2 ? $work : $personal,
                sprintf('Point mensuel semaine %d', $week),
                $monday->modify(sprintf('+%d days', $week * 7 + 2))->setTime(15, 30),
            );
        }
    }

    /**
     * One address of each kind, so the screen shows what they look like side by
     * side.
     *
     * Seeded with the content rather than with the calendars, which puts them under
     * the same `$fresh` guard: a link is created, never found, so reloading the demo
     * would otherwise hand out a new address every time and leave the old ones
     * live.
     */
    private function shareLinks(
        PlanningInterface $work,
        PlanningInterface $personal,
        PlanningInterface $courses,
    ): void {
        // A feed, which is what the old `feed_token` column held: no expiry,
        // because a phone polling it for years must not have it close underneath.
        $this->shareLinks->create([$courses], 'Abonnement téléphone', PlanningShareLinkModeEnum::Ics);

        // And a guest link over two calendars at once, which is the thing the
        // column could not express - somebody outside usually wants the work and
        // the availability together rather than two addresses for one schedule.
        $this->shareLinks->create(
            [$work, $personal],
            'Marie, studio photo',
            PlanningShareLinkModeEnum::Web,
            $this->startOfWeek()->modify('+30 days')->setTime(23, 59),
        );

        // One already closed, because the list has to show that state too - and
        // because "what did we revoke, and when" is the question the row exists to
        // answer.
        $revoked = $this->shareLinks->create(
            [$work],
            'Prestataire (terminé)',
            PlanningShareLinkModeEnum::Web,
        );
        $this->shareLinks->revoke($revoked, $this->startOfWeek()->modify('-3 days'));

        // And one that has already expired, which reads differently from revoked
        // even though the guest gets the same answer.
        $this->shareLinks->create(
            [$personal],
            'Relecture (expiré)',
            PlanningShareLinkModeEnum::Web,
            $this->startOfWeek()->modify('-5 days')->setTime(12, 0),
        );
    }

    private function reminder(
        EntityManagerInterface $manager,
        PlanningInterface $planning,
        string $title,
        DateTimeImmutable $dueAt,
    ): PlanningReminder {
        $reminder = new PlanningReminder();
        $reminder->setPlanning($planning);
        $reminder->setTitle($title);
        $reminder->setDueAt($dueAt);

        $manager->persist($reminder);

        return $reminder;
    }

    private function event(
        EntityManagerInterface $manager,
        PlanningInterface $planning,
        string $title,
        DateTimeImmutable $startAt,
        DateTimeImmutable $endAt,
    ): PlanningEventInterface {
        $event = new PlanningEvent();
        $event->setPlanning($planning);
        $event->setTitle($title);
        // Last, and in one call: the entity refuses an end before a start.
        $event->setSpan($startAt, $endAt);

        $manager->persist($event);

        return $event;
    }

    private function alert(
        EntityManagerInterface $manager,
        PlanningEventInterface $event,
        int $minutes,
        PlanningAlertChannelEnum $channel = PlanningAlertChannelEnum::Notification,
    ): void {
        $alert = new PlanningEventAlert();
        $event->addAlert($alert);
        $alert->setMinutesBefore($minutes);
        $alert->setChannel($channel);

        $manager->persist($alert);
    }

    /**
     * The Monday of the current week, which every date here is measured from.
     *
     * Monday because the grids start there, so a fixture anchored to it lands
     * predictably inside the visible month rather than half off the edge.
     */
    private function startOfWeek(): DateTimeImmutable
    {
        return new DateTimeImmutable('monday this week');
    }
}
