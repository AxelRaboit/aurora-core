import { useI18n } from "vue-i18n";
import {
    Archive,
    ArchiveRestore,
    Copy,
    Eye,
    FilePlus2,
    FileX2,
    Pencil,
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

        if (
            !template.draftId &&
            !template.isArchived &&
            can("studio.contract_templates.edit")
        ) {
            actions.push({
                key: "openDraft",
                color: "accent",
                icon: FilePlus2,
                title: t(`${prefix}.open_draft`),
                description: t(`${prefix}.row_actions.open_draft_description`),
                onSelect: () => handlers.openDraft(template),
            });
        }

        // The way back out of a draft opened by mistake. Offered here rather
        // than only inside the editor, and under the delete permission: it
        // destroys a text nobody has published, which is exactly what the
        // editor's own Abandon button does.
        if (template.draftId && can("studio.contract_templates.delete")) {
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
                icon: Pencil,
                title: t("shared.common.edit"),
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

        if (can("studio.contract_templates.delete")) {
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
