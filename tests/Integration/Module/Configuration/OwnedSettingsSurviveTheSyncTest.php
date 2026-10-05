<?php

declare(strict_types=1);

namespace Aurora\Tests\Integration\Module\Configuration;

use Aurora\Module\Configuration\Setting\Entity\Setting;
use Aurora\Module\Ged\Pexels\Setting\PexelsSettingEnum;
use Aurora\Tests\Integration\IntegrationTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * A settings row owned by a screen of its own has to survive a deploy.
 *
 * `aurora:application-parameter` runs on every release and deletes rows no
 * parameter provider claims, which is how a retired setting stops lingering.
 * The Pexels tab writes four rows of its own and deliberately keeps them off
 * the generic settings screen - so no parameter enum names them, and the sync
 * was deleting all four at each deploy. It took the API key with it: the
 * integration was configured, it worked, and the next release silently emptied
 * it, with nothing said anywhere.
 *
 * This is the test that would have caught it, and the reason it is an
 * integration test: what broke was the wiring between a tagged iterator and a
 * delete loop, which no unit test of either would have seen.
 */
final class OwnedSettingsSurviveTheSyncTest extends IntegrationTestCase
{
    private EntityManagerInterface $entityManager;

    private CommandTester $command;

    protected function setUp(): void
    {
        parent::setUp();
        static::bootKernel();

        $this->entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $this->command = new CommandTester(
            (new Application(static::$kernel))->find('aurora:application-parameter'),
        );
    }

    public function testTheSyncLeavesTheRowsOfAnOwnedScreenAlone(): void
    {
        foreach (PexelsSettingEnum::cases() as $case) {
            $this->store($case->value, 'gardé');
        }

        self::assertSame(0, $this->command->execute([]));
        $this->entityManager->clear();

        foreach (PexelsSettingEnum::cases() as $case) {
            self::assertNotNull(
                $this->find($case->value),
                sprintf('%s was deleted by the deploy-time sync', $case->value),
            );
        }
    }

    /** And the debris still goes: a key nobody claims is still obsolete. */
    public function testTheSyncStillClearsARowNobodyClaims(): void
    {
        $this->store('suite_retired_setting_nobody_owns', 'à jeter');

        self::assertSame(0, $this->command->execute([]));
        $this->entityManager->clear();

        self::assertNull($this->find('suite_retired_setting_nobody_owns'));
    }

    private function store(string $key, string $value): void
    {
        $setting = new Setting();
        $setting->setKey($key);
        $setting->setValue($value);

        $this->entityManager->persist($setting);
        $this->entityManager->flush();
    }

    private function find(string $key): ?Setting
    {
        return $this->entityManager->getRepository(Setting::class)->findOneBy(['key' => $key]);
    }
}
