<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\Contract\Service;

use Aurora\Module\Studio\Contract\Entity\ContractInterface;
use Aurora\Module\Studio\Contract\Signature\Entity\ContractSignatureInterface;
use Aurora\Module\Studio\Contract\Signature\Enum\ContractSignatureRoleEnum;
use Aurora\Module\Studio\Contract\Signature\Repository\ContractSignatureRepository;

/**
 * The sealed document with its « fait à …, le … » filled in.
 *
 * The city and the date of signature are the two blanks the signer fills, so
 * they stay as tokens in the sealed HTML (see {@see ContractInterface}). They
 * were meant to be written in from the signature when the document is shown,
 * and nothing did it: the signed PDF printed `{{contract.signature_city}}`,
 * braces included, and so did the signing page and the back office.
 *
 * Filled here, at display, and never stored: the sealed HTML is what the hash
 * covers, and it must stay the document the client was asked to sign.
 *
 * The customer's declaration fills them. They sign first, the sentence is
 * theirs on the page they sign, and the provider's own city and date are
 * printed under their signature in the proof block. Until the customer has
 * signed, a dotted blank stands where the words will go, as on paper.
 */
final readonly class ContractSignedDocument
{
    public const string BLANK = '……………';

    public function __construct(
        private ContractDocumentRenderer $renderer,
        private ContractVariableResolver $resolver,
        private ContractSignatureRepository $contractSignatureRepository,
    ) {}

    /** The stored document, filled from whatever has been signed so far. */
    public function html(ContractInterface $contract): string
    {
        return $this->fill($contract, $contract->getRenderedHtml() ?? '', $this->contractSignatureRepository->findForContract($contract));
    }

    /**
     * @param list<ContractSignatureInterface> $signatures both parties, one, or none
     */
    public function fill(ContractInterface $contract, string $html, array $signatures): string
    {
        $customer = null;

        foreach ($signatures as $signature) {
            if (ContractSignatureRoleEnum::Customer === $signature->getRole()) {
                $customer = $signature;
            }
        }

        $values = $this->resolver->signatureValues(
            $customer?->getDeclaredPlace(),
            $customer?->getDeclaredDate(),
            $contract->getLocale(),
        );

        foreach ($values as $token => $value) {
            if ('' === $value) {
                $values[$token] = self::BLANK;
            }
        }

        return $this->renderer->substitute($html, $values);
    }
}
