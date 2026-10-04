<?php

declare(strict_types=1);

namespace Aurora\Tests\Unit\Module\Ged\EventSubscriber;

use Aurora\Core\Module\Service\ModuleAccessChecker;
use Aurora\Module\Configuration\Setting\Enum\ModuleParameterEnum;
use Aurora\Module\Ged\EventSubscriber\GedRouteGateSubscriber;
use Aurora\Module\Ged\GedContext;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\HttpKernelInterface;

use function sprintf;

/**
 * Each media-library toggle closes its own screens and only those; the file
 * route shared with other modules follows the top-level toggle alone.
 */
final class GedRouteGateSubscriberTest extends TestCase
{
    /** @return iterable<string, array{ModuleParameterEnum, list<string>, list<string>}> toggle off, closed routes, routes left open */
    public static function toggleProvider(): iterable
    {
        yield 'documents' => [ModuleParameterEnum::GedDocuments, ['backend_ged_documents', 'backend_ged_documents_update', 'backend_ged_pexels_search'], ['backend_ged_tags', 'backend_ged_files', 'backend_ged_categories']];
        yield 'categories' => [ModuleParameterEnum::GedCategories, ['backend_ged_categories', 'backend_ged_categories_restore'], ['backend_ged_documents', 'backend_ged_tags']];
        yield 'tags' => [ModuleParameterEnum::GedTags, ['backend_ged_tags', 'backend_ged_tags_delete'], ['backend_ged_documents', 'backend_ged_folders']];
        yield 'folders' => [ModuleParameterEnum::GedFolders, ['backend_ged_folders', 'backend_ged_folders_move'], ['backend_ged_documents', 'backend_ged_files']];
        yield 'frontend' => [ModuleParameterEnum::GedFrontend, ['frontend_ged_index', 'frontend_ged_search'], ['backend_ged_documents', 'ged_document_view']];
        yield 'backend' => [ModuleParameterEnum::GedBackend, ['backend_ged_documents', 'backend_ged_tags', 'backend_ged_files'], ['frontend_ged_index', 'ged_document_view']];
    }

    /**
     * @param list<string> $closed
     * @param list<string> $open
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('toggleProvider')]
    public function testAToggleClosesItsOwnRoutesOnly(ModuleParameterEnum $off, array $closed, array $open): void
    {
        foreach ($closed as $route) {
            self::assertTrue($this->blocks($route, $off), sprintf('%s should be closed with %s off', $route, $off->value));
        }

        foreach ($open as $route) {
            self::assertFalse($this->blocks($route, $off), sprintf('%s should stay open with %s off', $route, $off->value));
        }
    }

    private function blocks(string $route, ModuleParameterEnum $off): bool
    {
        $checker = $this->createStub(ModuleAccessChecker::class);
        $checker->method('isEnabled')->willReturnCallback(static fn (ModuleParameterEnum|string $toggle): bool => $toggle !== $off);

        $request = new Request();
        $request->attributes->set('_route', $route);

        try {
            new GedRouteGateSubscriber(new GedContext($checker))->onKernelRequest(
                new RequestEvent($this->createStub(HttpKernelInterface::class), $request, HttpKernelInterface::MAIN_REQUEST),
            );
        } catch (NotFoundHttpException) {
            return true;
        }

        return false;
    }
}
