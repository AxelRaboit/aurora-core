<?php

declare(strict_types=1);

namespace Aurora\Module\Configuration\Setting\Service;

use Aurora\Core\Module\Toggle\ModuleToggle;
use Aurora\Core\Module\Toggle\ModuleToggleRegistry;
use Aurora\Module\Configuration\Setting\Exception\CascadeViolationException;
use Aurora\Module\Configuration\Setting\Repository\SettingRepository;
use Aurora\Module\Dev\Audit\Service\AuditLogger;

/**
 * Orchestrates writes to application parameters. Enforces the module
 * dependency graph (aggregated by {@see ModuleToggleRegistry} from every
 * module's own toggles - no central enum) on top of the pure-persistence
 * concerns owned by SettingRepository.
 *
 *  - Refuses enabling a parameter whose parent module is off
 *  - Cascades disable to all transitive children when a parent goes off
 *
 * The repository is intentionally kept rule-free so direct persistence
 * (migrations, seeders, the sync command) is not double-validated.
 */
final readonly class SettingsService
{
    public function __construct(
        private SettingRepository $repository,
        private AuditLogger $auditLogger,
        private ModuleToggleRegistry $moduleToggleRegistry,
    ) {}

    /**
     * @throws CascadeViolationException when enabling a child whose parent is off
     */
    public function set(string $key, ?string $value): void
    {
        $toggle = $this->moduleToggleRegistry->get($key);

        if ($toggle instanceof ModuleToggle && '1' === $value) {
            $this->assertParentEnabled($toggle->parentKey, $key);
        }

        $writes = [[$key, $value]];

        if ($toggle instanceof ModuleToggle && '0' === $value) {
            foreach ($this->moduleToggleRegistry->getDescendantKeys($key) as $childKey) {
                $writes[] = [$childKey, '0'];
            }
        }

        $this->repository->saveMany($writes);

        $this->auditLogger->log('core', 'settings.updated', null, null, ['key' => $key, 'value' => $value]);
    }

    private function assertParentEnabled(?string $parentKey, string $childKey): void
    {
        if (null === $parentKey) {
            return;
        }

        $value = $this->repository->get($parentKey, '1');

        if ('1' !== $value) {
            throw new CascadeViolationException($childKey, $parentKey);
        }
    }
}
