<?php

declare(strict_types=1);

namespace Aurora\Tests\Integration\Module\Editorial\Post;

use Aurora\Module\Configuration\Theme\Enum\ThemeFontEnum;
use Aurora\Module\Editorial\Post\View\PostsViewBuilder;
use Aurora\Module\Platform\User\Entity\User;
use Aurora\Module\Platform\User\Repository\UserRepository;
use Aurora\Tests\Integration\IntegrationTestCase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DomCrawler\Crawler;

use function array_diff;
use function array_key_exists;
use function array_keys;
use function array_values;
use function is_array;
use function json_decode;

/**
 * Everything the post editor's view builder computes reaches the editor.
 *
 * Twice a list was computed by `editView` and dropped by the Twig template
 * that mounts the editor, with nothing failing: `forms` first, then `decks`,
 * whose picker stayed empty until 4.9.2. The component declares each list
 * with an empty default, so a missing one does not break the page, it only
 * shows nothing. One check over every key rather than one test per list: the
 * next one added is covered without anyone thinking of it.
 */
final class EditorPropsTest extends IntegrationTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = self::createClient();

        $admin = self::getContainer()->get(UserRepository::class)
            ->findOneBy(['email' => 'dev@aurora.app', 'type' => 'suite']);
        self::assertInstanceOf(User::class, $admin);
        $this->client->loginUser($admin, 'admin');
    }

    public function testEveryKeyOfTheEditViewReachesTheEditor(): void
    {
        $props = $this->editorProps();
        $computed = array_keys(self::getContainer()->get(PostsViewBuilder::class)->editView());

        self::assertSame(
            [],
            array_values(array_diff($computed, array_keys($props))),
            'computed by editView but never handed to PostEditorApp by edit.html.twig',
        );
    }

    /** The banner's font picker gets the theme's list, Sora included. */
    public function testTheBannerFontsAreTheThemeFonts(): void
    {
        $fonts = $this->editorProps()['bannerFonts'] ?? null;

        self::assertIsArray($fonts);
        self::assertSame(ThemeFontEnum::choices(), $fonts);
    }

    /** @return array<string, mixed> */
    private function editorProps(): array
    {
        $crawler = $this->client->request('GET', '/suite/editorial/posts/new');
        self::assertResponseIsSuccessful();

        foreach ($crawler->filter('[data-symfony--ux-vue--vue-props-value]') as $node) {
            $props = json_decode((string) (new Crawler($node))->attr('data-symfony--ux-vue--vue-props-value'), true);
            if (is_array($props) && array_key_exists('createPath', $props)) {
                return $props;
            }
        }

        self::fail('the post editor is not on the page');
    }
}
