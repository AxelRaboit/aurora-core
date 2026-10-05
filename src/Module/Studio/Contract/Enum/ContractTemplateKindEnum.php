<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\Contract\Enum;

/**
 * What a template is: the body of a contract, or an annex to one.
 *
 * The distinction is not cosmetic, it is the rule the paper contracts already
 * follow. The legal body is common to every offer and is never duplicated per
 * offer; everything that varies from one to the next - platforms, volumes,
 * price - lives in an annex. A clause to amend is then touched once, in the
 * body, instead of drifting across three copies within months.
 *
 * A contract is therefore assembled from one body and, when the offer needs
 * one, one annex.
 */
enum ContractTemplateKindEnum: string
{
    case Body = 'body';
    case Annex = 'annex';

    public function getLabel(): string
    {
        return match ($this) {
            self::Body => 'suite.studio.contract_templates.kind.body',
            self::Annex => 'suite.studio.contract_templates.kind.annex',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $kind): string => $kind->value, self::cases());
    }
}
