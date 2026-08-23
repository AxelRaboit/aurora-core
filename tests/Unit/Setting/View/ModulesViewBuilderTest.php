<?php

declare(strict_types=1);

namespace Aurora\Tests\Unit\Setting\View;

use Aurora\Core\Module\Contract\ModuleInterface;
use Aurora\Core\Module\Contract\ModuleToggleProviderInterface;
use Aurora\Core\Module\Toggle\ModuleToggle;
use Aurora\Core\Module\Toggle\ModuleToggleRegistry;
use Aurora\Module\Configuration\Setting\Enum\ModuleParameterEnum;
use Aurora\Module\Configuration\Setting\Repository\SettingRepository;
use Aurora\Module\Configuration\Setting\View\ModulesViewBuilder;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

#[AllowMockObjectsWithoutExpectations]
final class ModulesViewBuilderTest extends TestCase
{
    private function makeBuilder(
        SettingRepository $repository,
        TranslatorInterface $translator,
        iterable $modules = [],
    ): ModulesViewBuilder {
        // Build a registry from every core ModuleParameterEnum case, matching
        // what the GeneralModule + PlatformModule + ConfigurationModule +
        // per-business-module ::getToggles() declarations do at runtime - so
        // the view builder sees the same toggle universe.
        $toggles = array_map(
            static fn (ModuleParameterEnum $case): ModuleToggle => $case->toToggle(),
            ModuleParameterEnum::cases(),
        );

        $toggleProvider = new class($toggles) implements ModuleInterface, ModuleToggleProviderInterface {
            /** @param list<ModuleToggle> $toggles */
            public function __construct(private readonly array $toggles) {}

            public function getId(): string
            {
                return 'core';
            }

            public function getNavSections(): array
            {
                return [];
            }

            public function getCatalogNavSections(): array
            {
                return [];
            }

            public function getPermissions(): array
            {
                return [];
            }

            public function getToggles(): array
            {
                return $this->toggles;
            }
        };

        $registry = new ModuleToggleRegistry([$toggleProvider]);

        return new ModulesViewBuilder($repository, $modules, $translator, $registry);
    }

    private function stubRepository(string $defaultValue = '1'): SettingRepository
    {
        $repository = $this->createStub(SettingRepository::class);
        $repository->method('get')->willReturn($defaultValue);

        return $repository;
    }

    private function stubTranslator(): TranslatorInterface
    {
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        return $translator;
    }

    public function testModulesPayloadReturnsParametersKey(): void
    {
        $builder = $this->makeBuilder($this->stubRepository(), $this->stubTranslator());
        $payload = $builder->modulesPayload();

        self::assertArrayHasKey('parameters', $payload);
        self::assertIsArray($payload['parameters']);
    }

    public function testModulesPayloadContainsOnlyTopLevelParameters(): void
    {
        $builder = $this->makeBuilder($this->stubRepository(), $this->stubTranslator());
        $payload = $builder->modulesPayload();

        $topLevelCount = 0;
        foreach (ModuleParameterEnum::cases() as $case) {
            if (null === $case->getParentCase()) {
                ++$topLevelCount;
            }
        }

        self::assertCount($topLevelCount, $payload['parameters']);
    }

    public function testEachTopLevelParameterHasRequiredKeys(): void
    {
        $builder = $this->makeBuilder($this->stubRepository(), $this->stubTranslator());
        $payload = $builder->modulesPayload();

        foreach ($payload['parameters'] as $parameter) {
            self::assertArrayHasKey('key', $parameter);
            self::assertArrayHasKey('label', $parameter);
            self::assertArrayHasKey('description', $parameter);
            self::assertArrayHasKey('value', $parameter);
            self::assertArrayHasKey('requires', $parameter);
            self::assertArrayHasKey('navItems', $parameter);
            self::assertArrayHasKey('subModules', $parameter);
        }
    }

    public function testSubModulesAreAttachedToCorrectParent(): void
    {
        $builder = $this->makeBuilder($this->stubRepository(), $this->stubTranslator());
        $payload = $builder->modulesPayload();

        $gedParam = null;
        foreach ($payload['parameters'] as $parameter) {
            if ($parameter['key'] === ModuleParameterEnum::GedBackend->value) {
                $gedParam = $parameter;
                break;
            }
        }

        self::assertNotNull($gedParam);
        self::assertIsArray($gedParam['subModules']);

        $subKeys = array_column($gedParam['subModules'], 'key');
        self::assertContains(ModuleParameterEnum::GedDocuments->value, $subKeys);
        self::assertContains(ModuleParameterEnum::GedCategories->value, $subKeys);
        self::assertContains(ModuleParameterEnum::GedTags->value, $subKeys);
        self::assertContains(ModuleParameterEnum::GedFolders->value, $subKeys);
        self::assertContains(ModuleParameterEnum::GedFrontend->value, $subKeys);
    }

    public function testEachSubModuleHasRequiredKeys(): void
    {
        $builder = $this->makeBuilder($this->stubRepository(), $this->stubTranslator());
        $payload = $builder->modulesPayload();

        foreach ($payload['parameters'] as $parameter) {
            foreach ($parameter['subModules'] as $subModule) {
                self::assertArrayHasKey('key', $subModule);
                self::assertArrayHasKey('label', $subModule);
                self::assertArrayHasKey('description', $subModule);
                self::assertArrayHasKey('value', $subModule);
                self::assertArrayHasKey('requires', $subModule);
            }
        }
    }

    public function testSubModuleRequiresReflectsCascadeRequires(): void
    {
        $builder = $this->makeBuilder($this->stubRepository(), $this->stubTranslator());
        $payload = $builder->modulesPayload();

        $gedParam = null;
        foreach ($payload['parameters'] as $parameter) {
            if ($parameter['key'] === ModuleParameterEnum::GedBackend->value) {
                $gedParam = $parameter;
                break;
            }
        }

        self::assertNotNull($gedParam);

        $documentsSub = null;
        foreach ($gedParam['subModules'] as $sub) {
            if ($sub['key'] === ModuleParameterEnum::GedDocuments->value) {
                $documentsSub = $sub;
                break;
            }
        }

        self::assertNotNull($documentsSub);
        self::assertSame(ModuleParameterEnum::GedBackend->value, $documentsSub['requires']);
    }

    public function testIndexViewReturnsTabbedStructure(): void
    {
        $builder = $this->makeBuilder($this->stubRepository(), $this->stubTranslator());
        $payload = ['parameters' => []];
        $view = $builder->indexView($payload);

        self::assertSame('modules', $view['tab']);
        self::assertSame($payload, $view['modules']);
    }

    public function testNavItemsEmptyWhenNoModulesRegistered(): void
    {
        $builder = $this->makeBuilder($this->stubRepository(), $this->stubTranslator(), []);
        $payload = $builder->modulesPayload();

        foreach ($payload['parameters'] as $parameter) {
            self::assertSame([], $parameter['navItems']);
        }
    }
}
