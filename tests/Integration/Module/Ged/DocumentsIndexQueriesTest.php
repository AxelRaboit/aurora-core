<?php

declare(strict_types=1);

namespace Aurora\Tests\Integration\Module\Ged;

use Aurora\Core\Validation\Dto\PaginationRequest;
use Aurora\Module\Ged\Document\View\DocumentsViewBuilder;
use Aurora\Tests\Integration\IntegrationTestCase;

use function array_filter;
use function count;
use function str_contains;

/**
 * The library's first render counts its folders once.
 *
 * The sidebar counted them, then the document list it embeds counted them
 * again for the same screen.
 */
final class DocumentsIndexQueriesTest extends IntegrationTestCase
{
    public function testTheFoldersAreCountedOnce(): void
    {
        static::bootKernel();
        $holder = static::getContainer()->get('doctrine.debug_data_holder');
        $holder->reset();

        $view = static::getContainer()->get(DocumentsViewBuilder::class)->indexView(new PaginationRequest(1, 20, null));

        self::assertSame($view['folders'], $view['documents']['folders']);

        $folderReads = array_filter(
            $holder->getData()['default'] ?? [],
            static fn (array $query): bool => str_contains((string) $query['sql'], 'FROM core_ged_document_folders'),
        );
        self::assertSame(1, count($folderReads));
    }
}
