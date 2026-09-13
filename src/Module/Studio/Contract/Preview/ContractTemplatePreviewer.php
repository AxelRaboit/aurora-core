<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\Contract\Preview;

use Aurora\Module\Studio\Contract\Entity\ContractTemplateVersionInterface;
use Aurora\Module\Studio\Contract\Entity\ContractTemplateVersionTranslationInterface;
use Aurora\Module\Studio\Contract\Exception\UnrenderableBlockException;
use Aurora\Module\Studio\Contract\Service\ContractCustomFieldScanner;
use Aurora\Module\Studio\Contract\Service\ContractDocumentRenderer;
use Aurora\Module\Studio\Contract\Service\ContractVariableCatalogue;
use Aurora\Module\Studio\Contract\Service\ContractVariableResolver;

/**
 * A wording seen the way the client will see it.
 *
 * Writing a contract means writing `{{customer.legal_name}}` and trusting it
 * will read as a sentence once filled. It usually does, and the times it does
 * not are the expensive ones: a token misspelt renders as itself in the signed
 * document, a clause reads fine with a short company name and wraps badly with
 * a long one, and a variable resolving to nothing leaves a hole nobody saw
 * until a client read it.
 *
 * None of that is visible in the editor, and the only other way to find out
 * was to build a real contract and freeze it - which mints a reference, writes
 * an audit line, and leaves a document to delete afterwards. Somebody checking
 * a comma should not have to do that.
 *
 * **The values are the ones the editor already shows.** The panel beside the
 * wording gives an example for every token; this uses exactly those, so the
 * preview and the panel cannot disagree about what a SIRET looks like.
 *
 * **Except the provider's, which are real.** Half of a contract is the
 * business itself, and those values are known today: they come from the
 * settings, not from whoever signs. Showing the real ones makes the preview
 * truer and, when a setting is empty, shows the blank it will actually leave.
 *
 * **Custom fields stay visibly blank.** `{{contract.custom.plateformes}}` is
 * answered per contract, so there is no example to give. A bracketed label
 * says "a slot, filled at creation" rather than pretending a value; leaving
 * the raw token would be honest too, but would make the one thing an author
 * most wants to read - the shape of the sentence - the one thing they cannot.
 */
final readonly class ContractTemplatePreviewer
{
    public function __construct(
        private ContractDocumentRenderer $renderer,
        private ContractVariableCatalogue $catalogue,
        private ContractVariableResolver $resolver,
        private ContractCustomFieldScanner $customFields,
    ) {}

    /**
     * The document for one language, as HTML, or null when the version is not
     * written in it.
     *
     * @throws UnrenderableBlockException when a block cannot be drawn, which is
     *                                    the refusal a freeze would meet - and
     *                                    meeting it here is the point
     */
    public function preview(ContractTemplateVersionInterface $version, string $locale): ?string
    {
        $translation = $version->getTranslation($locale);

        if (!$translation instanceof ContractTemplateVersionTranslationInterface) {
            return null;
        }

        $values = $this->values($version);
        $content = $translation->getContent();
        $blocks = is_array($content['blocks'] ?? null) ? $content['blocks'] : [];

        // Assembled exactly as ContractManager assembles a part at freeze: the
        // title as an h1 over the rendered blocks. A preview composing its own
        // frame would be previewing a different document.
        return sprintf(
            '<section><h1>%s</h1>%s</section>',
            $this->renderer->substitute($translation->getTitle(), $values),
            $this->renderer->render($blocks, $values),
        );
    }

    /**
     * The languages this version is written in.
     *
     * @return list<string>
     */
    public function locales(ContractTemplateVersionInterface $version): array
    {
        $locales = [];

        foreach ($version->getTranslations() as $translation) {
            $locales[] = $translation->getLocale();
        }

        return $locales;
    }

    /** @return array<string, string> */
    private function values(ContractTemplateVersionInterface $version): array
    {
        return [
            ...$this->catalogue->examples(),
            ...$this->resolver->providerValues(),
            ...$this->customFieldPlaceholders($version),
        ];
    }

    /**
     * `contract.custom.plateformes_retenues` becomes `[plateformes retenues]`.
     *
     * @return array<string, string>
     */
    private function customFieldPlaceholders(ContractTemplateVersionInterface $version): array
    {
        $values = [];

        foreach ($this->customFields->keysOf($version) as $key) {
            $values[ContractCustomFieldScanner::PREFIX.$key] = sprintf('[%s]', str_replace('_', ' ', $key));
        }

        return $values;
    }
}
