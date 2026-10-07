<?php

declare(strict_types=1);

namespace Aurora\Tests\Unit\Setting\Command;

use Aurora\Module\Configuration\Setting\Command\ApplicationParameterCommand;
use Aurora\Module\Configuration\Setting\Entity\Setting;
use Aurora\Module\Configuration\Setting\Entity\SettingInterface;
use Aurora\Module\Configuration\Setting\Enum\ApplicationParameterEnumInterface;
use Aurora\Module\Configuration\Setting\Provider\ApplicationParameterProviderInterface;
use Aurora\Module\Configuration\Setting\Provider\CoreApplicationParameterProvider;
use Aurora\Module\Configuration\Setting\Provider\CoreModuleParameterProvider;
use Aurora\Module\Configuration\Setting\Provider\OwnedSettingProviderInterface;
use Aurora\Module\Configuration\Setting\Repository\SettingRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

#[AllowMockObjectsWithoutExpectations]
final class ApplicationParameterCommandTest extends TestCase
{
    /**
     * @param iterable<ApplicationParameterProviderInterface>|null $providers
     * @param iterable<OwnedSettingProviderInterface>              $owners    keys a screen of
     *                                                                        its own writes, which
     *                                                                        the sync must spare
     */
    private function makeTester(
        SettingRepository $repository,
        EntityManagerInterface $entityManager,
        ?iterable $providers = null,
        iterable $owners = [],
    ): CommandTester {
        $providers ??= [new CoreApplicationParameterProvider(), new CoreModuleParameterProvider()];
        $command = new ApplicationParameterCommand($repository, $entityManager, $providers, $owners);

        return new CommandTester($command);
    }

    private function makeSettingStub(string $key, string $description = 'desc', string $type = 'string', ?string $group = null): SettingInterface
    {
        $setting = new Setting($key, 'value', $description, $type, $group);

        return $setting;
    }

    /**
     * The unit half of the same guarantee the integration test makes: a row
     * whose key an owner claims is not debris, even though no parameter enum
     * names it. This is where the Pexels API key was being deleted.
     */
    public function testSparesAKeyAnOwnerClaims(): void
    {
        $owned = $this->makeSettingStub('suite_ged_pexels_api_key');
        $repository = $this->createMock(SettingRepository::class);
        $repository->method('findAll')->willReturn([$owned]);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::never())->method('remove');

        $owner = new class implements OwnedSettingProviderInterface {
            public function getOwnedSettingKeys(): iterable
            {
                yield 'suite_ged_pexels_api_key';
            }
        };

        $tester = $this->makeTester($repository, $entityManager, [], [$owner]);

        self::assertSame(0, $tester->execute([]));
    }

    public function testCreatesAbsentParameters(): void
    {
        $repository = $this->createMock(SettingRepository::class);
        $entityManager = $this->createMock(EntityManagerInterface::class);

        $repository->method('findAll')->willReturn([]);
        $entityManager->expects(self::atLeastOnce())->method('persist');
        $entityManager->expects(self::once())->method('flush');

        $tester = $this->makeTester($repository, $entityManager);
        $tester->execute([]);

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('créé(s)', $tester->getDisplay());
    }

    public function testDryRunDoesNotFlush(): void
    {
        $repository = $this->createMock(SettingRepository::class);
        $entityManager = $this->createMock(EntityManagerInterface::class);

        $repository->method('findAll')->willReturn([]);
        $entityManager->expects(self::never())->method('flush');
        $entityManager->expects(self::never())->method('persist');

        $tester = $this->makeTester($repository, $entityManager);
        $tester->execute(['--dry-run' => true]);

        self::assertSame(0, $tester->getStatusCode());
        $display = $tester->getDisplay();
        self::assertStringContainsString('dry-run', $display);
        self::assertStringContainsString('créé(s)', $display);
    }

    public function testSyncsOutdatedDescription(): void
    {
        $repository = $this->createMock(SettingRepository::class);
        $entityManager = $this->createMock(EntityManagerInterface::class);

        $staleSetting = $this->makeSettingStub('site_name', 'old_description', 'string', 'application');

        $repository->method('findAll')->willReturn([$staleSetting]);
        $entityManager->expects(self::once())->method('flush');

        $tester = $this->makeTester($repository, $entityManager);
        $tester->execute([]);

        $display = $tester->getDisplay();
        self::assertStringContainsString('mis à jour', $display);
    }

    public function testDeletesObsoleteParameters(): void
    {
        $repository = $this->createMock(SettingRepository::class);
        $entityManager = $this->createMock(EntityManagerInterface::class);

        $obsolete = $this->makeSettingStub('totally_unknown_obsolete_key');

        $repository->method('findAll')->willReturn([$obsolete]);
        $entityManager->expects(self::once())->method('remove')->with($obsolete);
        $entityManager->expects(self::once())->method('flush');

        $tester = $this->makeTester($repository, $entityManager);
        $tester->execute([]);

        $display = $tester->getDisplay();
        self::assertStringContainsString('supprimé(s)', $display);
        self::assertStringContainsString('totally_unknown_obsolete_key', $display);
    }

    public function testSummaryContainsAllCounters(): void
    {
        $repository = $this->createMock(SettingRepository::class);
        $entityManager = $this->createMock(EntityManagerInterface::class);

        $repository->method('findAll')->willReturn([]);
        $entityManager->method('flush');

        $tester = $this->makeTester($repository, $entityManager);
        $tester->execute([]);

        $display = $tester->getDisplay();
        self::assertMatchesRegularExpression('/\d+ créé\(s\), \d+ mis à jour, \d+ supprimé\(s\)/', $display);
    }

    public function testDryRunDisplaysPendingCreations(): void
    {
        $repository = $this->createMock(SettingRepository::class);
        $entityManager = $this->createMock(EntityManagerInterface::class);

        $repository->method('findAll')->willReturn([]);

        $tester = $this->makeTester($repository, $entityManager);
        $tester->execute(['--dry-run' => true]);

        $display = $tester->getDisplay();
        self::assertStringContainsString('+', $display);
    }

    public function testCustomProviderContributesItsEnumCases(): void
    {
        $repository = $this->createMock(SettingRepository::class);
        $entityManager = $this->createMock(EntityManagerInterface::class);

        $repository->method('findAll')->willReturn([]);

        $customKey = 'suite_extension_custom_setting';
        $customProvider = $this->makeProviderWith($this->stubParameterEnum($customKey));

        $tester = $this->makeTester($repository, $entityManager, [$customProvider]);
        $tester->execute(['--dry-run' => true]);

        // The custom key from an extension provider appears in the "to-create" list
        self::assertStringContainsString($customKey, $tester->getDisplay());
    }

    public function testSettingKeptWhenItsProviderIsRegistered(): void
    {
        $repository = $this->createMock(SettingRepository::class);
        $entityManager = $this->createMock(EntityManagerInterface::class);

        $customKey = 'suite_extension_custom_setting';
        // Existing setting already in DB (admin saved a value via the UI)
        $existing = $this->makeSettingStub($customKey);
        $repository->method('findAll')->willReturn([$existing]);

        // EM should NOT remove the setting - the provider claims its key
        $entityManager->expects(self::never())->method('remove');

        $customProvider = $this->makeProviderWith($this->stubParameterEnum($customKey));

        $tester = $this->makeTester($repository, $entityManager, [$customProvider]);
        $tester->execute([]);

        // No "obsolète" line for the provider-claimed key
        self::assertStringNotContainsString($customKey.' (obsolète)', $tester->getDisplay());
    }

    /**
     * Stubs `ApplicationParameterEnumInterface` to simulate an enum case
     * exposed by an extension provider, without needing a concrete enum
     * declaration just for the test.
     */
    private function stubParameterEnum(string $key): ApplicationParameterEnumInterface
    {
        $stub = $this->createStub(ApplicationParameterEnumInterface::class);
        $stub->method('getKey')->willReturn($key);
        $stub->method('getLabel')->willReturn('stub.label');
        $stub->method('getDescription')->willReturn('stub description');
        $stub->method('getDefaultValue')->willReturn('');
        $stub->method('getType')->willReturn('string');
        $stub->method('getGroup')->willReturn('stub_group');

        return $stub;
    }

    private function makeProviderWith(ApplicationParameterEnumInterface $case): ApplicationParameterProviderInterface
    {
        return new class($case) implements ApplicationParameterProviderInterface {
            public function __construct(private readonly ApplicationParameterEnumInterface $case) {}

            public function getParameters(): iterable
            {
                yield $this->case;
            }
        };
    }
}
