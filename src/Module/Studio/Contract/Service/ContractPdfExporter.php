<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\Contract\Service;

use Aurora\Module\Studio\Contract\Entity\ContractInterface;
use Aurora\Module\Studio\Contract\Manager\ContractManagerInterface;
use Aurora\Module\Studio\Contract\Signature\Repository\ContractSignatureRepository;
use Symfony\Component\String\Slugger\SluggerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

use function sprintf;

/**
 * A contract on paper, at any point in its life.
 *
 * The module already had a PDF, and only one: the file minted at the
 * countersignature, written once and never rebuilt. That covers the archive and
 * covers nothing else - a draft sent to a client for comment, a sealed contract
 * printed before it goes out, a signed-by-one-party document an accountant asks
 * for. This is the export for those, and the whole design is about not letting
 * it be mistaken for the other one.
 *
 * Three shapes, decided here rather than at the call site:
 *
 * - **A draft** has no text of its own yet. What it will say is assembled by
 *   the manager's preview, slots and all, because a second assembly in another
 *   class is a second answer to the only question that matters.
 * - **A sealed contract** has its snapshot, and it is printed verbatim. Never
 *   re-rendered: re-rendering would show what today's templates produce, not
 *   what was sealed.
 * - **A concluded contract** is not handled here at all. Its bytes exist, they
 *   are hashed, and the caller reads them - `renderProvisional()` refuses it so
 *   that no route can accidentally hand out a look-alike.
 */
final readonly class ContractPdfExporter
{
    public function __construct(
        private ContractPdfGenerator $generator,
        private ContractManagerInterface $contracts,
        private ContractSignatureRepository $signatures,
        private SluggerInterface $slugger,
        private TranslatorInterface $translator,
    ) {}

    /** Whether the bytes have to be rendered rather than read from storage. */
    public function isProvisional(ContractInterface $contract): bool
    {
        return !$contract->hasPdf();
    }

    /** The bytes of a contract that has no stored file, built now and kept by nobody. */
    public function render(ContractInterface $contract): string
    {
        return $this->generator->renderProvisional(
            $contract,
            $this->documentHtml($contract),
            $this->signatures->findForContract($contract),
        );
    }

    /**
     * What the browser saves it as.
     *
     * The reference when there is one, because that is the number people quote
     * at each other; the customer otherwise, because a draft has no number and
     * `contrat.pdf` in a downloads folder names nothing. A provisional export
     * carries a suffix in the contract's own language: the name is the last
     * thing a reader sees before the file is filed somewhere, and it has to
     * say the same thing the first page does.
     */
    public function filename(ContractInterface $contract): string
    {
        $base = $contract->getReference()
            ?? $this->slugger->slug($contract->getCustomer()->getLegalName())->lower()->toString();

        if ('' === $base) {
            $base = (string) $contract->getId();
        }

        if (!$this->isProvisional($contract)) {
            return sprintf('%s.pdf', $base);
        }

        return sprintf(
            '%s-%s.pdf',
            $base,
            $this->translator->trans('studio.pdf.provisional.filename_suffix', [], null, $contract->getLocale()),
        );
    }

    /**
     * The text to print.
     *
     * The snapshot as soon as there is one. A draft has none, so the preview
     * builds what it would say today - which is exactly the caveat the banner
     * on the first page carries.
     */
    private function documentHtml(ContractInterface $contract): string
    {
        $sealed = $contract->getRenderedHtml();

        if (null !== $sealed && '' !== $sealed) {
            return $sealed;
        }

        return (string) $this->contracts->preview($contract)['html'];
    }
}
