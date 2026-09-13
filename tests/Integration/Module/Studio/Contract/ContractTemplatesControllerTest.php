<?php

declare(strict_types=1);

namespace Aurora\Tests\Integration\Module\Studio\Contract;

use Aurora\Module\Platform\User\Repository\UserRepository;
use Aurora\Module\Studio\Contract\Entity\ContractTemplate;
use Aurora\Module\Studio\Contract\Repository\ContractTemplateRepository;
use Aurora\Tests\Integration\IntegrationTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

use function json_decode;
use function sprintf;

/**
 * The template endpoint over HTTP.
 *
 * The manager's own rules are proven next door. What these check is the layer
 * the browser actually talks to: that creating a trame hands back the draft to
 * walk into, that a published version is refused a write with a sentence rather
 * than a 500, and that a version id from another trame cannot be edited under
 * this one's page.
 */
final class ContractTemplatesControllerTest extends IntegrationTestCase
{
    private KernelBrowser $client;

    private ContractTemplateRepository $templates;

    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = static::createClient();
        $container = static::getContainer();

        $admin = $container->get(UserRepository::class)
            ->findOneBy(['email' => 'dev@aurora.app', 'type' => 'backend']);
        $this->client->loginUser($admin, 'admin');

        $this->templates = $container->get(ContractTemplateRepository::class);
        $this->entityManager = $container->get(EntityManagerInterface::class);
    }

    protected function tearDown(): void
    {
        $this->entityManager->createQuery(
            sprintf('DELETE FROM %s', ContractTemplate::class),
        )->execute();

        parent::tearDown();
    }

    public function testCreatingATemplateHandsBackTheDraftToWalkInto(): void
    {
        $payload = $this->create('Contrat mensuel');

        self::assertTrue($payload['success']);
        self::assertSame('Contrat mensuel', $payload['template']['name']);
        self::assertSame('body', $payload['template']['kind']);
        // Without this the page would have to hunt for the first version, and
        // creating a trame then finding its draft are not two separate wishes.
        self::assertNotNull($payload['draftId']);
        self::assertNull($payload['template']['publishedVersion']);
    }

    public function testTheWordingIsSavedThenPublishedAndThenRefusedAWrite(): void
    {
        $created = $this->create('Contrat mensuel');
        $templateId = $created['template']['id'];
        $versionId = $created['draftId'];

        $this->client->jsonRequest(
            'POST',
            sprintf('/backend/studio/contract-templates/%d/versions/%d/save', $templateId, $versionId),
            ['translations' => [
                'fr' => ['title' => 'CONTRAT DE PRESTATION DE SERVICES', 'content' => ['blocks' => [['type' => 'header']]]],
            ]],
        );

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        $saved = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertSame('CONTRAT DE PRESTATION DE SERVICES', $saved['version']['translations']['fr']['title']);
        self::assertFalse($saved['version']['isPublished']);

        $this->client->jsonRequest(
            'POST',
            sprintf('/backend/studio/contract-templates/%d/versions/%d/publish', $templateId, $versionId),
        );

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        $published = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertTrue($published['version']['isPublished']);

        // The page never offers this; a stale tab does. It has to come back as
        // a sentence under the field rather than as a server error.
        $this->client->jsonRequest(
            'POST',
            sprintf('/backend/studio/contract-templates/%d/versions/%d/save', $templateId, $versionId),
            ['translations' => ['fr' => ['title' => 'Texte réécrit', 'content' => []]]],
        );

        self::assertSame(422, $this->client->getResponse()->getStatusCode());
        $refused = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertArrayHasKey('version', $refused['errors']);
    }

    public function testAnEmptyDraftIsRefusedPublication(): void
    {
        $created = $this->create('Contrat mensuel');

        $this->client->jsonRequest(
            'POST',
            sprintf(
                '/backend/studio/contract-templates/%d/versions/%d/publish',
                $created['template']['id'],
                $created['draftId'],
            ),
        );

        self::assertSame(422, $this->client->getResponse()->getStatusCode());
        $payload = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertArrayHasKey('translations', $payload['errors']);
    }

    public function testASecondDraftIsRefusedWithASentence(): void
    {
        $created = $this->create('Contrat mensuel');

        $this->client->jsonRequest(
            'POST',
            sprintf('/backend/studio/contract-templates/%d/open-draft', $created['template']['id']),
        );

        self::assertSame(422, $this->client->getResponse()->getStatusCode());
        $payload = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertArrayHasKey('draft', $payload['errors']);
        self::assertStringContainsString('version 1', $payload['errors']['draft']);
    }

    /**
     * A version belongs to one template, and the route says which.
     *
     * Without the ownership check the id alone would be enough, and a version
     * of another trame would be edited under this page's permissions.
     */
    public function testAVersionOfAnotherTemplateIsNotFound(): void
    {
        $first = $this->create('Contrat mensuel');
        $second = $this->create('Contrat one shot');

        $this->client->jsonRequest(
            'POST',
            sprintf(
                '/backend/studio/contract-templates/%d/versions/%d/save',
                $first['template']['id'],
                $second['draftId'],
            ),
            ['translations' => ['fr' => ['title' => 'Intrusion', 'content' => []]]],
        );

        self::assertSame(404, $this->client->getResponse()->getStatusCode());
    }

    public function testArchivingTakesATemplateOutOfTheSelectableSet(): void
    {
        $created = $this->create('Contrat mensuel');
        $id = $created['template']['id'];

        $this->client->jsonRequest('POST', sprintf('/backend/studio/contract-templates/%d/archive', $id));

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        $payload = json_decode((string) $this->client->getResponse()->getContent(), true);

        $archived = null;
        foreach ($payload['templates'] as $template) {
            if ($template['id'] === $id) {
                $archived = $template;
            }
        }

        self::assertNotNull($archived);
        self::assertTrue($archived['isArchived']);

        $this->client->jsonRequest('POST', sprintf('/backend/studio/contract-templates/%d/restore', $id));
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
    }

    public function testTheEditorPageRendersForADraft(): void
    {
        $created = $this->create('Contrat mensuel');

        $this->client->request(
            'GET',
            sprintf(
                '/backend/studio/contract-templates/%d/versions/%d',
                $created['template']['id'],
                $created['draftId'],
            ),
        );

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertStringContainsString('Contrat mensuel', (string) $this->client->getResponse()->getContent());
    }

    /**
     * The list can link to the version in force, and could not before.
     *
     * The number was serialised and the id was not, so the one row on this
     * screen a reader most wants to open - the wording actually in force - was
     * the one with no way in. Asserted from the rendered index rather than
     * from the serializer, because a field nobody passes to the component is
     * still a field the screen does not have.
     */
    public function testTheIndexCarriesTheIdOfTheVersionInForce(): void
    {
        $created = $this->create('Contrat mensuel');
        $templateId = $created['template']['id'];
        $versionId = $created['draftId'];

        $this->client->jsonRequest(
            'POST',
            sprintf('/backend/studio/contract-templates/%d/versions/%d/save', $templateId, $versionId),
            ['translations' => [
                'fr' => ['title' => 'Contrat', 'content' => ['blocks' => [['type' => 'header']]]],
            ]],
        );
        $this->client->jsonRequest(
            'POST',
            sprintf('/backend/studio/contract-templates/%d/versions/%d/publish', $templateId, $versionId),
        );
        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        $this->client->request('GET', '/backend/studio/contract-templates');

        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        $row = $this->rowFromIndex($templateId);

        self::assertNotNull($row);
        self::assertSame($versionId, $row['publishedVersionId']);
    }

    /**
     * One row of the `templates` prop, read back out of the rendered page.
     *
     * Named by its component rather than by the props attribute alone: the
     * backend layout mounts the sidemenu the same way, and it comes first.
     *
     * @return array<string, mixed>|null
     */
    private function rowFromIndex(int $templateId): ?array
    {
        $props = $this->client->getCrawler()
            ->filter('[data-symfony--ux-vue--vue-component-value="studio/backend/contract-templates/ContractTemplatesApp"]')
            ->attr('data-symfony--ux-vue--vue-props-value');

        $templates = json_decode((string) $props, true)['templates'] ?? [];

        foreach ($templates as $template) {
            if ($templateId === $template['id']) {
                return $template;
            }
        }

        return null;
    }

    /** @return array<string, mixed> */
    private function create(string $name): array
    {
        $this->client->jsonRequest('POST', '/backend/studio/contract-templates/create', [
            'name' => $name,
            'kind' => 'body',
        ]);

        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        return json_decode((string) $this->client->getResponse()->getContent(), true);
    }
}
