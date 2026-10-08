<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\Contract\Service;

use Aurora\Module\Configuration\Setting\Enum\ApplicationParameterEnum;
use Aurora\Module\Configuration\Setting\Repository\SettingRepository;
use Aurora\Module\Studio\Contract\Entity\ContractInterface;

/**
 * What the signature page has to tell the person it collects data from.
 *
 * The page asks a stranger for a name, an email, a place and a date, records
 * their IP and their user agent, and stores a drawing of their signature. It
 * said none of that. Article 13 asks for it at the moment of collection, which
 * is this page and no other - a notice on a website nobody reaches from here
 * would satisfy nothing.
 *
 * **Everything it states, it reads.** The controller's identity comes from the
 * same settings the document prints, and the retention comes from the same
 * value the deletion guard enforces. A notice that quoted its own numbers
 * would eventually promise a retention nobody applies, which is worse than
 * saying nothing: it would be a written claim contradicted by the code beside
 * it.
 *
 * **It is not part of the document.** The governing-language clause is sealed
 * inside the contract because it is contractual; this is not, so it lives in
 * the page's furniture. Putting it in the sealed HTML would also change every
 * hash ever taken.
 */
final readonly class ContractPrivacyNotice
{
    /**
     * The settings that name the controller, in printing order.
     *
     * @var list<ApplicationParameterEnum>
     */
    private const array IDENTITY = [
        ApplicationParameterEnum::StudioProviderName,
        ApplicationParameterEnum::StudioProviderAddress,
        ApplicationParameterEnum::StudioProviderEmail,
    ];

    public function __construct(
        private SettingRepository $settingRepository,
        private ContractRetentionPolicy $contractRetentionPolicy,
    ) {}

    /**
     * @return array{
     *     controller: list<string>,
     *     email: string,
     *     retentionYears: int,
     *     locale: string,
     * }
     */
    public function forContract(ContractInterface $contract): array
    {
        $identity = [];

        foreach (self::IDENTITY as $parameter) {
            $value = $this->settingRepository->getOrDefault($parameter);

            // An unfilled setting is skipped rather than printed as a blank
            // line. The notice degrades to what is known instead of showing a
            // reader that nobody filled the form in.
            if ('' !== $value) {
                $identity[] = $value;
            }
        }

        return [
            'controller' => $identity,
            'email' => $this->settingRepository->getOrDefault(ApplicationParameterEnum::StudioProviderEmail),
            'retentionYears' => $this->contractRetentionPolicy->years(),
            // The contract's language, not the browser's: this is read by the
            // person the document was addressed to, in the language it was
            // written in.
            'locale' => $contract->getLocale(),
        ];
    }
}
