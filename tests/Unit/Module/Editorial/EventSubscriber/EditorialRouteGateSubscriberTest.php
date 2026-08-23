<?php

declare(strict_types=1);

namespace Aurora\Tests\Unit\Module\Editorial\EventSubscriber;

use Aurora\Core\Module\Service\ModuleAccessChecker;
use Aurora\Module\Configuration\Setting\Enum\ModuleParameterEnum;
use Aurora\Module\Editorial\EditorialContext;
use Aurora\Module\Editorial\EventSubscriber\EditorialRouteGateSubscriber;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * Switching a module off used to remove its side-menu entries and nothing
 * else: the controllers stayed registered and answered 200 to anyone with
 * the URL. A disabled module has to be closed at the door.
 *
 * The sub-module cases are the point of the test. Editorial's toggles are
 * per-domain, so turning off Taxonomies alone must close the taxonomy
 * screens and leave posts and menus open - a single module-wide gate would
 * pass this file's first two tests and fail every one after them.
 */
final class EditorialRouteGateSubscriberTest extends TestCase
{
    public function testLetsThroughRoutesOfAnotherModuleEntirely(): void
    {
        $this->assertPasses('backend_ged_documents', []);
        $this->assertPasses('frontend_login', []);
    }

    public function testClosesEverythingWhenTheBackendIsOff(): void
    {
        $off = [ModuleParameterEnum::EditorialBackend->value];

        $this->assertBlocked('backend_editorial_posts', $off);
        $this->assertBlocked('backend_editorial_taxonomies', $off);
        $this->assertBlocked('backend_editorial_menus', $off);
        $this->assertBlocked('backend_editorial_post_types', $off);
    }

    public function testClosesOnlyTheSubModuleThatIsOff(): void
    {
        $off = [ModuleParameterEnum::EditorialTaxonomies->value];

        $this->assertBlocked('backend_editorial_taxonomies', $off);
        $this->assertBlocked('backend_editorial_taxonomies_term_create', $off);

        $this->assertPasses('backend_editorial_posts', $off);
        $this->assertPasses('backend_editorial_menus', $off);
        $this->assertPasses('backend_editorial_post_types', $off);
    }

    /**
     * `backend_editorial_posts` must not swallow `backend_editorial_post_types`
     * - they share a prefix up to the `s`, and getting this wrong closes the
     * post-types screen whenever posts are off.
     */
    public function testDoesNotConfuseTwoRouteNamesThatShareAPrefix(): void
    {
        $off = [ModuleParameterEnum::EditorialPosts->value];

        $this->assertBlocked('backend_editorial_posts', $off);
        $this->assertPasses('backend_editorial_post_types', $off);
    }

    /** The public routes are Core's to gate; this subscriber must not touch them. */
    public function testIgnoresThePublicRoutes(): void
    {
        $this->assertPasses('editorial_post', [ModuleParameterEnum::EditorialBackend->value]);
        $this->assertPasses('editorial_home', [ModuleParameterEnum::EditorialBackend->value]);
    }

    /** @param list<string> $disabled */
    private function assertBlocked(string $route, array $disabled): void
    {
        $this->expectingBlocked($route, $disabled, true);
    }

    /** @param list<string> $disabled */
    private function assertPasses(string $route, array $disabled): void
    {
        $this->expectingBlocked($route, $disabled, false);
    }

    /** @param list<string> $disabled */
    private function expectingBlocked(string $route, array $disabled, bool $blocked): void
    {
        $subscriber = new EditorialRouteGateSubscriber(
            new EditorialContext($this->accessChecker($disabled)),
        );

        $request = new Request();
        $request->attributes->set('_route', $route);

        $event = new RequestEvent(
            $this->createStub(HttpKernelInterface::class),
            $request,
            HttpKernelInterface::MAIN_REQUEST,
        );

        $thrown = false;

        try {
            $subscriber->onKernelRequest($event);
        } catch (NotFoundHttpException) {
            $thrown = true;
        }

        self::assertSame($blocked, $thrown, sprintf('route %s', $route));
    }

    /**
     * Stands in for the real checker, cascade included: a sub-module whose
     * ancestor is off is off too, which is what makes the "backend off closes
     * everything" case meaningful.
     *
     * @param list<string> $disabled
     */
    private function accessChecker(array $disabled): ModuleAccessChecker
    {
        $checker = $this->createStub(ModuleAccessChecker::class);
        $checker->method('isEnabled')->willReturnCallback(
            static function (ModuleParameterEnum|string $toggle) use ($disabled): bool {
                $case = $toggle instanceof ModuleParameterEnum ? $toggle : ModuleParameterEnum::from($toggle);

                if (in_array($case->value, $disabled, true)) {
                    return false;
                }

                for ($parent = $case->getParentCase(); null !== $parent; $parent = $parent->getParentCase()) {
                    if (in_array($parent->value, $disabled, true)) {
                        return false;
                    }
                }

                return true;
            },
        );

        return $checker;
    }
}
