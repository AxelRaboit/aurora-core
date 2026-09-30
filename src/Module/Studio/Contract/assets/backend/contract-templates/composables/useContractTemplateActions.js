import { useI18n } from "vue-i18n";
import {
    Archive,
    ArchiveRestore,
    Copy,
    Eye,
    FilePlus2,
    FileX2,
    Pencil,
    Tag,
    Trash2,
} from "lucide-vue-next";
import { usePrivileges } from "@/shared/composables/usePrivileges.js";

/**
 * What may be done to one trame, decided once and shown twice.
 *
 * The list hands these to `AppRowActions` and the cards render them as
 * buttons. Two presentations, one definition: written out in each template
 * instead, a permission added on one screen and forgotten on the other is a
 * button somebody has and shouldn't.
 *
 * The conditions are rules, not decoration - a trame with a draft open does
 * not offer to open a second one, an archived trame offers to come back rather
 * than to leave again - and they are the reason this is not inline in the
 * template.
 *
 * The order is the order they are meant to be read: look at it, work on it,
 * copy it, rename it, put it away, and destroy it last.
 *
 * @param {Function} editorPath `(templateId, versionId) => string`, from the
 *   list composable. Reading a trame is a navigation rather than a command, so
 *   its action carries an `href` and stays openable in a new tab.
 */
export function useContractTemplateActions(editorPath) {
    const { t } = useI18n();
    const { can } = usePrivileges();

    /**
     * @param {object} template the row
     * @param {object} handlers one function per action, named by its key
     * @returns {Array<object>} `{ key, title, description, color, icon, href?, onSelect? }`
     */
    return function actionsFor(template, handlers) {
        const actions = [];
        const prefix = "backend.studio.contract_templates";

        // First, because reading is what somebody opening this screen without
        // the intent to change anything is here for - and because it was the
        // one thing the list could not do. The editor already refuses to write
        // a published version and says so, so "consulter" and "ouvrir la
        // version en vigueur" are the same screen, named for what it is.
        //
        // Offered only when there is a version in force: a trame that has never
        // been published has nothing settled to read, and its draft is reached
        // by the badge beside it.
        if (
            template.publishedVersionId &&
            can("studio.contract_templates.view")
        ) {
            actions.push({
                key: "view",
                icon: Eye,
                title: t(`${prefix}.view`),
                description: t(`${prefix}.row_actions.view_description`),
                href: editorPath?.(template.id, template.publishedVersionId),
            });
        }

        // The gesture most people come for, first and in colour: change the
        // text. With a draft open it goes back into it; otherwise it opens the
        // next version and goes in. The version in force does not change
        // until the draft is published.
        if (!template.isArchived && can("studio.contract_templates.edit")) {
            actions.unshift(
                template.draftId
                    ? {
                          key: "editText",
                          color: "accent",
                          icon: Pencil,
                          title: t(`${prefix}.continue_draft`, {
                              number: template.draftVersion,
                          }),
                          description: t(
                              `${prefix}.row_actions.continue_draft_description`,
                          ),
                          href: editorPath?.(template.id, template.draftId),
                      }
                    : {
                          key: "editText",
                          color: "accent",
                          icon: FilePlus2,
                          title: t(`${prefix}.edit_text`),
                          description: t(
                              `${prefix}.row_actions.edit_text_description`,
                          ),
                          onSelect: () => handlers.openDraft(template),
                      },
            );
        }

        // The way back out of a draft opened by mistake, under the same right
        // as opening one: an account allowed to open drafts but not to delete
        // templates used to be stuck with the first one.
        if (template.draftId && can("studio.contract_templates.edit")) {
            actions.push({
                key: "discard",
                color: "amber",
                icon: FileX2,
                title: t(`${prefix}.discard`),
                description: t(`${prefix}.row_actions.discard_description`),
                onSelect: () => handlers.discard(template),
            });
        }

        if (can("studio.contract_templates.create")) {
            actions.push({
                key: "duplicate",
                color: "sky",
                icon: Copy,
                title: t(`${prefix}.duplicate`),
                description: t(`${prefix}.row_actions.duplicate_description`),
                onSelect: () => handlers.duplicate(template),
            });
        }

        if (can("studio.contract_templates.edit")) {
            actions.push({
                key: "rename",
                icon: Tag,
                // It renames and files the trame; it never opened the text,
                // which « Modifier » promised.
                title: t(`${prefix}.rename_title`),
                description: t(`${prefix}.row_actions.rename_description`),
                onSelect: () => handlers.rename(template),
            });
        }

        if (!template.isArchived && can("studio.contract_templates.edit")) {
            actions.push({
                key: "archive",
                color: "amber",
                icon: Archive,
                title: t(`${prefix}.archive`),
                description: t(`${prefix}.row_actions.archive_description`),
                onSelect: () => handlers.archive(template),
            });
        }

        if (template.isArchived && can("studio.contract_templates.edit")) {
            actions.push({
                key: "restore",
                color: "emerald",
                icon: ArchiveRestore,
                title: t(`${prefix}.restore`),
                description: t(`${prefix}.row_actions.restore_description`),
                onSelect: () => handlers.restore(template),
            });
        }

        // Only when no contract starts from it: the server refuses otherwise,
        // and archiving is the way to retire a trame that has served.
        if (
            can("studio.contract_templates.delete") &&
            0 === (template.contractsCount ?? 0)
        ) {
            actions.push({
                key: "delete",
                color: "rose",
                icon: Trash2,
                title: t("shared.common.delete"),
                description: t(`${prefix}.row_actions.delete_description`),
                onSelect: () => handlers.remove(template),
            });
        }

        return actions;
    };
}
