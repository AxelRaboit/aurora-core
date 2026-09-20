<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\Customer\Dto;

use Aurora\Core\Support\Str;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

use function is_array;
use function mb_trim;
use function preg_replace;

#[AsAlias(CustomerInformationInputFactoryInterface::class)]
class CustomerInformationInputFactory implements CustomerInformationInputFactoryInterface
{
    /** @param array<string, mixed> $data */
    public function fromArray(array $data): CustomerInformationInputInterface
    {
        return new CustomerInformationInput(
            legalName: Str::trimFromArray($data, 'legalName'),
            siret: $this->digitsOrNull($data, 'siret'),
            siren: $this->digitsOrNull($data, 'siren'),
            phone: Str::trimOrNullFromArray($data, 'phone'),
            landline: Str::trimOrNullFromArray($data, 'landline'),
            email: Str::emailOrNullFromArray($data, 'email'),
            postalAddress: Str::trimOrNullFromArray($data, 'postalAddress'),
            links: $this->links($data),
            notes: Str::trimOrNullFromArray($data, 'notes'),
        );
    }

    /**
     * Les lignes de liens, débarrassées de celles que personne n'a remplies.
     *
     * **Une ligne entièrement vide n'est pas une erreur, c'est une ligne qu'on
     * a ouverte et laissée.** Le bouton « ajouter » en pose une vide, et
     * refuser d'enregistrer parce qu'elle est vide obligerait à la retirer
     * avant de sauver - un geste que personne ne comprend. Une ligne à moitié
     * remplie, elle, est une vraie erreur et remonte comme telle.
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
     * Les chiffres d'un numéro, quelle que soit la façon dont il a été tapé.
     *
     * La même règle que la saisie des clients : un SIRET se lit sur un
     * document par groupes et se tape comme ça. La forme stockée est toujours
     * la suite de chiffres, sans quoi le même numéro existerait sous deux
     * orthographes.
     *
     * Ce qui reste est rendu tel quel même s'il n'a pas la bonne longueur :
     * c'est la contrainte qui signale un mauvais numéro, et avaler l'erreur
     * ici validerait un `null` et n'enregistrerait rien.
     *
     * @param array<string, mixed> $data
     */
    private function digitsOrNull(array $data, string $key): ?string
    {
        $raw = Str::trimOrNullFromArray($data, $key);

        if (null === $raw) {
            return null;
        }

        $digits = (string) preg_replace('/\D/', '', $raw);

        return '' === $digits ? null : $digits;
    }
}
