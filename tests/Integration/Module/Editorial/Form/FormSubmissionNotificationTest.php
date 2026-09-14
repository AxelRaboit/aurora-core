<?php

declare(strict_types=1);

namespace Aurora\Tests\Integration\Module\Editorial\Form;

use Aurora\Module\Configuration\Setting\Enum\ApplicationParameterEnum;
use Aurora\Module\Configuration\Setting\Repository\SettingRepository;
use Aurora\Module\Editorial\Form\Entity\Form;
use Aurora\Module\Editorial\Form\Entity\FormField;
use Aurora\Module\Editorial\Form\Enum\FormFieldTypeEnum;
use Aurora\Module\Editorial\Form\Message\DeliverFormSubmissionMessage;
use Aurora\Module\Editorial\Form\MessageHandler\DeliverFormSubmissionHandler;
use Aurora\Tests\Integration\Concern\ResetsRateLimiters;
use Aurora\Tests\Integration\IntegrationTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Mime\Email;

/**
 * What a submission causes once it is stored: who is told, when, and in which
 * language.
 *
 * The mails used to go out inside the visitor's own request, so a mail server
 * that was down answered *them* with an error for a message that had in fact
 * been recorded - and they wrote it again. They are queued now, which is the
 * first thing asserted here, because it is the part a passing suite would
 * otherwise never notice going back.
 */
final class FormSubmissionNotificationTest extends IntegrationTestCase
{
    use ResetsRateLimiters;

    private const string OWNER = 'owner@aurora.test';

    private KernelBrowser $client;

    private EntityManagerInterface $entityManager;

    /** @var list<object> */
    private array $created = [];

    private int $nameFieldId = 0;

    private int $emailFieldId = 0;

    /** @var array<string, string> */
    private array $slugs = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = static::createClient();
        $this->entityManager = static::getContainer()->get(EntityManagerInterface::class);

        $this->resetRateLimiter('form_submission');
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

    /**
     * The request the visitor waits on does no outbound work at all. This is
     * the whole point of the change: the answer they get says the message was
     * received, and it says so before anyone tries to mail anybody.
     */
    public function testTheVisitorsRequestSendsNothingItself(): void
    {
        $this->publishForm();

        $this->submit('Camille', 'camille@example.test');

        self::assertResponseIsSuccessful();
        self::assertSame([], $this->mailerMessages(), 'the visitor waited on a mail being sent');
        self::assertCount(1, $this->queued(), 'nothing was queued to announce the submission');
    }

    /** Both mails: one to whoever watches the form, one back to the visitor. */
    public function testTheWorkerSendsBothMails(): void
    {
        $this->publishForm();
        $this->submit('Camille', 'camille@example.test');

        $this->runQueue();

        $recipients = array_map(
            static fn (Email $email): string => $email->getTo()[0]->getAddress(),
            $this->mailerMessages(),
        );

        self::assertSame([self::OWNER, 'camille@example.test'], $recipients);
    }

    /**
     * Hitting reply must reach the person who wrote. Without the header the
     * only way to answer is to reopen the builder and copy the address out by
     * hand, once per message.
     */
    public function testTheOwnersMailRepliesToTheVisitor(): void
    {
        $this->publishForm();
        $this->submit('Camille', 'camille@example.test');

        $this->runQueue();

        $replyTo = $this->ownerMail()->getReplyTo();

        self::assertCount(1, $replyTo);
        self::assertSame('camille@example.test', $replyTo[0]->getAddress());
    }

    /**
     * A submission through the English form is still announced in the site's
     * language. It used to arrive in the visitor's, which says nothing about
     * the message and leaves the inbox unsortable.
     */
    public function testTheOwnersMailIsInTheSiteLanguage(): void
    {
        $this->publishForm();
        $this->submit('Camille', 'camille@example.test', locale: 'en');

        $this->runQueue();

        self::assertStringContainsString('Nouvelle soumission', $this->ownerMail()->getSubject() ?? '');
    }

    /** The visitor, on the other hand, is answered in the language they used. */
    public function testTheVisitorsConfirmationIsInTheirOwnLanguage(): void
    {
        $this->publishForm();
        $this->submit('Camille', 'camille@example.test', locale: 'en');

        $this->runQueue();

        $confirmation = $this->mailerMessages()[1];

        self::assertStringContainsString('Your message', $confirmation->getSubject() ?? '');
    }

    /**
     * Enough to find the submission again weeks later, without searching the
     * back office by hand: which one it is, when it arrived, and a way in.
     */
    public function testTheOwnersMailCarriesTheReferenceAndAWayBack(): void
    {
        $form = $this->publishForm();
        $this->submit('Camille', 'camille@example.test');

        $this->runQueue();

        $body = $this->ownerMail()->getHtmlBody();
        self::assertIsString($body);

        self::assertMatchesRegularExpression('/FSUB|SUB/', $body, 'the mail names no submission reference');
        self::assertStringContainsString('/backend/editorial/forms/'.$form->getId(), $body);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function submit(string $name, string $email, string $locale = 'fr'): void
    {
        $answers = [
            (string) $this->nameFieldId => $name,
            (string) $this->emailFieldId => $email,
        ];

        $this->client->request(
            'POST',
            sprintf('/%s/forms/%s', $locale, $this->slugs[$locale]),
            server: ['CONTENT_TYPE' => 'application/json'],
            content: (string) json_encode($answers),
        );

        // Asserted here rather than in each test: a refused submission stores
        // nothing and queues nothing, so every assertion downstream would fail
        // for a reason that has nothing to do with what it is testing.
        self::assertResponseIsSuccessful((string) $this->client->getResponse()->getContent());
    }

    /** @return list<DeliverFormSubmissionMessage> */
    private function queued(): array
    {
        $transport = static::getContainer()->get('messenger.transport.async');
        self::assertInstanceOf(InMemoryTransport::class, $transport);

        $messages = [];
        foreach ($transport->getSent() as $envelope) {
            $message = $envelope->getMessage();
            if ($message instanceof DeliverFormSubmissionMessage) {
                $messages[] = $message;
            }
        }

        return $messages;
    }

    /**
     * Stands in for the worker. The handler is invoked directly rather than
     * through the bus: what is under test is what the queued work does, and
     * going through the bus would only re-queue it.
     */
    private function runQueue(): void
    {
        $handler = static::getContainer()->get(DeliverFormSubmissionHandler::class);

        foreach ($this->queued() as $message) {
            $handler($message);
        }
    }

    /**
     * The mails that actually reached a transport.
     *
     * Queued events are skipped: Messenger is enabled, so the mailer hands
     * each message to the bus and one `MessageEvent` is dispatched queued at
     * that point, a second unqueued when it goes through the transport.
     * Counting both reads one mail as two.
     *
     * @return list<Email>
     */
    private function mailerMessages(): array
    {
        $messages = [];

        foreach (static::getMailerEvents() as $event) {
            if ($event->isQueued()) {
                continue;
            }

            $message = $event->getMessage();
            self::assertInstanceOf(Email::class, $message);
            $messages[] = $message;
        }

        return $messages;
    }

    private function ownerMail(): Email
    {
        $messages = $this->mailerMessages();
        self::assertNotSame([], $messages, 'no mail was sent at all');
        self::assertSame(self::OWNER, $messages[0]->getTo()[0]->getAddress());

        return $messages[0];
    }

    /**
     * A form in the three seeded locales, watched by a known address so the
     * assertions never depend on whether this install has an administrator
     * email set.
     */
    private function publishForm(): Form
    {
        $suffix = bin2hex(random_bytes(4));

        static::getContainer()->get(SettingRepository::class)
            ->set(ApplicationParameterEnum::EmailLocale->value, 'fr');

        $form = new Form();
        $form->setActive(true);
        $form->setNotifyEmail(self::OWNER);
        $form->translate('fr')->setTitle('Me contacter')->setSlug('contact-'.$suffix);
        $form->translate('en')->setTitle('Get in touch')->setSlug('contact-en-'.$suffix);

        // addField, not setForm alone. The row is written either way; what
        // differs is this entity manager's copy of the form, and the handler
        // below runs in-process against that same copy. A form whose inverse
        // collection was never updated hands the code under test no fields at
        // all, so the confirmation mail finds no address and the notification
        // gets no Reply-To - a failure that looks like the feature, not like
        // the fixture. MenuSectionHighlightTest solves the same problem from
        // the other end, with a refresh().
        $name = new FormField();
        $form->addField($name);
        $name->setType(FormFieldTypeEnum::Text)->setRequired(true)->setPosition(0);
        $name->translate('fr')->setLabel('Votre nom');
        $name->translate('en')->setLabel('Your name');

        $email = new FormField();
        $form->addField($email);
        $email->setType(FormFieldTypeEnum::Email)->setRequired(true)->setPosition(1);
        $email->translate('fr')->setLabel('Votre e-mail');
        $email->translate('en')->setLabel('Your email');

        $this->entityManager->persist($form);
        $this->entityManager->persist($name);
        $this->entityManager->persist($email);
        $this->entityManager->flush();

        $this->created[] = $email;
        $this->created[] = $name;
        $this->created[] = $form;

        // Held as ids rather than read back off $form->getFields(): a request
        // reboots the kernel, so the collection this test would read is not
        // the one the server answers from.
        $this->nameFieldId = (int) $name->getId();
        $this->emailFieldId = (int) $email->getId();
        $this->slugs = ['fr' => 'contact-'.$suffix, 'en' => 'contact-en-'.$suffix];

        return $form;
    }
}
