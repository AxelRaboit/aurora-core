<?php

declare(strict_types=1);

namespace Aurora\Tests\Integration\Module\Editorial\Form;

use Aurora\Module\Editorial\Form\Entity\Form;
use Aurora\Tests\Integration\IntegrationTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * The page a form has of its own, at `/{locale}/forms/{slug}`.
 *
 * It carries the same questions as the content the form was posed in, under a
 * second address. Nothing links to it and it is out of the sitemap, so the
 * duplicate is theoretical - right up until a menu entry points at it, at
 * which point the site is publishing a rival to its own contact page without
 * having asked to.
 *
 * So the page goes on working, and says by default that it would rather not be
 * listed.
 */
final class FormStandalonePageTest extends IntegrationTestCase
{
    private KernelBrowser $client;

    private EntityManagerInterface $entityManager;

    /** @var list<object> */
    private array $created = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = static::createClient();
        $this->entityManager = static::getContainer()->get(EntityManagerInterface::class);
    }

    protected function tearDown(): void
    {
        foreach (array_reverse($this->created) as $entity) {
            $this->entityManager->remove($entity);
        }

        $this->entityManager->flush();
        $this->created = [];

        parent::tearDown();
    }

    public function testThePageStillAnswers(): void
    {
        $slug = $this->publishForm(indexed: false);

        $this->client->request('GET', '/fr/forms/'.$slug);

        self::assertResponseIsSuccessful();
    }

    public function testItAsksNotToBeListedByDefault(): void
    {
        $slug = $this->publishForm(indexed: false);

        $this->client->request('GET', '/fr/forms/'.$slug);

        self::assertStringContainsString(
            '<meta name="robots" content="noindex, nofollow">',
            (string) $this->client->getResponse()->getContent(),
        );
    }

    /** A form whose own page is the one people are meant to find says so. */
    public function testAFormCanAskForItsPageToBeListed(): void
    {
        $slug = $this->publishForm(indexed: true);

        $this->client->request('GET', '/fr/forms/'.$slug);

        self::assertStringNotContainsString(
            'name="robots"',
            (string) $this->client->getResponse()->getContent(),
        );
    }

    private function publishForm(bool $indexed): string
    {
        $slug = 'contact-'.bin2hex(random_bytes(4));

        $form = new Form();
        $form->setActive(true);
        $form->setStandalonePageIndexed($indexed);
        $form->translate('fr')->setTitle('Me contacter')->setSlug($slug);

        $this->entityManager->persist($form);
        $this->entityManager->flush();

        $this->created[] = $form;

        return $slug;
    }
}
