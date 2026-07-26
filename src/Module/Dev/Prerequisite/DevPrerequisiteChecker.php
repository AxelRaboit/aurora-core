<?php

declare(strict_types=1);

namespace Aurora\Module\Dev\Prerequisite;

use function extension_loaded;

/**
 * Checks every developer prerequisite listed in
 * `docs/aurora-core/ops/prerequisites.md` and returns a list of
 * {@see PrerequisiteWarning} objects for those that are not satisfied.
 *
 * Designed to be cheap enough to call on every admin page load in the
 * `dev` environment:
 *
 *  - PHP extension checks  → `extension_loaded()` — essentially free.
 *  - Binary checks         → single `exec()` per binary — milliseconds.
 *
 * Results are also cached in-instance so multiple Twig calls per request
 * (e.g. layout + embed) hit the file cache once at most.
 */
final class DevPrerequisiteChecker
{
    /** @var list<PrerequisiteWarning>|null */
    private ?array $warnings = null;

    /**
     * @return list<PrerequisiteWarning>
     */
    public function getWarnings(): array
    {
        if (null !== $this->warnings) {
            return $this->warnings;
        }

        return $this->warnings = [
            ...$this->checkPhpExtensions(),
            ...$this->checkNodeJs(),
        ];
    }

    public function hasWarnings(): bool
    {
        return [] !== $this->getWarnings();
    }

    // ── PHP extensions ────────────────────────────────────────────────

    /** @return list<PrerequisiteWarning> */
    private function checkPhpExtensions(): array
    {
        $required = ['pdo_pgsql', 'intl', 'mbstring', 'gd', 'zip', 'curl'];
        $warnings = [];

        foreach ($required as $ext) {
            if (!extension_loaded($ext)) {
                $warnings[] = new PrerequisiteWarning(
                    message: 'Extension PHP manquante : '.$ext,
                    fix: 'sudo apt install php8.4-'.$ext,
                    level: 'warning',
                );
            }
        }

        return $warnings;
    }

    // ── Node.js ───────────────────────────────────────────────────────

    /** @return list<PrerequisiteWarning> */
    private function checkNodeJs(): array
    {
        if (!$this->execAvailable()) {
            return [];
        }

        $output = [];
        exec('node --version 2>/dev/null', $output, $code);

        if (0 !== $code || [] === $output) {
            return [new PrerequisiteWarning(
                message: 'Node.js introuvable',
                fix: 'Installer depuis nodejs.org ou via nvm (≥ 18 requis)',
                level: 'warning',
            )];
        }

        // Warn if version < 18
        $version = mb_ltrim($output[0], 'v');
        if (version_compare($version, '18.0.0', '<')) {
            return [new PrerequisiteWarning(
                message: sprintf('Node.js %s < 18 requis', $version),
                fix: 'Mettre à jour Node.js via nvm : nvm install 18 && nvm use 18',
                level: 'warning',
            )];
        }

        return [];
    }

    private function execAvailable(): bool
    {
        if (!function_exists('exec')) {
            return false;
        }

        $disabled = array_map(trim(...), explode(',', ini_get('disable_functions') ?: ''));

        return !in_array('exec', $disabled, true);
    }
}
