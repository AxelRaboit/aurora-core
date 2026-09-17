<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\Contract\Service;

use Aurora\Core\Money\Enum\CurrencyEnum;
use Aurora\Module\Configuration\Setting\Enum\ApplicationParameterEnum;
use Aurora\Module\Configuration\Setting\Repository\SettingRepository;
use Aurora\Module\Studio\Contract\Entity\ContractInterface;
use Aurora\Module\Studio\Customer\Entity\CustomerInterface;
use DateTimeImmutable;
use IntlDateFormatter;
use NumberFormatter;

use function preg_match;
use function preg_replace;
use function sprintf;

/**
 * Turns a contract into the values its tokens stand for.
 *
 * Only the freeze-time tokens. The two signature-time ones are left alone on
 * purpose, and this class is where that is enforced rather than remembered:
 * `resolve()` returns no entry for them, so a substitution pass has nothing to
 * put in their place and leaves the token standing.
 *
 * Formatting happens here, once, and that is deliberate. An amount printed
 * into a contract is read by a person in a language, so it goes through ICU
 * with the contract's own locale - and the same amount always prints the same
 * way, which is what a hash of the rendered document requires.
 */
final readonly class ContractVariableResolver
{
    /**
     * The provider tokens and the settings behind them.
     *
     * Written out rather than derived from the key, so the pairing is
     * greppable from both ends: a token in a trame leads to a setting, and a
     * setting leads to the token that prints it.
     *
     * @var array<string, ApplicationParameterEnum>
     */
    private const array PROVIDER = [
        'provider.name' => ApplicationParameterEnum::StudioProviderName,
        'provider.representative' => ApplicationParameterEnum::StudioProviderRepresentative,
        'provider.address' => ApplicationParameterEnum::StudioProviderAddress,
        'provider.siret' => ApplicationParameterEnum::StudioProviderSiret,
        'provider.ape_code' => ApplicationParameterEnum::StudioProviderApeCode,
        'provider.vat_mention' => ApplicationParameterEnum::StudioProviderVatMention,
        'provider.email' => ApplicationParameterEnum::StudioProviderEmail,
        'provider.phone' => ApplicationParameterEnum::StudioProviderPhone,
        'provider.bank_holder' => ApplicationParameterEnum::StudioProviderBankHolder,
        'provider.bank_iban' => ApplicationParameterEnum::StudioProviderBankIban,
        'provider.bank_bic' => ApplicationParameterEnum::StudioProviderBankBic,
        'provider.bank_name' => ApplicationParameterEnum::StudioProviderBankName,
    ];

    public function __construct(
        private ContractVariableCatalogue $catalogue,
        private SettingRepository $settings,
    ) {}

    /**
     * Every token this contract can fill in today, keyed without braces.
     *
     * A field nobody filled in comes back as an empty string rather than being
     * absent: the token is known, its value is simply not there yet, and a
     * document with a visible blank is better than one carrying `{{…}}` into a
     * signature.
     *
     * @return array<string, string>
     */
    public function resolve(ContractInterface $contract): array
    {
        $customer = $contract->getCustomer();
        $locale = $contract->getLocale();

        return [
            'customer.legal_name' => $customer->getLegalName(),
            'customer.legal_form' => $customer->getLegalForm() ?? '',
            'customer.share_capital' => $this->capital($customer, $locale),
            'customer.registered_office' => $customer->getRegisteredOffice() ?? '',
            'customer.siret' => $this->groupedSiret($customer->getSiret()),
            'customer.trade_register' => $customer->getTradeRegister() ?? '',
            'customer.vat_number' => $customer->getVatNumber() ?? '',
            'customer.activity_sector' => $customer->getActivitySector() ?? '',
            'customer.representative_full_name' => $customer->getRepresentativeFullName() ?? '',
            'customer.representative_role' => $customer->getRepresentativeRole() ?? '',
            'customer.contractual_email' => $customer->getContractualEmail() ?? '',
            'customer.phone' => $customer->getPhone() ?? '',
            'contract.reference' => $contract->getReference() ?? '',
            'contract.amount' => $this->amount($contract, $locale),
            'contract.effective_date' => $this->formatDate($contract->getEffectiveDate(), $locale),
            // What an amendment says about the document it changes. Empty on
            // an original, and the freeze refuses a wording that asks for them
            // there rather than sealing the blank.
            'contract.amends_reference' => $contract->getAmendsReference() ?? '',
            'contract.amends_effective_date' => $this->formatDate($contract->getAmends()?->getEffectiveDate(), $locale),
            'contract.amends_rank' => null === $contract->getAmendmentRank() ? '' : (string) $contract->getAmendmentRank(),
            ...$this->providerValues(),
            // The blanks this contract carries, last so a trame cannot shadow
            // a catalogue variable with a custom field of the same name.
            ...$this->custom($contract),
        ];
    }

    /**
     * The provider's own identity, read from the settings.
     *
     * An unset setting is skipped rather than resolved to an empty string, so
     * a trame printing `{{provider.siret}}` against a blank setting is refused
     * at the freeze instead of sealing a document with a hole where the SIRET
     * should be.
     *
     * Public because the freeze checks these before it mints a reference: a
     * contract refused for a missing setting should not have consumed a number
     * from the sequence.
     *
     * @return array<string, string>
     */
    public function providerValues(): array
    {
        $values = [];

        foreach (self::PROVIDER as $token => $parameter) {
            $value = mb_trim($this->settings->get($parameter->value, '') ?? '');

            if ('' !== $value) {
                $values[$token] = $value;
            }
        }

        return $values;
    }

    /**
     * The per-contract blanks, prefixed the way a trame writes them.
     *
     * Only what the contract actually carries: a key the wording asks for and
     * the contract does not have stays unresolved on purpose, so the freeze
     * refuses it by name instead of printing an empty space.
     *
     * @return array<string, string>
     */
    private function custom(ContractInterface $contract): array
    {
        $values = [];

        foreach ($contract->getCustomFields() as $key => $value) {
            if ('' === $value) {
                continue;
            }

            $values[ContractCustomFieldScanner::PREFIX.$key] = $value;
        }

        return $values;
    }

    /**
     * The tokens a document may still carry after a resolve.
     *
     * @return list<string>
     */
    public function deferredTokens(): array
    {
        return $this->catalogue->signatureTokens();
    }

    private function capital(CustomerInterface $customer, string $locale): string
    {
        $cents = $customer->getShareCapitalCents();

        if (null === $cents) {
            return '';
        }

        // The currency exists exactly when the amount does, which the factory
        // guarantees; the fallback is for rows written before that rule.
        $currency = $customer->getShareCapitalCurrency();

        return $this->money($cents, $currency instanceof CurrencyEnum ? $currency->value : 'EUR', $locale);
    }

    private function amount(ContractInterface $contract, string $locale): string
    {
        $cents = $contract->getAmountCents();

        if (null === $cents) {
            return '';
        }

        $currency = $contract->getAmountCurrency();

        return $this->money($cents, $currency instanceof CurrencyEnum ? $currency->value : 'EUR', $locale);
    }

    private function money(int $cents, string $currency, string $locale): string
    {
        $formatter = new NumberFormatter($locale, NumberFormatter::CURRENCY);

        // A round amount prints without decimals, which is how a contract
        // states a monthly fee. The two-decimal form is kept for the amounts
        // that need it rather than imposed on those that do not.
        if (0 === $cents % 100) {
            $formatter->setAttribute(NumberFormatter::FRACTION_DIGITS, 0);
        }

        return (string) $formatter->formatCurrency($cents / 100, $currency);
    }

    private function formatDate(?DateTimeImmutable $date, string $locale): string
    {
        if (!$date instanceof DateTimeImmutable) {
            return '';
        }

        // The short form, because that is what a contract writes: 01/10/2026,
        // not "1 octobre 2026". ICU rather than a hardcoded format, so the
        // Spanish version of the same contract reads as a Spanish date.
        $formatter = new IntlDateFormatter($locale, IntlDateFormatter::SHORT, IntlDateFormatter::NONE);
        $formatter->setPattern($this->datePattern($locale));

        return (string) $formatter->format($date);
    }

    /**
     * A four-digit year, whatever the locale's short form does.
     *
     * ICU's short date gives a two-digit year in several locales, and "le
     * 01/10/26" in a contract is an ambiguity nobody should have to resolve a
     * decade later.
     */
    private function datePattern(string $locale): string
    {
        $formatter = new IntlDateFormatter($locale, IntlDateFormatter::SHORT, IntlDateFormatter::NONE);
        $pattern = (string) $formatter->getPattern();

        if (1 === preg_match('/y{4}/', $pattern)) {
            return $pattern;
        }

        return (string) preg_replace('/y{1,3}/', 'yyyy', $pattern);
    }

    /**
     * A SIRET in the groups it is printed in.
     *
     * Stored as fourteen digits, read as 732 829 320 00074. The contract shows
     * the printed form because that is what somebody checks it against.
     */
    private function groupedSiret(?string $siret): string
    {
        if (null === $siret) {
            return '';
        }

        if (1 !== preg_match('/^(\d{3})(\d{3})(\d{3})(\d{5})$/', $siret, $parts)) {
            return $siret;
        }

        return sprintf('%s %s %s %s', $parts[1], $parts[2], $parts[3], $parts[4]);
    }
}
