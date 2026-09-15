<?php

declare(strict_types=1);

namespace Aurora\Tests\Unit\Security;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

use function preg_match;
use function sprintf;

/**
 * Every staff-only path is inside the admin firewall's pattern.
 *
 * The check exists because getting this wrong fails silently in the worst
 * possible direction. Outside `^/(backend|dev|workspace)` no backend session is
 * restored, so a member of the team opening a client space would arrive as an
 * anonymous visitor: not an error, not a 500, just a redirect to the front
 * login of a site they administer.
 *
 * It matters more than usual for `/workspace`, which is deliberately not under
 * `/backend`: a space is opened in its own shell, without the admin chrome, and
 * the address says so. Leaving the prefix is a presentation decision; losing
 * the identity that comes with it would be an accident.
 */
final class AdminFirewallCoversStaffRoutesTest extends TestCase
{
    /**
     * The path prefixes the admin firewall has to cover, with what each is for.
     *
     * Add a prefix here the moment a staff-only screen is served from one. A
     * prefix nobody serves from is harmless; a prefix nobody declared is the
     * bug this test is about.
     *
     * @return list<array{string, string}>
     */
    public static function staffPrefixProvider(): array
    {
        return [
            ['/backend/studio/spaces', 'the back-office itself'],
            ['/dev/dashboard', 'the developer screens'],
            ['/workspace/1', 'a client space, outside /backend on purpose'],
        ];
    }

    #[DataProvider('staffPrefixProvider')]
    public function testTheAdminFirewallCoversIt(string $path, string $what): void
    {
        $pattern = $this->security()['firewalls']['admin']['pattern'] ?? null;
        self::assertIsString($pattern, 'the admin firewall has a pattern');

        self::assertSame(
            1,
            preg_match('#'.$pattern.'#', $path),
            sprintf(
                'the admin firewall pattern "%s" does not cover "%s" (%s), so no backend identity would be restored there',
                $pattern,
                $path,
                $what,
            ),
        );
    }

    /**
     * And a rule stops it before the public catch-all does.
     *
     * The second wall, and the one the controller attribute cannot be. Every
     * staff path ends up matching `^/` eventually; what decides whether it is
     * public is which rule matches first. An action added under one of these
     * prefixes without its own `#[IsGranted]` would otherwise be reachable by
     * anybody, silently, which is the shape of the accident worth a test.
     */
    #[DataProvider('staffPrefixProvider')]
    public function testARuleGuardsItBeforeTheCatchAll(string $path, string $what): void
    {
        foreach ($this->security()['access_control'] ?? [] as $rule) {
            if (1 !== preg_match('#'.$rule['path'].'#', $path)) {
                continue;
            }

            self::assertNotSame(
                'PUBLIC_ACCESS',
                $rule['roles'],
                sprintf('"%s" (%s) is matched by a PUBLIC_ACCESS rule before anything guards it', $path, $what),
            );

            return;
        }

        self::fail(sprintf('no access_control rule matches "%s" (%s)', $path, $what));
    }

    /** @return array<string, mixed> */
    private function security(): array
    {
        return Yaml::parseFile(__DIR__.'/../../../config/packages/security.yaml')['security'];
    }
}
