<?php

declare(strict_types=1);

namespace Aurora\Tests\Integration\Module\Editorial\Form;

use Aurora\Core\Locale\Service\LocaleContextInterface;
use Aurora\Module\Editorial\Form\Entity\Form;
use Aurora\Module\Editorial\Form\Entity\FormInterface;
use Aurora\Module\Editorial\Form\Enum\FormFieldTypeEnum;
use Aurora\Module\Platform\User\Entity\User;
use Aurora\Module\Platform\User\Repository\UserRepository;
use Aurora\Tests\Integration\IntegrationTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

use function array_map;
use function array_values;
use function bin2hex;
use function html_entity_decode;
use function json_decode;
use function preg_match;
use function random_bytes;
use function sprintf;

/**
 * The forms screens: a list, a page per form, and a creation that starts from
 * a template.
 *
 * Forms used to be one side-menu entry each, `/forms` redirected to the first,
 * and creating one asked for every language's title, slug and description
 * before the form had a single question. What these tests hold: the list
 * renders and stays a list, the menu carries one entry, and a title plus a
 * template gives a form with its questions already written in every language.
 */
final class FormsScreensTest extends IntegrationTestCase
{
    private KernelBrowser $client;

    private EntityManagerInterface $entityManager;

    /** @var list<int> */
    private array $created = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = static::createClient();
        $user = static::getContainer()->get(UserRepository::class)->findOneBy(['email' => 'dev@aurora.app', 'type' => 'backend']);
        self::assertInstanceOf(User::class, $user);
        $this->client->loginUser($user, 'admin');
        $this->client->setServerParameter('HTTP_X-Requested-With', 'XMLHttpRequest');

        $this->entityManager = static::getContainer()->get(EntityManagerInterface::class);
    }

    protected function tearDown(): void
    {
        foreach ($this->created as $id) {
            $form = $this->entityManager->find(Form::class, $id);
            if (null !== $form) {
                $this->entityManager->remove($form);
            }
        }

        $this->entityManager->flush();

        parent::tearDown();
    }

    public function testATitleAndATemplateGiveAFormWithItsQuestionsInEveryLanguage(): void
    {
        $form = $this->create('quote');

        $locales = static::getContainer()->get(LocaleContextInterface::class)->getActiveLocales();
        self::assertNotSame([], $locales);

        foreach ($locales as $locale) {
            self::assertNotNull($form->getTranslation($locale), sprintf('the form has a %s title', $locale));
        }

        self::assertCount(2, $form->getSteps() ?? []);

        $fields = array_values($form->getFields()->toArray());
        self::assertSame(
            ['text', 'email', 'tel', 'textarea', 'select', 'date'],
            array_map(static fn ($field): string => $field->getType()->value, $fields),
        );
        self::assertSame([1, 1, 1, 2, 2, 2], array_map(static fn ($field): ?int => $field->getStep(), $fields));

        // Worded in each language, not the creator's copied into the others.
        $name = $fields[0];
        self::assertSame('Nom complet', $name->getTranslation('fr')?->getLabel());
        if (null !== $name->getTranslation('en')) {
            self::assertSame('Full name', $name->getTranslation('en')->getLabel());
        }

        $budget = $fields[4];
        self::assertSame(FormFieldTypeEnum::Select, $budget->getType());
        self::assertCount(4, $budget->getTranslation('fr')?->getOptions() ?? []);
    }

    public function testABlankFormStartsWithNoQuestion(): void
    {
        $form = $this->create('blank');

        self::assertCount(0, $form->getFields());
        self::assertNull($form->getSteps());
    }

    public function testATitleIsStillRequired(): void
    {
        $this->client->jsonRequest('POST', '/backend/editorial/forms', ['title' => '   ', 'template' => 'contact']);

        self::assertSame(422, $this->client->getResponse()->getStatusCode());
    }

    /** The list stays a list, whether forms exist or not: it no longer redirects to the first. */
    public function testTheListRendersWithFormsToo(): void
    {
        $this->create('contact');

        $this->client->request('GET', '/backend/editorial/forms');

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertStringContainsString('editorial/backend/forms/FormsApp', (string) $this->client->getResponse()->getContent());
    }

    public function testAFormHasItsOwnPage(): void
    {
        $form = $this->create('contact');

        $this->client->request('GET', sprintf('/backend/editorial/forms/%d', $form->getId()));

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertStringContainsString('editorial/backend/forms/FormEditorApp', (string) $this->client->getResponse()->getContent());
    }

    /** One menu entry for every form, and no group listing them one by one. */
    public function testTheMenuCarriesOneEntryAndNoFormGroup(): void
    {
        $this->create('contact');

        $this->client->request('GET', '/backend/editorial/forms');

        $matched = preg_match(
            '/vue-component-value="core\/backend\/sidemenu\/AppSidemenu" data-symfony--ux-vue--vue-props-value="([^"]*)"/',
            (string) $this->client->getResponse()->getContent(),
            $matches,
        );
        self::assertSame(1, $matched);

        $view = json_decode(html_entity_decode($matches[1], ENT_QUOTES), true, 512, JSON_THROW_ON_ERROR)['moduleNavView'] ?? [];

        $groups = [];
        $paths = [];
        foreach ($view['groups'] ?? [] as $group) {
            $groups[] = $group['id'];
            foreach ($group['items'] as $item) {
                $paths[] = $item['path'] ?? '';
            }
        }

        self::assertNotContains('forms', $groups);
        self::assertContains('/backend/editorial/forms', $paths);
    }

    private function create(string $template): FormInterface
    {
        $this->client->jsonRequest('POST', '/backend/editorial/forms', [
            'title' => 'Essai '.bin2hex(random_bytes(4)),
            'template' => $template,
        ]);

        self::assertSame(200, $this->client->getResponse()->getStatusCode(), (string) $this->client->getResponse()->getContent());

        $payload = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertStringEndsWith('/backend/editorial/forms/'.$payload['form']['id'], $payload['editPath']);

        $this->created[] = (int) $payload['form']['id'];
        $this->entityManager->clear();

        $form = $this->entityManager->find(Form::class, $payload['form']['id']);
        self::assertInstanceOf(FormInterface::class, $form);

        return $form;
    }
}
