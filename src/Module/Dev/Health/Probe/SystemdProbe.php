<?php

declare(strict_types=1);

namespace Aurora\Module\Dev\Health\Probe;

use Aurora\Module\Configuration\Setting\Enum\ApplicationParameterEnum;
use Aurora\Module\Configuration\Setting\Repository\SettingRepository;
use Aurora\Module\Dev\Health\Enum\HealthStatusEnum;
use Aurora\Module\Dev\Health\Report\HealthCheck;
use Aurora\Module\Dev\Health\Worker\WorkerHeartbeat;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * The server's own services, when it says which ones matter.
 *
 * **The list is a setting, empty by default.** aurora-core runs on servers it
 * knows nothing about; the names of the units worth watching belong to
 * whoever runs one, in Settings > Système. Each unit is read with
 * `systemctl show`, which any account may run: its state, its last result
 * and how many times systemd restarted it. The count is shown, never judged:
 * a service told to restart (`Restart=always`) comes back the same way after
 * a crash and after an exit it chose, like the worker leaving every hour on
 * its `--time-limit`. The worker's own crashes are told apart by
 * {@see WorkerHeartbeat}.
 *
 * Nothing at all on a machine without systemd (a container, another system).
 * Two files say more when the server is a Debian or an Ubuntu and they are
 * readable: a reboot is waiting, and how many updates are pending.
 */
final readonly class SystemdProbe
{
    /** What a unit name may contain: nothing that reaches a shell, nothing that is an option. */
    private const string UNIT_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9@._:\-]*$/';

    public function __construct(
        private SettingRepository $settingRepository,
        private ExecutableFinder $executableFinder = new ExecutableFinder(),
        private string $rebootRequiredFile = '/var/run/reboot-required',
        private string $updatesAvailableFile = '/var/lib/update-notifier/updates-available',
    ) {}

    /** @return list<string> the unit names the setting lists, the invalid ones left out */
    public function units(): array
    {
        $raw = $this->settingRepository->getOrDefault(ApplicationParameterEnum::SystemHealthUnits);
        $units = preg_split('/[\s,;]+/', $raw, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return array_values(array_unique(array_filter($units, static fn (string $unit): bool => 1 === preg_match(self::UNIT_PATTERN, $unit))));
    }

    /** @return list<HealthCheck> empty when nothing is to be watched or systemd is absent */
    public function checks(): array
    {
        $units = $this->units();
        $systemctl = [] === $units ? null : $this->executableFinder->find('systemctl');

        if (null === $systemctl) {
            return [];
        }

        $checks = [];
        foreach ($units as $unit) {
            $checks[] = $this->unit($systemctl, $unit);
        }

        return [...$checks, ...$this->server()];
    }

    private function unit(string $systemctl, string $unit): HealthCheck
    {
        try {
            $process = new Process([$systemctl, 'show', $unit, '--property=LoadState,ActiveState,SubState,Result,NRestarts', '--no-pager']);
            $process->setTimeout(4);
            $process->run();
            $properties = $this->parse($process->getOutput());
        } catch (Throwable) {
            $properties = [];
        }

        $load = $properties['LoadState'] ?? '';
        $active = $properties['ActiveState'] ?? '';
        $result = $properties['Result'] ?? '';
        $restarts = (int) ($properties['NRestarts'] ?? 0);

        $status = match (true) {
            '' === $active || 'not-found' === $load => HealthStatusEnum::Unknown,
            'failed' === $active || ('success' !== $result && '' !== $result) => HealthStatusEnum::Danger,
            'active' === $active => HealthStatusEnum::Ok,
            // A one-shot service that ran and stopped cleanly (a backup
            // triggered by its timer) is inactive by design.
            'inactive' === $active && 'success' === $result => HealthStatusEnum::Ok,
            default => HealthStatusEnum::Warning,
        };

        return new HealthCheck('unit:'.$unit, $status, 'suite.health.unit.label', 'not-found' === $load ? 'suite.health.unit.not_found' : 'suite.health.unit.state', [
            'unit' => $unit,
            'active' => $active,
            'sub' => $properties['SubState'] ?? '',
            'restarts' => $restarts,
        ], [
            'unit' => $unit,
            'activeState' => $active,
            'subState' => $properties['SubState'] ?? null,
            'result' => '' === $result ? null : $result,
            'restarts' => $restarts,
        ]);
    }

    /** @return list<HealthCheck> */
    private function server(): array
    {
        $checks = [];

        if (is_file($this->rebootRequiredFile)) {
            $checks[] = new HealthCheck('reboot', HealthStatusEnum::Warning, 'suite.health.reboot.label', 'suite.health.reboot.required');
        }

        if (is_readable($this->updatesAvailableFile) && 1 === preg_match('/(\d+)/', (string) file_get_contents($this->updatesAvailableFile), $matches)) {
            $count = (int) $matches[1];
            $checks[] = new HealthCheck('updates', $count > 0 ? HealthStatusEnum::Warning : HealthStatusEnum::Ok, 'suite.health.updates.label', 'suite.health.updates.pending', ['count' => $count], ['pending' => $count]);
        }

        return $checks;
    }

    /** @return array<string, string> */
    private function parse(string $output): array
    {
        $properties = [];
        foreach (preg_split('/\R/', mb_trim($output)) ?: [] as $line) {
            $position = mb_strpos($line, '=');
            if (false !== $position) {
                $properties[mb_substr($line, 0, $position)] = mb_substr($line, $position + 1);
            }
        }

        return $properties;
    }
}
