<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\Contract\Signature\Manager;

use Aurora\Core\Mail\Service\MailService;
use Aurora\Core\Validation\Exception\FieldException;
use Aurora\Module\Studio\Contract\Access\Entity\ContractAccessLinkInterface;
use Aurora\Module\Studio\Contract\Signature\Entity\AbstractContractSignatureChallenge;
use Aurora\Module\Studio\Contract\Signature\Entity\ContractSignatureChallenge;
use Aurora\Module\Studio\Contract\Signature\Entity\ContractSignatureChallengeInterface;
use Aurora\Module\Studio\Contract\Signature\Repository\ContractSignatureChallengeRepository;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Contracts\Translation\TranslatorInterface;

use function hash_equals;
use function preg_match;

/**
 * The code that proves the signer reads the contract's mailbox.
 *
 * Everything security-relevant about a six-digit code is here, because six
 * digits is only a million possibilities and none of that million matters if
 * the limits are missing. Four of them:
 *
 * 1. **Ten minutes.** Set by the entity when the code is minted, never by a
 *    caller.
 * 2. **Five wrong answers**, counted and persisted whether or not the caller
 *    goes on to do anything with the result.
 * 3. **Single use.** A verified code is dead, even inside its window.
 * 4. **A ceiling on how many can be asked for.** Per link rather than per IP,
 *    because the IP is the attacker's to change and the link is the thing being
 *    attacked - and because nobody should be able to mail a customer a hundred
 *    codes.
 *
 * Only the newest code is ever checked. Accepting any code still inside its
 * window would multiply the guesses the attempt limit exists to cap.
 */
#[AsAlias(ContractSignatureChallengeManagerInterface::class)]
class ContractSignatureChallengeManager implements ContractSignatureChallengeManagerInterface
{
    /**
     * How many codes one address may ask for in an hour.
     *
     * Ten: enough for a mistyped address, a spam folder and a second device,
     * and far short of a way to flood somebody's mailbox.
     */
    public const int MAX_ISSUED_PER_HOUR = 10;

    public function __construct(
        protected readonly EntityManagerInterface $entityManager,
        protected readonly ContractSignatureChallengeRepository $challengeRepository,
        protected readonly MailService $mailService,
        protected readonly TranslatorInterface $translator,
    ) {}

    public function issue(ContractAccessLinkInterface $link): ContractSignatureChallengeInterface
    {
        // Only while there is something to sign: the form is hidden once the
        // customer has answered, and a code mailed for a concluded, refused or
        // cancelled contract is mail about nothing.
        if (!$link->getContract()->getStatus()->isWaitingForCustomer()) {
            throw new FieldException('code', $this->translator->trans('suite.studio.contracts.errors.contract_closed', [], null, $link->getContract()->getLocale()));
        }

        $issued = $this->challengeRepository->countIssuedSince($link, new DateTimeImmutable('-1 hour'));

        if ($issued >= self::MAX_ISSUED_PER_HOUR) {
            throw new FieldException('code', $this->translator->trans('studio.public.sign.errors.too_many_codes', [], null, $link->getContract()->getLocale()));
        }

        $challenge = $this->createChallenge();
        $code = $challenge->mint();

        $challenge
            ->setLink($link)
            // The contract's address, never one the request supplied. A code
            // mailed to an address the signer typed would prove that they can
            // read their own mailbox, which is not the question.
            ->setSentTo($link->getRecipientEmail());

        $this->entityManager->persist($challenge);
        $this->entityManager->flush();

        $contract = $link->getContract();

        $this->mailService->send(
            to: $challenge->getSentTo(),
            subjectKey: 'studio.email.signature_code.subject',
            template: '@Studio/email/signature_code.html.twig',
            context: [
                'code' => $code,
                'contract' => $contract,
                'minutes' => AbstractContractSignatureChallenge::LIFETIME_MINUTES,
            ],
            locale: $contract->getLocale(),
        );

        return $challenge;
    }

    /**
     * Checks a typed code and consumes it.
     *
     * Returns the moment it was verified, which is what the signature stores.
     * Every refusal is a `FieldException` on `code`, and every refusal says the
     * same thing: ask for a new one. Telling somebody whether their code was
     * wrong, expired or already used tells them which of those to work around.
     */
    public function verify(ContractAccessLinkInterface $link, string $code): DateTimeImmutable
    {
        $challenge = $this->challengeRepository->findLatestFor($link);
        $now = new DateTimeImmutable();

        if (!$challenge instanceof ContractSignatureChallengeInterface || !$challenge->isUsable($now)) {
            throw $this->refuse($link);
        }

        // Six digits, checked before the comparison: a payload carrying
        // something else is not a wrong code, it is not a code.
        if (1 !== preg_match('/^\d{6}$/', $code)) {
            $challenge->recordFailedAttempt();
            $this->entityManager->flush();

            throw $this->refuse($link);
        }

        if (!hash_equals($challenge->getHashedCode(), AbstractContractSignatureChallenge::hashCode($code))) {
            // Counted and persisted before the refusal is raised. A limit that
            // only applies when the caller finishes politely is not a limit.
            $challenge->recordFailedAttempt();
            $this->entityManager->flush();

            throw $this->refuse($link);
        }

        $challenge->consume($now);
        $this->entityManager->flush();

        return $now;
    }

    /**
     * Where the code about to be checked was sent.
     *
     * Read before `verify()`, which consumes it: it is the latest code of the
     * link, the one the signer is typing.
     */
    public function recipientOf(ContractAccessLinkInterface $link): ?string
    {
        return $this->challengeRepository->findLatestFor($link)?->getSentTo();
    }

    protected function createChallenge(): ContractSignatureChallengeInterface
    {
        return new ContractSignatureChallenge();
    }

    private function refuse(ContractAccessLinkInterface $link): FieldException
    {
        return new FieldException('code', $this->translator->trans(
            'studio.public.sign.errors.code_invalid',
            [],
            null,
            $link->getContract()->getLocale(),
        ));
    }
}
