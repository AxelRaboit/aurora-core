<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\Contract\Enum;

/**
 * Which trade a template belongs to.
 *
 * Orthogonal to {@see ContractTemplateKindEnum}, and the two answer different
 * questions. The kind says what a document **is** - a body or an annex - and
 * decides how a contract is assembled. The category says what it is **for**,
 * and decides which documents belong in the same conversation.
 *
 * The distinction earns its place the day a business sells more than one
 * thing. A library of twenty templates covering three trades is unreadable
 * without it: the picker offers the photography annex while somebody is
 * drafting a web project, and the list is a single alphabetical run in which
 * nothing is near what it belongs with.
 *
 * **Why a template may have none.** A category is a classification somebody
 * applies, and "nobody has said yet" is a real state that deserves to be
 * visible rather than hidden behind a default. A template silently born into a
 * trade it was never assigned to is worse than one plainly marked as
 * unclassified: the first is a wrong answer, the second is a question.
 *
 * Cases are deliberately few and broad. A category that splits per offer would
 * be doing the annex's job.
 */
enum ContractTemplateCategoryEnum: string
{
    case CommunityManagement = 'community_management';
    case Photography = 'photography';
    case Development = 'development';

    public function getLabel(): string
    {
        return match ($this) {
            self::CommunityManagement => 'backend.studio.contract_templates.category.community_management',
            self::Photography => 'backend.studio.contract_templates.category.photography',
            self::Development => 'backend.studio.contract_templates.category.development',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $category): string => $category->value, self::cases());
    }
}
