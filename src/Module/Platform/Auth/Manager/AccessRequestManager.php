<?php

declare(strict_types=1);

namespace Aurora\Module\Platform\Auth\Manager;

use Aurora\Core\Sequence\SequenceGenerator;
use Aurora\Core\Sequence\SequencePrefixEnum;
use Aurora\Module\Configuration\Setting\Enum\ApplicationParameterEnum;
use Aurora\Module\Configuration\Setting\Repository\SettingRepository;
use Aurora\Module\Platform\Auth\Entity\AccessRequest;
use Aurora\Module\Platform\Auth\Entity\AccessRequestInterface;
use Aurora\Module\Platform\Auth\Enum\AccessRequestStatusEnum;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment as TwigEnvironment;

#[AsAlias(AccessRequestManagerInterface::class)]
class AccessRequestManager implements AccessRequestManagerInterface
{
    public function __construct(
        protected readonly EntityManagerInterface $entityManager,
        protected readonly MailerInterface $mailer,
        protected readonly TwigEnvironment $twig,
        protected readonly UrlGeneratorInterface $urlGenerator,
        protected readonly SettingRepository $settingRepository,
        protected readonly TranslatorInterface $translator,
        protected readonly string $adminEmail,
        protected readonly string $mailerFrom,
        protected readonly SequenceGenerator $sequenceGenerator,
    ) {}

    public function create(string $email, ?string $name, ?string $message): AccessRequestInterface
    {
        $prefix = $this->settingRepository->get(ApplicationParameterEnum::CoreAccessRequestPrefix->value, SequencePrefixEnum::AccessRequest->value) ?? SequencePrefixEnum::AccessRequest->value;

        $request = $this->createAccessRequest($email, new DateTimeImmutable('+48 hours'));
        $request->setRequesterName($name ?: null);
        $request->setMessage($message ?: null);
        $request->setReference($this->sequenceGenerator->next($prefix));

        $this->entityManager->persist($request);
        $this->entityManager->flush();

        $this->sendAdminNotification($request);

        return $request;
    }

    public function approve(AccessRequest $request, ?string $generatedPassword = null): void
    {
        $request->setStatus(AccessRequestStatusEnum::Approved);
        $this->entityManager->flush();

        $this->sendRequesterApproval($request, $generatedPassword);
    }

    public function reject(AccessRequest $request): void
    {
        $request->setStatus(AccessRequestStatusEnum::Rejected);
        $this->entityManager->flush();

        $this->sendRequesterRejection($request);
    }

    private function siteName(): string
    {
        return $this->settingRepository->getOrDefault(ApplicationParameterEnum::SiteName);
    }

    private function sendAdminNotification(AccessRequestInterface $request): void
    {
        $siteName = $this->siteName();
        $adminUrl = $this->urlGenerator->generate('dev_access_requests', [], UrlGeneratorInterface::ABSOLUTE_URL);

        $body = $this->twig->render('@Shared/email/access_request_admin.html.twig', [
            'request' => $request,
            'siteName' => $siteName,
            'adminUrl' => $adminUrl,
        ]);

        $subject = $this->translator->trans('shared.mail.access_request_admin.heading');

        $this->mailer->send(new Email()
            ->from($this->mailerFrom)
            ->to($this->adminEmail)
            ->subject(sprintf('[%s] %s', $siteName, $subject))
            ->html($body));
    }

    private function sendRequesterApproval(AccessRequestInterface $request, ?string $generatedPassword = null): void
    {
        $siteName = $this->siteName();
        $loginUrl = $this->urlGenerator->generate('suite_platform_login', [], UrlGeneratorInterface::ABSOLUTE_URL);

        $body = $this->twig->render('@Shared/email/access_request_approved.html.twig', [
            'request' => $request,
            'loginUrl' => $loginUrl,
            'generatedPassword' => $generatedPassword,
            'siteName' => $siteName,
        ]);

        $subject = $this->translator->trans('shared.mail.access_request_approved.heading');

        $this->mailer->send(new Email()
            ->from($this->mailerFrom)
            ->to($request->getRequesterEmail())
            ->subject(sprintf('[%s] %s', $siteName, $subject))
            ->html($body));
    }

    private function sendRequesterRejection(AccessRequestInterface $request): void
    {
        $siteName = $this->siteName();

        $body = $this->twig->render('@Shared/email/access_request_rejected.html.twig', [
            'request' => $request,
            'siteName' => $siteName,
        ]);

        $subject = $this->translator->trans('shared.mail.access_request_rejected.heading');

        $this->mailer->send(new Email()
            ->from($this->mailerFrom)
            ->to($request->getRequesterEmail())
            ->subject(sprintf('[%s] %s', $siteName, $subject))
            ->html($body));
    }

    protected function createAccessRequest(string $email, DateTimeImmutable $expiresAt): AccessRequestInterface
    {
        return new AccessRequest($email, $expiresAt);
    }
}
