<?php

declare(strict_types=1);

namespace Aurora\Tests\Unit\Module\Notes\EventSubscriber;

use Aurora\Core\Module\Service\ModuleAccessChecker;
use Aurora\Module\Configuration\Setting\Enum\ModuleParameterEnum;
use Aurora\Module\Notes\EventSubscriber\NotesRouteGateSubscriber;
use Aurora\Module\Notes\NotesContext;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\HttpKernelInterface;

use function sprintf;

/**
 * Notes switched off stop answering, shared notes and published spaces
 * included, whichever of the two toggles is off.
 */
final class NotesRouteGateSubscriberTest extends TestCase
{
    private const array ROUTES = ['suite_notes_markdown', 'suite_notes_markdown_update', 'suite_notes_spaces_create', 'notes_share', 'notes_share_note', 'notes_public_space', 'notes_public_image'];

    public function testNotesOffCloseTheirScreensAndWhatTheyPublish(): void
    {
        foreach ([ModuleParameterEnum::NotesSuite, ModuleParameterEnum::NotesMarkdown] as $off) {
            foreach (self::ROUTES as $route) {
                self::assertTrue($this->blocks($route, $off), sprintf('route %s with %s off', $route, $off->value));
            }
        }

        foreach (self::ROUTES as $route) {
            self::assertFalse($this->blocks($route, null), sprintf('route %s', $route));
        }
    }

    public function testRoutesOfAnotherModuleAreNotItsBusiness(): void
    {
        self::assertFalse($this->blocks('suite_studio_spaces', ModuleParameterEnum::NotesSuite));
        self::assertFalse($this->blocks('workspace_space_notes', ModuleParameterEnum::NotesSuite));
    }

    private function blocks(string $route, ?ModuleParameterEnum $off): bool
    {
        $checker = $this->createStub(ModuleAccessChecker::class);
        $checker->method('isEnabled')->willReturnCallback(static fn (ModuleParameterEnum|string $toggle): bool => $toggle !== $off);

        $request = new Request();
        $request->attributes->set('_route', $route);

        try {
            new NotesRouteGateSubscriber(new NotesContext($checker))->onKernelRequest(
                new RequestEvent($this->createStub(HttpKernelInterface::class), $request, HttpKernelInterface::MAIN_REQUEST),
            );
        } catch (NotFoundHttpException) {
            return true;
        }

        return false;
    }
}
