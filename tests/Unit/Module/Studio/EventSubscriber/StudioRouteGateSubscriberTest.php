<?php

declare(strict_types=1);

namespace Aurora\Tests\Unit\Module\Studio\EventSubscriber;

use Aurora\Core\Module\Service\ModuleAccessChecker;
use Aurora\Module\Configuration\Setting\Enum\ModuleParameterEnum;
use Aurora\Module\Studio\EventSubscriber\StudioRouteGateSubscriber;
use Aurora\Module\Studio\StudioContext;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\HttpKernelInterface;

use function in_array;
use function sprintf;

/**
 * Switching Studio off, or one of its parts, used to hide the menu and
 * leave every screen and every client link answering.
 */
final class StudioRouteGateSubscriberTest extends TestCase
{
    public function testEverythingIsOpenWhileStudioIsOn(): void
    {
        foreach (['backend_studio_spaces', 'backend_studio_contracts', 'workspace_space_content', 'public_space_show', 'public_contract_sign', 'public_deck_show'] as $route) {
            $this->assertGate($route, [], false);
        }
    }

    public function testStudioOffClosesTheScreensAndTheClientPages(): void
    {
        $off = [ModuleParameterEnum::StudioBackend->value];

        foreach (['backend_studio_customers', 'backend_studio_craft_settings', 'workspace_space_content', 'public_space_show', 'public_contract_sign', 'public_deck_show'] as $route) {
            $this->assertGate($route, $off, true);
        }
    }

    public function testOnePartOffClosesThatPartOnly(): void
    {
        $off = [ModuleParameterEnum::StudioContracts->value];

        $this->assertGate('backend_studio_contracts', $off, true);
        $this->assertGate('backend_studio_contract_templates', $off, true);
        $this->assertGate('public_contract_sign', $off, true);

        $this->assertGate('backend_studio_spaces', $off, false);
        $this->assertGate('workspace_space_content', $off, false);
        $this->assertGate('public_space_show', $off, false);
    }

    public function testSpacesOffClosesTheWorkspaceAndTheClientLinks(): void
    {
        $off = [ModuleParameterEnum::StudioSpaces->value];

        $this->assertGate('backend_studio_spaces', $off, true);
        $this->assertGate('workspace_space_chat_post', $off, true);
        $this->assertGate('public_space_answer', $off, true);
        $this->assertGate('backend_studio_decks', $off, false);
    }

    public function testRoutesOfAnotherModuleAreNotItsBusiness(): void
    {
        $this->assertGate('backend_planning_calendar', [ModuleParameterEnum::StudioBackend->value], false);
        $this->assertGate('editorial_post', [ModuleParameterEnum::StudioBackend->value], false);
    }

    /** @param list<string> $disabled */
    private function assertGate(string $route, array $disabled, bool $blocked): void
    {
        $checker = $this->createStub(ModuleAccessChecker::class);
        $checker->method('isEnabled')->willReturnCallback(
            static function (ModuleParameterEnum|string $toggle) use ($disabled): bool {
                $case = $toggle instanceof ModuleParameterEnum ? $toggle : ModuleParameterEnum::from($toggle);

                for ($current = $case; null !== $current; $current = $current->getParentCase()) {
                    if (in_array($current->value, $disabled, true)) {
                        return false;
                    }
                }

                return true;
            },
        );

        $request = new Request();
        $request->attributes->set('_route', $route);
        $thrown = false;

        try {
            new StudioRouteGateSubscriber(new StudioContext($checker))->onKernelRequest(
                new RequestEvent($this->createStub(HttpKernelInterface::class), $request, HttpKernelInterface::MAIN_REQUEST),
            );
        } catch (NotFoundHttpException) {
            $thrown = true;
        }

        self::assertSame($blocked, $thrown, sprintf('route %s', $route));
    }
}
