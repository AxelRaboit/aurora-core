<?php

declare(strict_types=1);

namespace Aurora\Tests\Integration\Module\Editorial\Post;

use Aurora\Module\Editorial\Form\Entity\Form;
use Aurora\Module\Platform\User\Entity\User;
use Aurora\Module\Platform\User\Repository\UserRepository;
use Aurora\Tests\Integration\IntegrationTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * The forms the grid editor may pose, all the way to the browser.
 *
 * `editView` computed the list from the first day the form zone existed, and
 * the Twig template that mounts the editor never carried it. Nothing broke
 * loudly: the component declares `forms` with an empty default, so the picker
 * rendered with nothing in it but its own placeholder - and a zone that
 * already named a form displayed as if it named none, because a browser falls
 * back to the first option when the selected value matches no other.
 *
 * Through the served page rather than against the view builder: the builder
 * was right the whole time. What was missing was one line of the template, and
 * only the rendered page has it.
 */
final class EditorFormChoicesTest extends IntegrationTestCase
{
    private KernelBrowser $client;

    private EntityManagerInterface $entityManager;

    /** @var list<object> */
    private array $created = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = self::createClient();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);

        $admin = self::getContainer()->get(UserRepository::class)
            ->findOneBy(['email' => 'dev@aurora.app', 'type' => 'backend']);
        self::assertInstanceOf(User::class, $admin);
        $this->client->loginUser($admin, 'admin');
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

    public function testTheEditorIsHandedTheFormsItCanPose(): void
    {
        $title = 'Devis '.bin2hex(random_bytes(4));
        $this->publishForm($title);

        $this->client->request('GET', '/backend/editorial/posts/new');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString(
            $title,
            (string) $this->client->getResponse()->getContent(),
            'the form picker will render empty, and a zone naming a form will look like it names none',
        );
    }

    private function publishForm(string $title): void
    {
        $form = new Form();
        $form->setActive(true);
        $form->translate('fr')->setTitle($title)->setSlug(mb_strtolower(str_replace(' ', '-', $title)));

        $this->entityManager->persist($form);
        $this->entityManager->flush();

        $this->created[] = $form;
    }
}
