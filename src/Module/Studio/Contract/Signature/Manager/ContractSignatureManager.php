<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\Contract\Signature\Manager;

use Aurora\Core\Mail\Service\MailService;
use Aurora\Core\Validation\Exception\FieldException;
use Aurora\Module\Dev\Audit\Service\AuditLogger;
use Aurora\Module\Platform\User\Entity\CoreUserInterface;
use Aurora\Module\Studio\Contract\Access\Entity\ContractAccessLinkInterface;
use Aurora\Module\Studio\Contract\Entity\ContractInterface;
use Aurora\Module\Studio\Contract\Enum\ContractStatusEnum;
use Aurora\Module\Studio\Contract\Service\ContractPdfGenerator;
use Aurora\Module\Studio\Contract\Service\ContractSeal;
use Aurora\Module\Studio\Contract\Signature\Dto\ContractSignatureInputInterface;
use Aurora\Module\Studio\Contract\Signature\Entity\ContractSignature;
use Aurora\Module\Studio\Contract\Signature\Entity\ContractSignatureInterface;
use Aurora\Module\Studio\Contract\Signature\Enum\ContractSignatureRoleEnum;
use Aurora\Module\Studio\Contract\Signature\Repository\ContractSignatureRepository;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\Translation\TranslatorInterface;
use Throwable;

use function mb_substr;

/**
 * Recording a signature, and the order that has to hold while doing it.
 *
 * The customer signs first and the provider countersigns; the countersignature
 * is what concludes the contract. That ordering was a deliberate reversal of
 * the obvious one, and it buys two things: the moment of formation stays with
 * the provider, and no signature can ever be invalidated by an edit, because
 * the document was already sealed before either party saw it.
 *
 * Everything this class writes is evidence, so the sequence matters as much as
 * the result:
 *
 * 1. **The payload is validated before the code is consumed.** A typo in a name
 *    must not burn a credential and send somebody back to their mailbox.
 * 2. **The code is verified and consumed in one step**, by the challenge
 *    manager, which is where the limits live.
 * 3. **The signature is written with the hash as it stands**, copied rather than
 *    referenced, so a later divergence is detectable.
 * 4. **The status moves last.** A contract that says "signed" with no signature
 *    row would be the worst of the possible half-states.
 */
#[AsAlias(ContractSignatureManagerInterface::class)]
class ContractSignatureManager implements ContractSignatureManagerInterface
{
    public function __construct(
        protected readonly EntityManagerInterface $entityManager,
        protected readonly AuditLogger $auditLogger,
        protected readonly ContractSignatureRepository $signatures,
        protected readonly ContractSignatureChallengeManagerInterface $challenges,
        protected readonly MailService $mail,
        protected readonly ContractPdfGenerator $pdf,
        protected readonly TranslatorInterface $translator,
        protected readonly ContractSeal $seal,
    ) {}

    public function signAsCustomer(
        ContractAccessLinkInterface $link,
        ContractSignatureInputInterface $input,
        Request $request,
    ): ContractSignatureInterface {
        $contract = $link->getContract();

        $this->assertSignable($contract, ContractSignatureRoleEnum::Customer);

        // Consumed here, after the payload has already been validated by the
        // caller. Everything past this line has to succeed or the signer has
        // to ask for a new code, so nothing that can fail on form input is
        // left to happen afterwards.
        $sentTo = $this->challenges->recipientOf($link);
        $verifiedAt = $this->challenges->verify($link, $input->getCode());

        $signature = $this->build($contract, $input, ContractSignatureRoleEnum::Customer, $request);
        $signature
            ->setChallengeVerifiedAt($verifiedAt)
            ->setChallengeSentTo($sentTo)
            ->setLinkSelector($link->getSelector());

        $this->entityManager->persist($signature);

        $contract->setStatus(ContractStatusEnum::SignedByCustomer);
        $this->entityManager->flush();

        $this->auditLogger->log('studio', 'contract.signed_by_customer', 'Contract', $contract->getId(), [
            ...$this->auditPayload($signature),
            'selector' => $link->getSelector(),
        ]);

        $this->notifyProvider($signature);

        return $signature;
    }

    public function countersign(
        ContractInterface $contract,
        ContractSignatureInputInterface $input,
        CoreUserInterface $user,
        Request $request,
    ): ContractSignatureInterface {
        $this->assertSignable($contract, ContractSignatureRoleEnum::Provider);

        // The order, enforced rather than assumed: the countersignature is what
        // concludes, so there has to be something to conclude.
        $customerSignature = $this->signatures->findOneForRole($contract, ContractSignatureRoleEnum::Customer);

        if (!$customerSignature instanceof ContractSignatureInterface) {
            throw new FieldException('status', $this->translator->trans('backend.studio.contracts.errors.customer_has_not_signed'));
        }

        $signature = $this->build($contract, $input, ContractSignatureRoleEnum::Provider, $request);
        // A session rather than a mailbox: the provider is authenticated, which
        // is a stronger link to a person than a code, and asking them for a
        // code mailed to themselves would add a step and no evidence.
        $signature->setUser($user);

        $this->entityManager->persist($signature);

        $contract->setStatus(ContractStatusEnum::Countersigned);

        // The PDF is written here and nowhere else: the countersignature is
        // what concludes, so it is the first and only moment the document is
        // complete. Generating it earlier would produce a file missing a
        // signature; generating it later would mean regenerating it.
        //
        // Written before anything is saved, and saved in one go with it. The
        // status used to be stored first: a PDF that failed then left a
        // contract concluded with no file, and no way to make one, since a
        // second countersignature is refused.
        $pdf = $this->pdf->generate($contract, [$customerSignature, $signature]);
        $contract->attachPdf($pdf['path'], $pdf['hash'], new DateTimeImmutable());

        try {
            $this->entityManager->flush();
        } catch (Throwable $throwable) {
            // Nothing was concluded, so the file concludes nothing either, and
            // left behind it would refuse the next attempt as a collision.
            $this->pdf->remove($pdf['path']);

            throw $throwable;
        }

        $this->auditLogger->log('studio', 'contract.countersigned', 'Contract', $contract->getId(), [
            ...$this->auditPayload($signature),
            'pdfPath' => $pdf['path'],
            'pdfHash' => $pdf['hash'],
        ]);

        $this->notifyBothParties($signature);

        return $signature;
    }

    /**
     * Whether this party can still sign this contract.
     *
     * Three refusals, and each is a different mistake: a document that was
     * never sealed, a contract that has run out of time or been withdrawn, and
     * a party that has already signed.
     */
    protected function assertSignable(ContractInterface $contract, ContractSignatureRoleEnum $role): void
    {
        // The customer reads these on the signing page, in the contract's
        // language; they used to come in the back office's.
        $locale = ContractSignatureRoleEnum::Customer === $role ? $contract->getLocale() : null;

        if (!$contract->isFrozen()) {
            throw new FieldException('status', $this->translator->trans('backend.studio.contracts.errors.seal_before_signing', [], null, $locale));
        }

        if (ContractStatusEnum::Countersigned === $contract->getStatus()) {
            throw new FieldException('status', $this->translator->trans('backend.studio.contracts.errors.already_concluded', [], null, $locale));
        }

        // Checked at the moment it matters: a signature binds whoever gives it
        // to the stored text, so that text has to be the one that was sealed.
        // It was checked nowhere, and an altered document would have been
        // signed, then printed.
        if (!$this->seal->verify($contract)) {
            throw new FieldException('status', $this->translator->trans('backend.studio.contracts.errors.seal_broken', [], null, $locale));
        }

        foreach ([ContractStatusEnum::Refused, ContractStatusEnum::Expired, ContractStatusEnum::Revoked, ContractStatusEnum::Cancelled] as $closed) {
            if ($closed === $contract->getStatus()) {
                throw new FieldException('status', $this->translator->trans('backend.studio.contracts.errors.contract_closed', [], null, $locale));
            }
        }

        // An amendment binds nothing once the contract it modifies has ended.
        if ($contract->getAmends()?->isTerminationEffective() ?? false) {
            throw new FieldException('status', $this->translator->trans('backend.studio.contracts.errors.amends_terminated', [], null, $locale));
        }

        if ($this->signatures->findOneForRole($contract, $role) instanceof ContractSignatureInterface) {
            // The unique index says the same thing, as a driver exception. This
            // says it as a sentence.
            throw new FieldException('status', $this->translator->trans('backend.studio.contracts.errors.role_already_signed', [], null, $locale));
        }
    }

    /**
     * The row, with the declared half and the observed half filled in.
     *
     * The hash is read off the contract and copied. Referencing it would mean
     * this row says whatever the contract says today, which is exactly the
     * property a signature must not have.
     */
    protected function build(
        ContractInterface $contract,
        ContractSignatureInputInterface $input,
        ContractSignatureRoleEnum $role,
        Request $request,
    ): ContractSignatureInterface {
        $declaredDate = DateTimeImmutable::createFromFormat('!Y-m-d', $input->getDate());

        if (false === $declaredDate) {
            throw new FieldException('date', $this->translator->trans('studio.public.sign.errors.date_invalid', [], null, $contract->getLocale()));
        }

        $signature = $this->createSignature();

        return $signature
            ->setContract($contract)
            ->setRole($role)
            ->setDeclaredFirstName($input->getFirstName())
            ->setDeclaredLastName($input->getLastName())
            ->setDeclaredEmail($input->getEmail())
            ->setDeclaredPlace($input->getPlace())
            ->setDeclaredDate($declaredDate)
            ->setSignedAt(new DateTimeImmutable())
            ->setIpAddress($request->getClientIp())
            // Truncated to what the column holds rather than refused: a browser
            // sending a two-kilobyte user agent is odd, not a reason to stop
            // somebody signing, and the first 500 characters identify it.
            ->setUserAgent($this->userAgent($request))
            ->setSignedContentHash((string) $contract->getContentHash())
            ->setSignatureImage($input->getSignatureImage());
    }

    protected function userAgent(Request $request): ?string
    {
        $agent = $request->headers->get('User-Agent');

        return null === $agent ? null : mb_substr($agent, 0, 500);
    }

    protected function createSignature(): ContractSignatureInterface
    {
        return new ContractSignature();
    }

    /**
     * Tells the provider somebody signed.
     *
     * To the administrator address rather than to a person: whoever watches the
     * back office needs to know there is a contract waiting to be concluded,
     * and that is a mailbox rather than a name.
     */
    protected function notifyProvider(ContractSignatureInterface $signature): void
    {
        $contract = $signature->getContract();

        $this->mail->sendToAdmin(
            subjectKey: 'studio.email.customer_signed.subject',
            template: '@Studio/email/customer_signed.html.twig',
            context: [
                'contract' => $contract,
                'signature' => $signature,
            ],
            subjectParams: ['{reference}' => (string) $contract->getReference()],
        );
    }

    /**
     * Tells both parties it is concluded.
     *
     * The customer's copy goes to the address the contract names, not to the
     * one they typed: the contractual address is the channel the document
     * itself says counts.
     */
    protected function notifyBothParties(ContractSignatureInterface $signature): void
    {
        $contract = $signature->getContract();

        // The signed copy, attached. This is the mail the customer keeps, and a
        // link they would have to be logged in to follow is not a copy.
        //
        // Sent from inside a borrowed local path rather than from a path held
        // afterwards: the mail service attaches by filename, and on a remote
        // backend the file only exists for the length of the callback. On the
        // server's own disk nothing is copied and this costs nothing.
        if ($contract->hasPdf()) {
            $this->pdf->withLocalCopy($contract, function (string $path) use ($contract): void {
                $this->sendConcludedMail($contract, [[
                    'path' => $path,
                    'name' => sprintf('%s.pdf', (string) $contract->getReference()),
                ]]);
            });
        } else {
            $this->sendConcludedMail($contract, []);
        }

        $this->mail->sendToAdmin(
            subjectKey: 'studio.email.concluded.subject',
            template: '@Studio/email/concluded.html.twig',
            // The provider's copy links to the back office; the customer's
            // has no back office to link to.
            context: ['contract' => $contract, 'forProvider' => true],
            subjectParams: ['{reference}' => (string) $contract->getReference()],
        );
    }

    /**
     * @param list<array{path: string, name?: string}> $attachments
     */
    protected function sendConcludedMail(ContractInterface $contract, array $attachments): void
    {
        $this->mail->send(
            // Une signature s'enregistre meme si le mail ne part pas :
            // `MailService` renvoie sans rien faire sur une adresse vide, et
            // refuser d'enregistrer un engagement parce qu'on ne peut pas en
            // accuser reception serait perdre le fait pour l'annonce.
            to: $contract->getCustomer()->getContractualEmail() ?? '',
            subjectKey: 'studio.email.concluded.subject',
            template: '@Studio/email/concluded.html.twig',
            context: ['contract' => $contract],
            locale: $contract->getLocale(),
            subjectParams: ['{reference}' => (string) $contract->getReference()],
            attachments: $attachments,
        );
    }

    /** @return array<string, mixed> */
    protected function auditPayload(ContractSignatureInterface $signature): array
    {
        return [
            'reference' => $signature->getContract()->getReference(),
            'role' => $signature->getRole()->value,
            'declaredBy' => $signature->getDeclaredFullName(),
            'declaredEmail' => $signature->getDeclaredEmail(),
            'declaredPlace' => $signature->getDeclaredPlace(),
            'declaredDate' => $signature->getDeclaredDate()->format('Y-m-d'),
            // The evidence, in the trail as well as in the row: an audit line
            // that says a contract was signed and cannot say against which
            // document is not worth much.
            'signedContentHash' => $signature->getSignedContentHash(),
            'ip' => $signature->getIpAddress(),
        ];
    }
}
