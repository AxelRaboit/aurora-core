<?php

declare(strict_types=1);

namespace Aurora\Tests\Integration\Module\Planning;

use Aurora\Module\Planning\Planning\Entity\AbstractPlanning;
use Aurora\Module\Platform\User\Entity\User;
use Aurora\Module\Platform\User\Repository\UserRepository;
use Aurora\Tests\Integration\IntegrationTestCase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * The screen reaches every route the module exposes.
 *
 * This test exists because it would have caught a gap nothing else did: the three
 * calendar routes were written, granted and covered by API tests, and the page
 * never mentioned them. A fresh installation showed an empty sidebar with no way
 * to make the first calendar, and the "new event" button is hidden without one -
 * so the screen was a dead end, and every test was green.
 *
 * A route with a passing API test and no caller is not a feature.
 */
final class PlanningScreenWiringTest extends IntegrationTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = static::createClient();

        $admin = static::getContainer()->get(UserRepository::class)
            ->findOneBy(['email' => 'dev@aurora.app', 'type' => 'suite']);
        self::assertInstanceOf(User::class, $admin);
        $this->client->loginUser($admin, 'admin');
    }

    public function testTheCalendarScreenCarriesEveryRouteItNeeds(): void
    {
        $props = $this->screenProps();

        foreach ([
            'eventsPath' => '/suite/planning/events',
            'createEventPath' => '/suite/planning/events/create',
            'updateEventPathTemplate' => '/suite/planning/events/__id__/update',
            'deleteEventPathTemplate' => '/suite/planning/events/__id__/delete',
            'moveEventPathTemplate' => '/suite/planning/events/__id__/move',
            'respondEventPathTemplate' => '/suite/planning/events/__id__/respond',
            'createCalendarPath' => '/suite/planning/calendars/create',
            'updateCalendarPathTemplate' => '/suite/planning/calendars/__id__/update',
            'deleteCalendarPathTemplate' => '/suite/planning/calendars/__id__/delete',
            'createLinkPath' => '/suite/planning/links/create',
            'revokeLinkPathTemplate' => '/suite/planning/links/__id__/revoke',
            'sharesCalendarPathTemplate' => '/suite/planning/calendars/__id__/shares',
            'createReminderPath' => '/suite/planning/reminders/create',
            'updateReminderPathTemplate' => '/suite/planning/reminders/__id__/update',
            'deleteReminderPathTemplate' => '/suite/planning/reminders/__id__/delete',
            'toggleReminderPathTemplate' => '/suite/planning/reminders/__id__/toggle',
        ] as $propertyName => $path) {
            self::assertArrayHasKey($propertyName, $props, sprintf('The screen is never given %s.', $propertyName));
            self::assertSame($path, $props[$propertyName]);
        }
    }

    /**
     * The zone list comes from the runtime, so the picker cannot offer one the
     * DTO would then refuse.
     */
    public function testTheScreenIsGivenTheTimezonesTheValidatorAccepts(): void
    {
        $props = $this->screenProps();

        self::assertArrayHasKey('timezones', $props);
        self::assertIsArray($props['timezones']);
        self::assertContains('Europe/Paris', $props['timezones']);
        self::assertContains('UTC', $props['timezones']);
    }

    /**
     * The props the Twig template hands the Vue component, decoded.
     *
     * Decoded rather than matched as a substring of the page, because the
     * attribute is HTML-escaped JSON: `/suite/...` appears there as
     * `\/suite\/...`, so a plain assertStringContainsString fails on a route
     * that is perfectly well wired - which is what it did first.
     *
     * @return array<string, mixed>
     */
    private function screenProps(): array
    {
        $this->client->request('GET', '/suite/planning/calendar');
        self::assertResponseIsSuccessful();

        // Named, not "the first component on the page": the suite layout mounts
        // the sidemenu and the notification bell too, and `attr()` returns the
        // first match - so an unnamed filter silently asserted against the
        // sidemenu's props, which is how this test first failed on a screen that
        // was correctly wired.
        $crawler = $this->client->getCrawler()->filter(
            '[data-symfony--ux-vue--vue-component-value="planning/suite/planning/PlanningApp"]',
        );
        self::assertSame(1, $crawler->count(), 'The calendar screen does not mount PlanningApp.');

        $decoded = json_decode(
            (string) $crawler->attr('data-symfony--ux-vue--vue-props-value'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        self::assertIsArray($decoded);

        return $decoded;
    }

    /**
     * The palette's ceiling is written down in three places - the entity, the
     * chart tokens and the swatch row - and they have to agree.
     *
     * A ninth swatch would let somebody pick a slot the entity clamps, so the
     * colour they chose is not the colour they get.
     */
    public function testTheSwatchRowOffersExactlyThePaletteTheEntityAccepts(): void
    {
        $js = file_get_contents(
            __DIR__.'/../../../../src/Module/Planning/assets/suite/planning/composables/calendarColours.js',
        );
        self::assertIsString($js);

        self::assertStringContainsString(
            sprintf('MAX_COLOUR_SLOT = %d', AbstractPlanning::MAX_COLOUR_SLOT),
            $js,
        );

        $css = file_get_contents(__DIR__.'/../../../../src/Core/assets/css/base/chart.css');
        self::assertIsString($css);

        for ($slot = 1; $slot <= AbstractPlanning::MAX_COLOUR_SLOT; ++$slot) {
            self::assertStringContainsString(sprintf('--chart-cat-%d:', $slot), $css);
        }

        self::assertStringNotContainsString(
            sprintf('--chart-cat-%d:', AbstractPlanning::MAX_COLOUR_SLOT + 1),
            $css,
        );
    }
}
