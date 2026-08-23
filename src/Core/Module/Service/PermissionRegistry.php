<?php

declare(strict_types=1);

namespace Aurora\Core\Module\Service;

use Aurora\Core\Module\Contract\ModuleInterface;

final class PermissionRegistry
{
    /** @var list<string> all registered privilege names */
    private array $permissions = [];

    /** @var array<string, list<string>> module id -> privilege names */
    private array $byModule = [];

    /** @param iterable<ModuleInterface> $modules */
    public function __construct(iterable $modules)
    {
        foreach ($modules as $module) {
            $moduleId = $module->getId();
            $this->byModule[$moduleId] ??= [];

            foreach ($module->getPermissions() as $permission) {
                $this->permissions[] = $permission->name;
                // Permission can opt into a different display group via
                // NavPermission::$group - used to surface a permission
                // declared by one module under another section in the
                // privileges modal. Rarely needed since Jalon 5 aligned
                // every permission name on its owner module id.
                $group = $permission->group ?? $moduleId;
                $this->byModule[$group] ??= [];
                $this->byModule[$group][] = $permission->name;
            }
        }

        $this->permissions = array_unique($this->permissions);
    }

    public function has(string $permissionName): bool
    {
        return in_array($permissionName, $this->permissions, true);
    }

    /** @return list<string> */
    public function all(): array
    {
        return $this->permissions;
    }

    /** @return array<string, list<string>> */
    public function byModule(): array
    {
        return $this->byModule;
    }
}
