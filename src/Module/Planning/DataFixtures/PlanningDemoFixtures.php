<?php

declare(strict_types=1);

namespace Aurora\Module\Planning\DataFixtures;

use Aurora\Core\DataFixtures\AppFixtures;
use Aurora\Module\Planning\Event\Entity\PlanningEvent;
use Aurora\Module\Planning\Event\Entity\PlanningEventAlert;
use Aurora\Module\Planning\Event\Entity\PlanningEventInterface;
use Aurora\Module\Planning\Planning\Entity\Planning;
use Aurora\Module\Planning\Planning\Entity\PlanningInterface;
use Aurora\Module\Planning\Planning\Enum\PlanningVisibilityEnum;
use Aurora\Module\Planning\Reminder\Entity\PlanningReminder;
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
 * Demo content for the calendar: two calendars with something on them.
 *
 * Until this existed, a fresh installation opened the calendar on nothing at all
 * - not an empty month, but no calendars either, which is the one state where the
 * screen has no way to explain itself.
 *
 * **Every date is relative to today.** A fixture with dates written into it shows
 * an empty month the following November, and a demo that is empty is worse than
 * no demo. The cost is that the data is not reproducible run to run, which is
 * fine for a demo and is why the tests build their own rows instead of leaning on
 * this.
 *
 * Dev/test only, `demo` group.
 */
class PlanningDemoFixtures extends Fixture implements DependentFixtureInterface, FixtureGroupInterface
{
    public function __construct(private readonly UserRepository $userRepository) {}

    public static function getGroups(): array
    {
        return ['demo'];
    }

    public function getDependencies(): array
    {
        return [AppFixtures::class];
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

        // Private and owned, which is what `findVisibleTo` needs to return them:
        // an ownerless private calendar is visible to nobody, a trap this module
        // has fallen into once already.
        $work = $this->calendar($manager, $owner, 'Pro', 2, 'Réunions, recettes, livraisons.');
        $personal = $this->calendar($manager, $owner, 'Perso', 3, 'Ce qui ne regarde pas le travail.');

        $this->events($manager, $work, $personal);
        $this->reminders($manager, $work, $personal);

        $manager->flush();
    }

    private function calendar(
        EntityManagerInterface $manager,
        User $owner,
        string $name,
        int $colourSlot,
        string $description,
    ): PlanningInterface {
        $planning = new Planning();
        $planning->setName($name);
        $planning->setDescription($description);
        $planning->setColourSlot($colourSlot);
        $planning->setOwner($owner);
        $planning->setVisibility(PlanningVisibilityEnum::Private);

        $manager->persist($planning);

        return $planning;
    }

    /**
     * One of each shape the grids can draw, so the demo exercises the layout
     * rather than just filling it.
     */
    private function events(
        EntityManagerInterface $manager,
        PlanningInterface $work,
        PlanningInterface $personal,
    ): void {
        $monday = $this->startOfWeek();

        // A recurring-looking weekly meeting, three weeks of it. Not recurrence -
        // three separate events, because recurrence does not exist yet and a
        // fixture pretending otherwise would misrepresent the product.
        for ($week = 0; $week < 3; ++$week) {
            $start = $monday->modify(sprintf('+%d days', $week * 7))->setTime(9, 0);
            $event = $this->event($manager, $work, 'Point hebdo', $start, $start->modify('+45 minutes'));
            $event->setLocation('Salle du fond');
            $this->alert($manager, $event, 15);
        }

        // Two that overlap, which is the case the hour grid's column packing
        // exists for.
        $tuesday = $monday->modify('+1 day');
        $this->event($manager, $work, 'Recette client', $tuesday->setTime(14, 0), $tuesday->setTime(15, 30));
        $this->event($manager, $work, 'Appel fournisseur', $tuesday->setTime(14, 30), $tuesday->setTime(15, 0));

        // A run of days, which the month view draws as a bar and the hourly views
        // put in the all-day band.
        $leave = $this->event(
            $manager,
            $personal,
            'Congés',
            $monday->modify('+10 days')->setTime(0, 0),
            $monday->modify('+14 days')->setTime(23, 59, 59),
        );
        $leave->setAllDay(true);

        // One crossing midnight, which is neither an all-day event nor something a
        // single column can draw.
        $friday = $monday->modify('+4 days');
        $this->event($manager, $personal, 'Vol de nuit', $friday->setTime(22, 30), $friday->modify('+1 day')->setTime(1, 15));

        // One with an alert pinned to a moment rather than an offset.
        $rehearsal = $this->event(
            $manager,
            $work,
            'Répétition générale',
            $monday->modify('+8 days')->setTime(10, 0),
            $monday->modify('+8 days')->setTime(12, 0),
        );
        $pinned = new PlanningEventAlert();
        $rehearsal->addAlert($pinned);
        $pinned->setAbsoluteAt($monday->modify('+7 days')->setTime(18, 0));
        $manager->persist($pinned);
    }

    private function reminders(
        EntityManagerInterface $manager,
        PlanningInterface $work,
        PlanningInterface $personal,
    ): void {
        $monday = $this->startOfWeek();

        // One overdue, one due later, one already done - the three states the grid
        // draws differently, so the demo shows all three.
        $late = new PlanningReminder();
        $late->setPlanning($work);
        $late->setTitle('Relancer le devis');
        $late->setNotes('Sans réponse depuis la semaine dernière.');
        $late->setDueAt($monday->modify('-2 days')->setTime(11, 0));

        $manager->persist($late);

        $soon = new PlanningReminder();
        $soon->setPlanning($work);
        $soon->setTitle('Préparer la présentation');
        $soon->setDueAt($monday->modify('+2 days')->setTime(16, 0));

        $manager->persist($soon);

        $done = new PlanningReminder();
        $done->setPlanning($personal);
        $done->setTitle('Prendre le rendez-vous chez le dentiste');
        $done->setDueAt($monday->modify('+1 day')->setTime(9, 0));
        $done->complete($monday->setTime(8, 30));

        $manager->persist($done);

        $wholeDay = new PlanningReminder();
        $wholeDay->setPlanning($personal);
        $wholeDay->setTitle('Anniversaire de Camille');
        $wholeDay->setDueAt($monday->modify('+16 days')->setTime(0, 0));
        $wholeDay->setAllDay(true);

        $manager->persist($wholeDay);
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

    private function alert(EntityManagerInterface $manager, PlanningEventInterface $event, int $minutes): void
    {
        $alert = new PlanningEventAlert();
        $event->addAlert($alert);
        $alert->setMinutesBefore($minutes);
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
