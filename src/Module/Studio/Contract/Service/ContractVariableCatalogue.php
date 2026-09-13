<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\Contract\Service;

/**
 * The placeholders a template may carry, where each one gets its value, and
 * WHEN it is resolved.
 *
 * Declared in code rather than stored per version, because the set of things
 * that can be filled in is a property of the application, not of a document.
 * A version that writes `{{customer.siret}}` needs no schema of its own: the
 * token either names something this catalogue knows how to resolve, or it does
 * not, and that answer is the same for every template.
 *
 * Three groups, and each reads somewhere different. `customer.*` reads the
 * Customer attached to the contract. `contract.*` reads the contract itself:
 * the reference, the amount, the date it takes effect, the city and date of
 * signature. `provider.*` reads the application's settings.
 *
 * The provider group was deliberately absent at first, because the paper
 * trames carried that identity as ordinary wording. It earned its place the
 * moment there were two bodies and three annexes: the same block, typed five
 * times, is five places to forget when a bank or an address changes. Settings
 * rather than a Customer row, because there is exactly one provider.
 *
 * The two moments matter as much as the tokens. Most are known when a contract
 * is frozen and are substituted then, so the document a signer reads has no
 * blanks in it. Two are not: the city and the date of signature are stated by
 * the person signing, which is exactly how the paper contracts work - "fait à
 * …, le …" is a blank the signer fills. Those stay as tokens inside the frozen
 * snapshot and are only rendered into the final PDF from the signature.
 *
 * Baking them at freeze time would have meant either inventing a date before
 * anybody signed, or refusing to freeze until somebody did. The first is a lie
 * in a legal document; the second breaks the whole flow, since freezing is what
 * has to happen before the link goes out.
 */
final readonly class ContractVariableCatalogue
{
    /** Substituted when the contract is frozen, before the link goes out. */
    public const string AT_FREEZE = 'freeze';

    /** Left as a token in the snapshot, filled from the signature. */
    public const string AT_SIGNATURE = 'signature';

    /**
     * Every token, grouped by where it comes from.
     *
     * The example is not decoration: a token list without one leaves the
     * writer guessing whether a date arrives as 08/09/2026 or 2026-09-08,
     * which is exactly the kind of thing that gets discovered in a signed PDF.
     *
     * @return list<array{
     *     group: string,
     *     labelKey: string,
     *     variables: list<array{token: string, labelKey: string, example: string, resolvedAt: string}>
     * }>
     */
    public function groups(): array
    {
        return [
            [
                'group' => 'customer',
                'labelKey' => 'backend.studio.contract_templates.variables.customer',
                'variables' => [
                    $this->variable('customer.legal_name', 'Boulangerie Durand'),
                    $this->variable('customer.legal_form', 'SARL'),
                    $this->variable('customer.share_capital', '10 000 €'),
                    $this->variable('customer.registered_office', '12 rue des Lilas, 69003 Lyon'),
                    $this->variable('customer.siret', '732 829 320 00074'),
                    $this->variable('customer.trade_register', 'Lyon B 732 829 320'),
                    $this->variable('customer.vat_number', 'FR12732829320'),
                    $this->variable('customer.activity_sector', 'boulangerie artisanale'),
                    $this->variable('customer.representative_full_name', 'Camille Durand'),
                    $this->variable('customer.representative_role', 'Gérante'),
                    $this->variable('customer.contractual_email', 'contact@durand.fr'),
                    $this->variable('customer.phone', '06 12 34 56 78'),
                ],
            ],
            [
                'group' => 'provider',
                'labelKey' => 'backend.studio.contract_templates.variables.provider',
                'variables' => [
                    $this->variable('provider.name', 'Léa Marchand - Entrepreneure individuelle'),
                    $this->variable('provider.representative', 'Léa MARCHAND'),
                    $this->variable('provider.address', '7 rue de la Fontaine, 38000 Grenoble'),
                    $this->variable('provider.siret', '904 512 336 00010'),
                    $this->variable('provider.ape_code', '7021Z'),
                    $this->variable('provider.vat_mention', 'TVA non applicable, art. 293 B du CGI'),
                    $this->variable('provider.email', 'contact@exemple.fr'),
                    $this->variable('provider.phone', '06 12 34 56 78'),
                    $this->variable('provider.bank_holder', 'Léa Marchand EI'),
                    $this->variable('provider.bank_iban', 'FR76 1234 5678 9012 3456 7890 123'),
                    $this->variable('provider.bank_bic', 'ABCDFRPP'),
                    $this->variable('provider.bank_name', 'Banque'),
                ],
            ],
            [
                'group' => 'contract',
                'labelKey' => 'backend.studio.contract_templates.variables.contract',
                'variables' => [
                    $this->variable('contract.reference', 'CM-2026-0001'),
                    $this->variable('contract.amount', '850 €'),
                    $this->variable('contract.effective_date', '01/10/2026'),
                    // Only an amendment can fill these, and the freeze refuses
                    // a wording that asks for them on a contract that amends
                    // nothing: an avenant sealed as a standalone document
                    // would name no parent and say so with a blank.
                    $this->variable('contract.amends_reference', 'CM-2026-0001'),
                    $this->variable('contract.amends_effective_date', '01/10/2026'),
                    $this->variable('contract.amends_rank', '1'),
                    $this->variable('contract.signature_city', 'Lyon', self::AT_SIGNATURE),
                    $this->variable('contract.signature_date', '08/09/2026', self::AT_SIGNATURE),
                ],
            ],
        ];
    }

    /**
     * Every token as a flat list, for checking a document against the
     * catalogue.
     *
     * @return list<string>
     */
    public function tokens(): array
    {
        $tokens = [];

        foreach ($this->groups() as $group) {
            foreach ($group['variables'] as $variable) {
                $tokens[] = $variable['token'];
            }
        }

        return $tokens;
    }

    public function knows(string $token): bool
    {
        return in_array($token, $this->tokens(), true);
    }

    /**
     * Every token with the example the editor already shows beside it.
     *
     * The same strings, deliberately. A preview built on a second set of
     * made-up values would drift from the panel an author reads while writing,
     * and the two would disagree about what `{{customer.siret}}` looks like.
     *
     * @return array<string, string> token => example
     */
    public function examples(): array
    {
        $examples = [];

        foreach ($this->groups() as $group) {
            foreach ($group['variables'] as $variable) {
                $examples[$variable['token']] = $variable['example'];
            }
        }

        return $examples;
    }

    /** @return list<string> */
    public function signatureTokens(): array
    {
        $tokens = [];

        foreach ($this->groups() as $group) {
            foreach ($group['variables'] as $variable) {
                if (self::AT_SIGNATURE === $variable['resolvedAt']) {
                    $tokens[] = $variable['token'];
                }
            }
        }

        return $tokens;
    }

    /** @return array{token: string, labelKey: string, example: string, resolvedAt: string} */
    private function variable(string $token, string $example, string $resolvedAt = self::AT_FREEZE): array
    {
        return [
            'token' => $token,
            'resolvedAt' => $resolvedAt,
            // The label key mirrors the token, so adding a variable is one
            // entry here and one line in each catalogue rather than a mapping
            // table to keep in agreement.
            'labelKey' => 'backend.studio.contract_templates.variables.'.str_replace('.', '_', $token),
            'example' => $example,
        ];
    }
}
