<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\Customer\Dto;

use Aurora\Core\Money\Enum\CurrencyEnum;
use Aurora\Core\Support\Str;
use Aurora\Module\Studio\Customer\Enum\CustomerStatusEnum;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

use function is_array;
use function is_numeric;
use function mb_trim;
use function preg_replace;
use function round;
use function str_replace;

#[AsAlias(CustomerInputFactoryInterface::class)]
class CustomerInputFactory implements CustomerInputFactoryInterface
{
    /** @param array<string, mixed> $data */
    public function fromArray(array $data): CustomerInputInterface
    {
        $capital = $this->centsFromArray($data, 'shareCapital');

        return new CustomerInput(
            legalName: Str::trimFromArray($data, 'legalName'),
            legalForm: Str::trimOrNullFromArray($data, 'legalForm'),
            shareCapitalCents: $capital,
            // The currency exists exactly when the amount does: a currency on
            // its own says nothing, and an amount without one cannot be
            // printed into a contract. Defaulting to euro rather than asking
            // matches who signs these, and the column stays open for the rest.
            shareCapitalCurrency: null === $capital
                ? null
                : (CurrencyEnum::tryFrom(Str::trimFromArray($data, 'shareCapitalCurrency')) ?? CurrencyEnum::EUR),
            registeredOffice: Str::trimOrNullFromArray($data, 'registeredOffice'),
            siret: $this->digitsOrNull($data, 'siret'),
            tradeRegister: Str::trimOrNullFromArray($data, 'tradeRegister'),
            vatNumber: Str::trimOrNullFromArray($data, 'vatNumber'),
            activitySector: Str::trimOrNullFromArray($data, 'activitySector'),
            representativeFirstName: Str::trimOrNullFromArray($data, 'representativeFirstName'),
            representativeLastName: Str::trimOrNullFromArray($data, 'representativeLastName'),
            representativeRole: Str::trimOrNullFromArray($data, 'representativeRole'),
            contractualEmail: Str::emailOrNullFromArray($data, 'contractualEmail'),
            phone: Str::trimOrNullFromArray($data, 'phone'),
            // Prospect par defaut : une valeur inconnue ou absente decrit une
            // fiche dont personne n'a encore dit qu'elle s'etait engagee.
            status: CustomerStatusEnum::tryFrom(Str::trimFromArray($data, 'status'))
                ?? CustomerStatusEnum::Prospect,
            siren: $this->digitsOrNull($data, 'siren'),
            landline: Str::trimOrNullFromArray($data, 'landline'),
            links: $this->links($data),
            informationNotes: Str::trimOrNullFromArray($data, 'informationNotes'),
        );
    }

    /**
     * Les lignes de liens, debarrassees de celles que personne n'a remplies.
     *
     * **Une ligne entierement vide n'est pas une erreur, c'est une ligne qu'on
     * a ouverte et laissee.** Le bouton « Ajouter un lien » en pose une vide,
     * et refuser d'enregistrer parce qu'elle est vide obligerait a la retirer
     * avant de sauver. Une ligne a moitie remplie, elle, est une vraie erreur
     * et remonte comme telle, sous `links[2].url`.
     *
     * @param array<string, mixed> $data
     *
     * @return list<CustomerLinkInput>
     */
    private function links(array $data): array
    {
        $raw = $data['links'] ?? [];

        if (!is_array($raw)) {
            return [];
        }

        $links = [];

        foreach ($raw as $row) {
            if (!is_array($row)) {
                continue;
            }

            $label = mb_trim((string) ($row['label'] ?? ''));
            $url = mb_trim((string) ($row['url'] ?? ''));

            if ('' === $label && '' === $url) {
                continue;
            }

            $links[] = new CustomerLinkInput(label: $label, url: $url);
        }

        return $links;
    }

    /**
     * A human amount ("10 000", "1 500,50", "1500.5") as cents.
     *
     * Parsed here rather than in the browser: the field is typed by a person,
     * and the two separators French and English keyboards produce have to mean
     * the same thing. Null when the field is absent or unreadable, which the
     * DTO's own constraint then reports rather than storing a zero that reads
     * as "capital of nothing".
     *
     * @param array<string, mixed> $data
     */
    private function centsFromArray(array $data, string $key): ?int
    {
        $raw = $this->rawOrNull($data, $key);

        if (null === $raw) {
            return null;
        }

        // Thin spaces and non-breaking spaces come from copy-pasted amounts as
        // often as plain ones do, so every kind of space goes, then the comma
        // becomes the decimal point PHP can read.
        $normalized = (string) preg_replace('/\s|\x{00A0}|\x{202F}/u', '', $raw);
        $normalized = str_replace(',', '.', $normalized);

        if (!is_numeric($normalized)) {
            return null;
        }

        return (int) round((float) $normalized * 100);
    }

    /**
     * The digits of a SIRET or a SIREN, however it was typed.
     *
     * A SIRET is read off a document in groups ("904 512 336 00010") and typed
     * that way. Stripping the separators here means the stored form is always
     * the fourteen digits, so a lookup by number finds the row whatever the
     * spacing was.
     *
     * @param array<string, mixed> $data
     */
    private function digitsOrNull(array $data, string $key): ?string
    {
        $raw = $this->rawOrNull($data, $key);

        if (null === $raw) {
            return null;
        }

        $digits = (string) preg_replace('/\D/', '', $raw);

        // The stripped form is handed back even when it is not fourteen digits:
        // the constraint is what reports a bad number, and swallowing it here
        // would validate a null and save nothing.
        return '' === $digits ? null : $digits;
    }

    /**
     * A trimmed value, or null when the key is absent or blank.
     *
     * Not `Str::trimOrNullFromArray`: that one ends in `?: null`, and the
     * string "0" is falsy in PHP, so a share capital of zero came back as
     * "nobody typed one". Zero is a real answer here - an association has no
     * capital - so blankness is tested rather than truthiness.
     *
     * @param array<string, mixed> $data
     */
    private function rawOrNull(array $data, string $key): ?string
    {
        if (!isset($data[$key])) {
            return null;
        }

        $trimmed = mb_trim((string) $data[$key]);

        return '' === $trimmed ? null : $trimmed;
    }
}
