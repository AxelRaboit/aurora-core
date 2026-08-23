<?php

declare(strict_types=1);

namespace Aurora\Tests\Unit\Service;

use Aurora\Core\Mail\Service\MailService;
use Aurora\Module\Configuration\Setting\Enum\ApplicationParameterEnum;
use Aurora\Module\Configuration\Setting\Repository\SettingRepository;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Translation\LocaleSwitcher;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

#[AllowMockObjectsWithoutExpectations]
final class MailServiceTest extends TestCase
{
    private MailerInterface $mailer;
    private Environment $twig;
    private SettingRepository $settings;
    private TranslatorInterface $translator;
    private LocaleSwitcher $localeSwitcher;
    private MailService $service;

    protected function setUp(): void
    {
        $this->mailer = $this->createMock(MailerInterface::class);
        $this->twig = $this->createMock(Environment::class);
        $this->settings = $this->createMock(SettingRepository::class);
        $this->translator = $this->createMock(TranslatorInterface::class);
        $this->localeSwitcher = $this->createMock(LocaleSwitcher::class);
        $this->service = new MailService(
            $this->mailer,
            $this->twig,
            $this->settings,
            $this->translator,
            $this->localeSwitcher,
            'noreply@aurora.local',
        );
    }

    public function testSendNoOpsWhenRecipientIsEmpty(): void
    {
        $this->mailer->expects(self::never())->method('send');
        $this->service->send('', 'subject.key', '@template.html.twig');
    }

    public function testSendBuildsBracketedSubjectAndDelegatesToMailer(): void
    {
        $this->settings->method('getOrDefault')->willReturn('Aurora Site');
        $this->settings->method('get')->willReturn(null); // no EmailLocale, no DefaultLocale
        $this->translator->expects(self::once())
            ->method('trans')
            ->with('subject.key', [])
            ->willReturn('Hello');
        $this->twig->expects(self::once())
            ->method('render')
            ->with('@tpl.html.twig', self::callback(fn (array $ctx): bool => 'Aurora Site' === $ctx['siteName']))
            ->willReturn('<p>body</p>');

        $this->mailer->expects(self::once())
            ->method('send')
            ->with(self::callback(function (Email $email): bool {
                self::assertSame('[Aurora Site] Hello', $email->getSubject());
                self::assertSame('to@example.com', $email->getTo()[0]->getAddress());
                self::assertStringContainsString('<p>body</p>', $email->getHtmlBody());

                return true;
            }));

        $this->service->send('to@example.com', 'subject.key', '@tpl.html.twig');
    }

    public function testSendUsesExplicitLocaleOverEmailLocaleSetting(): void
    {
        $this->settings->method('getOrDefault')->willReturn('Aurora');
        $this->translator->method('trans')->willReturn('Subject');
        $this->twig->method('render')->willReturn('body');

        // Explicit locale → LocaleSwitcher::runWithLocale('en')
        $this->localeSwitcher->expects(self::once())
            ->method('runWithLocale')
            ->with('en', self::callback('is_callable'))
            ->willReturnCallback(static fn (string $_, callable $cb) => $cb());

        $this->service->send('to@example.com', 'k', '@t.html.twig', locale: 'en');
    }

    public function testSendFallsBackToEmailLocaleSettingThenDefaultLocale(): void
    {
        $this->settings->method('getOrDefault')->willReturnMap([
            [ApplicationParameterEnum::SiteName, 'Aurora'],
        ]);
        $this->settings->method('get')->willReturnMap([
            [ApplicationParameterEnum::EmailLocale->value, null, ''],
            [ApplicationParameterEnum::DefaultLocale->value, ApplicationParameterEnum::DefaultLocale->getDefaultValue(), 'fr'],
        ]);
        $this->translator->method('trans')->willReturn('s');
        $this->twig->method('render')->willReturn('b');

        $this->localeSwitcher->expects(self::once())
            ->method('runWithLocale')
            ->with('fr', self::callback('is_callable'))
            ->willReturnCallback(static fn (string $_, callable $cb) => $cb());

        $this->service->send('to@example.com', 'k', '@t.html.twig');
    }

    public function testSendInterpolatesSubjectParameters(): void
    {
        $this->settings->method('getOrDefault')->willReturn('Aurora');
        $this->settings->method('get')->willReturn(null);
        $this->translator->expects(self::once())
            ->method('trans')
            ->with('photo.subject_invite', ['{title}' => 'My Gallery'])
            ->willReturn('My Gallery - Photos ready');
        $this->twig->method('render')->willReturn('b');

        $this->mailer->expects(self::once())
            ->method('send')
            ->with(self::callback(function (Email $email): bool {
                self::assertSame('[Aurora] My Gallery - Photos ready', $email->getSubject());

                return true;
            }));

        $this->service->send(
            'to@example.com',
            'photo.subject_invite',
            '@t.html.twig',
            subjectParams: ['{title}' => 'My Gallery'],
        );
    }

    public function testSendToAdminNoOpsWhenAdminEmailUnset(): void
    {
        $this->settings->method('get')->willReturn(null);
        $this->mailer->expects(self::never())->method('send');

        $this->service->sendToAdmin('k', '@t.html.twig');
    }

    public function testSendCcFiltersOutDuplicateOfRecipient(): void
    {
        $this->settings->method('getOrDefault')->willReturn('Aurora');
        $this->settings->method('get')->willReturn(null);
        $this->translator->method('trans')->willReturn('s');
        $this->twig->method('render')->willReturn('b');

        $this->mailer->expects(self::once())
            ->method('send')
            ->with(self::callback(function (Email $email): bool {
                self::assertCount(0, $email->getCc(), 'CC matching recipient should be filtered');

                return true;
            }));

        $this->service->send('to@example.com', 'k', '@t.html.twig', cc: ['to@example.com']);
    }

    public function testSendCcKeepsValidAndDropsEmpty(): void
    {
        $this->settings->method('getOrDefault')->willReturn('Aurora');
        $this->settings->method('get')->willReturn(null);
        $this->translator->method('trans')->willReturn('s');
        $this->twig->method('render')->willReturn('b');

        $this->mailer->expects(self::once())
            ->method('send')
            ->with(self::callback(function (Email $email): bool {
                $addresses = array_map(static fn ($a) => $a->getAddress(), $email->getCc());
                // Empty CC entries are filtered, valid distinct ones remain.
                self::assertSame(['cc1@example.com', 'cc2@example.com'], $addresses);

                return true;
            }));

        $this->service->send(
            'to@example.com',
            'k',
            '@t.html.twig',
            cc: ['cc1@example.com', '', 'cc2@example.com'],
        );
    }

    public function testSiteNameReturnsTheSettingValue(): void
    {
        $this->settings->expects(self::once())
            ->method('getOrDefault')
            ->with(ApplicationParameterEnum::SiteName)
            ->willReturn('Aurora Test');

        self::assertSame('Aurora Test', $this->service->siteName());
    }

    public function testAdminEmailReturnsNullWhenSettingIsUnset(): void
    {
        $this->settings->expects(self::atLeastOnce())
            ->method('get')
            ->with(ApplicationParameterEnum::AdminEmail->value)
            ->willReturn(null);

        self::assertNull($this->service->adminEmail());
    }

    public function testAdminEmailTreatsEmptyStringAsUnset(): void
    {
        // Empty-string settings come from the UI when an admin clears the
        // field - caller must see this as "not configured", not "empty addr".
        $this->settings->expects(self::atLeastOnce())
            ->method('get')
            ->with(ApplicationParameterEnum::AdminEmail->value)
            ->willReturn('');

        self::assertNull($this->service->adminEmail());
    }

    public function testAdminEmailReturnsTheConfiguredAddress(): void
    {
        $this->settings->expects(self::atLeastOnce())
            ->method('get')
            ->with(ApplicationParameterEnum::AdminEmail->value)
            ->willReturn('admin@aurora.test');

        self::assertSame('admin@aurora.test', $this->service->adminEmail());
    }

    public function testSendToAdminDelegatesWhenAdminEmailIsSet(): void
    {
        // adminEmail set + siteName resolves + everything happens as a
        // normal send() to that address.
        $this->settings->method('get')->willReturnMap([
            [ApplicationParameterEnum::AdminEmail->value, null, 'admin@aurora.test'],
            [ApplicationParameterEnum::EmailLocale->value, null, null],
            [ApplicationParameterEnum::DefaultLocale->value, ApplicationParameterEnum::DefaultLocale->getDefaultValue(), null],
        ]);
        $this->settings->method('getOrDefault')->willReturn('Aurora');
        $this->translator->method('trans')->willReturn('Daily report');
        $this->twig->method('render')->willReturn('<p>report</p>');

        $this->mailer->expects(self::once())
            ->method('send')
            ->with(self::callback(function (Email $email): bool {
                self::assertSame('admin@aurora.test', $email->getTo()[0]->getAddress());
                self::assertSame('[Aurora] Daily report', $email->getSubject());

                return true;
            }));

        $this->service->sendToAdmin('admin.subject', '@admin.html.twig');
    }
}
