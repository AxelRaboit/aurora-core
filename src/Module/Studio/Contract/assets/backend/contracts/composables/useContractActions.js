import { useI18n } from "vue-i18n";
import {
    Ban,
    Eye,
    FileDown,
    FileSignature,
    Lock,
    Mail,
    Pencil,
    Trash2,
} from "lucide-vue-next";
import { usePrivileges } from "@/shared/composables/usePrivileges.js";

/**
 * What may be done to one contract, and it depends on which half it is in.
 *
 * A draft is edited, sealed, or thrown away; a sealed contract is sent, has its
 * link revoked, and is read. The two are different sets rather than one set
 * with conditions, which is why there are two builders and not a flag.
 *
 * Sealing is where the two meet and it is the one that cannot be undone, so it
 * carries the careful colour, and reading the document is a navigation - it
 * gets `href` and stays openable in a new tab.
 *
 * The PDF export is the one action both sets share, because it is the one
 * question that does not depend on which half a contract is in: what does this
 * say, on paper. It is a plain link for the same reason as the document - the
 * server decides what comes back, and a download is a navigation.
 */
export function useContractActions() {
    const { t } = useI18n();
    const { can } = usePrivileges();

    const prefix = "backend.studio.contracts";

    /**
     * The same entry on both sides, built once.
     *
     * Offered to anybody who may see a contract, like the preview: reading a
     * document is not changing it. What comes back differs with the state - the
     * signed file once there is one, a working copy before - and that is the
     * server's call, not this list's.
     *
     * @param {object} contract any contract, in any state
     * @param {Function} exportPath builds the address for one contract
     * @returns {object} the action
     */
    function exportAction(contract, exportPath) {
        return {
            key: "export",
            icon: FileDown,
            title: t(`${prefix}.export_pdf`),
            description: t(`${prefix}.row_actions.export_description`),
            href: exportPath(contract),
        };
    }

    /**
     * @param {object} contract a contract still in preparation
     * @param {object} handlers one function per action, named by its key, plus `exportPath`
     * @returns {Array<object>} `{ key, title, description, color, icon, onSelect }`
     */
    function draftActions(contract, handlers) {
        const actions = [];

        // First, and offered to anybody who may see a contract: reading what a
        // document will say is not editing it, and this is the only way to read
        // one before it is sealed - after which it can no longer be changed.
        actions.push({
            key: "preview",
            icon: Eye,
            title: t(`${prefix}.preview`),
            description: t(`${prefix}.row_actions.preview_description`),
            onSelect: () => handlers.preview(contract),
        });

        actions.push(exportAction(contract, handlers.exportPath));

        if (can("studio.contracts.edit")) {
            actions.push({
                key: "edit",
                icon: Pencil,
                title: t("shared.common.edit"),
                description: t(`${prefix}.row_actions.edit_description`),
                onSelect: () => handlers.edit(contract),
            });

            actions.push({
                key: "freeze",
                color: "amber",
                icon: Lock,
                title: t(`${prefix}.freeze`),
                description: t(`${prefix}.row_actions.freeze_description`),
                onSelect: () => handlers.freeze(contract),
            });
        }

        if (can("studio.contracts.delete")) {
            actions.push({
                key: "delete",
                color: "rose",
                icon: Trash2,
                title: t("shared.common.delete"),
                description: t(`${prefix}.row_actions.delete_description`),
                onSelect: () => handlers.remove(contract),
            });
        }

        return actions;
    }

    /**
     * @param {object} contract a sealed contract
     * @param {object} handlers `send`, `revoke`, `documentPath` and `exportPath`
     * @returns {Array<object>} the same shape, `href` on the navigation
     */
    function sealedActions(contract, handlers) {
        const actions = [];

        actions.push({
            key: "document",
            color: "sky",
            icon: FileSignature,
            title: t(`${prefix}.read_document`),
            description: t(`${prefix}.row_actions.document_description`),
            href: handlers.documentPath(contract),
        });

        actions.push(exportAction(contract, handlers.exportPath));

        if (can("studio.contracts.send")) {
            if (contract.link) {
                actions.push({
                    key: "revoke",
                    color: "amber",
                    icon: Ban,
                    title: t(`${prefix}.revoke_link`),
                    description: t(`${prefix}.row_actions.revoke_description`),
                    onSelect: () => handlers.revoke(contract),
                });
            } else {
                actions.push({
                    key: "send",
                    color: "accent",
                    icon: Mail,
                    title: t(`${prefix}.send`),
                    description: t(`${prefix}.row_actions.send_description`),
                    onSelect: () => handlers.send(contract),
                });
            }
        }

        return actions;
    }

    return { draftActions, sealedActions };
}
