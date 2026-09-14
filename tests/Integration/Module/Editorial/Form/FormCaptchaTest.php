<?php

declare(strict_types=1);

namespace Aurora\Tests\Integration\Module\Editorial\Form;

use Aurora\Module\Editorial\Captcha\CaptchaProviderEnum;
use Aurora\Module\Editorial\Captcha\CaptchaSettings;
use Aurora\Module\Editorial\Form\Entity\Form;
use Aurora\Module\Editorial\Form\Entity\FormField;
use Aurora\Module\Editorial\Form\Enum\FormFieldTypeEnum;
use Aurora\Module\Editorial\Form\Repository\FormSubmissionRepository;
use Aurora\Tests\Integration\IntegrationTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * The anti-robot check on a public form.
 *
 * It guarded comments and not forms, which is the wrong way round: a comment
 * waits for a moderator, while a submission is validated, stored, mailed to
 * the owner and pushed to their webhook the moment it arrives. A form is also
 * the one thing on a site that a robot can find with no link to follow.
 *
 * Through the served endpoint rather than against the controller: what is
 * being tested is that a submission with no token never reaches the manager,
 * and only a real request proves the order the checks run in.
 *
 * One request per test method - the kernel reboots between them, which is what
 * makes a freshly written setting visible to SettingRepository's per-request
 * cache.
 */
final class FormCaptchaTest extends IntegrationTestCase
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

    public function testASubmissionWithNoTokenIsRefusedWhenTheCheckIsOn(): void
    {
        [$slug, $fieldId] = $this->publishForm();
        $this->configureCaptcha(true);

        $this->submit($slug, [(string) $fieldId => 'Bonjour']);

        self::assertResponseStatusCodeSame(429);
        self::assertSame(0, $this->submissionCount(), 'a refused submission was stored anyway');
    }

    /**
     * The message must not say which check refused. Naming it is telling a
     * spammer what to change, which is why the flood limit and the challenge
     * answer with the same key.
     */
    public function testTheRefusalDoesNotSayWhichCheckItWas(): void
    {
        [$slug, $fieldId] = $this->publishForm();
        $this->configureCaptcha(true);

        $this->submit($slug, [(string) $fieldId => 'Bonjour']);

        $payload = json_decode((string) $this->client->getResponse()->getContent(), true);

        self::assertIsArray($payload);
        self::assertSame('frontend.editorial.forms.errors.too_many', $payload['error'] ?? null);
    }

    /** A site with no check configured goes on accepting submissions. */
    public function testASubmissionGoesThroughWhenTheCheckIsOff(): void
    {
        [$slug, $fieldId] = $this->publishForm();
        $this->configureCaptcha(false);

        $this->submit($slug, [(string) $fieldId => 'Bonjour']);

        self::assertResponseIsSuccessful();
        self::assertSame(1, $this->submissionCount());
    }

    /** @param array<string, string> $answers */
    private function submit(string $slug, array $answers): void
    {
        $this->client->request(
            'POST',
            '/fr/forms/'.$slug,
            server: ['CONTENT_TYPE' => 'application/json'],
            content: (string) json_encode($answers),
        );
    }

    private function configureCaptcha(bool $enabled): void
    {
        static::getContainer()->get(CaptchaSettings::class)->save(
            $enabled,
            CaptchaProviderEnum::Turnstile,
            $enabled ? '0x4AAAAAAAsitekey' : '',
            $enabled ? '0x4AAAAAAAsecret' : '',
        );
    }

    private function submissionCount(): int
    {
        return count(static::getContainer()->get(FormSubmissionRepository::class)->findAll());
    }

    /** @return array{0: string, 1: int} the form's slug and its one field's id */
    private function publishForm(): array
    {
        $suffix = bin2hex(random_bytes(4));

        $form = new Form();
        $form->setActive(true);
        $form->translate('fr')->setTitle('Contact')->setSlug('contact-'.$suffix);

        // addField, not setForm alone: the collection carries orphanRemoval, so
        // a field attached only from its own side is removed again by the same
        // flush - and the form this test submits to would have no questions at
        // all, which accepts anything and proves nothing.
        $field = new FormField();
        $form->addField($field);
        $field->setType(FormFieldTypeEnum::Text)->setRequired(true)->setPosition(0);
        $field->translate('fr')->setLabel('Message');

        $this->entityManager->persist($form);
        $this->entityManager->persist($field);
        $this->entityManager->flush();

        $this->created[] = $field;
        $this->created[] = $form;

        return ['contact-'.$suffix, (int) $field->getId()];
    }
}
