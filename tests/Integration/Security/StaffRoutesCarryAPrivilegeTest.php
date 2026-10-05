<?php

declare(strict_types=1);

namespace Aurora\Tests\Integration\Security;

use ReflectionClass;
use ReflectionMethod;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;

use function class_exists;
use function explode;
use function implode;
use function in_array;
use function is_string;
use function method_exists;
use function sprintf;
use function str_contains;
use function str_starts_with;

/**
 * Every staff route names what it takes to open it.
 *
 * `/suite` and `/workspace` only ask to be signed in to the back office
 * (security.yaml); what a person may do there is the `#[IsGranted]` on the
 * controller or its action. A route without one is open to any staff account,
 * a fresh one with no privilege ticked included - and nothing says so. The
 * audit of 04/10/2026 found none; this keeps it that way.
 *
 * A route that is personal (one's profile, one's notifications) still names a
 * role, so the rule holds without exception. The only routes allowed none are
 * the ones a person reaches before being signed in, listed below.
 */
final class StaffRoutesCarryAPrivilegeTest extends KernelTestCase
{
    /** Reached before signing in: login, password, registration, invitation, access request. */
    private const array BEFORE_SIGNING_IN = [
        'suite_platform_login',
        'suite_platform_logout',
        'suite_platform_forgot_password',
        'suite_platform_reset_password',
        'suite_platform_register',
        'suite_platform_register_confirm',
        'suite_platform_resend_verification',
        'suite_platform_verify_email',
        'suite_platform_invitation_accept',
        'suite_platform_access_request',
    ];

    public function testEveryStaffRouteCarriesAnIsGranted(): void
    {
        self::bootKernel();
        $router = self::getContainer()->get(RouterInterface::class);

        $open = [];
        $checked = 0;
        foreach ($router->getRouteCollection()->all() as $name => $route) {
            if (!str_starts_with($route->getPath(), '/suite') && !str_starts_with($route->getPath(), '/workspace')) {
                continue;
            }

            if (in_array($name, self::BEFORE_SIGNING_IN, true)) {
                continue;
            }

            $controller = $route->getDefault('_controller');
            if (!is_string($controller)) {
                continue;
            }

            [$class, $method] = str_contains($controller, '::') ? explode('::', $controller) : [$controller, '__invoke'];
            if (!class_exists($class)) {
                continue;
            }
            if (!method_exists($class, $method)) {
                continue;
            }

            ++$checked;
            if (!$this->isGuarded(new ReflectionClass($class), new ReflectionMethod($class, $method))) {
                $open[] = sprintf('%s (%s)', $name, $route->getPath());
            }
        }

        // Not a vacuous pass: the back office has hundreds of these.
        self::assertGreaterThan(300, $checked);
        self::assertSame([], $open, "Staff routes without #[IsGranted]:\n".implode("\n", $open));
    }

    /** @param ReflectionClass<object> $class */
    private function isGuarded(ReflectionClass $class, ReflectionMethod $method): bool
    {
        if ([] !== $method->getAttributes(IsGranted::class)) {
            return true;
        }

        for ($current = $class; false !== $current; $current = $current->getParentClass()) {
            if ([] !== $current->getAttributes(IsGranted::class)) {
                return true;
            }
        }

        return false;
    }
}
