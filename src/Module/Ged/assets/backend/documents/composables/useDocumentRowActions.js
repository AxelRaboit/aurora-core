import { useI18n } from "vue-i18n";
import {
    CloudUpload,
    Download,
    Eye,
    HardDriveDownload,
    Pencil,
    QrCode,
    Trash2,
} from "lucide-vue-next";

/**
 * What one document offers, which depends on whether it has a file at all.
 *
 * The library keeps records with no file behind them - a draft waiting for its
 * upload - and downloading or printing a QR code for one of those would offer
 * an address that resolves to nothing. Three conditions that were three `v-if`
 * repeated in two places, since the row is written once for the cards and once
 * for the table.
 *
 * Download carries `href` and stays a link: it is a navigation, and the browser
 * is what should handle it.
 *
 * Moving a document between storage backends is offered here rather than as a
 * screen of its own: it is a property of one document, like its folder, and
 * belongs where the other per-document verbs are. It is absent entirely until
 * a second backend exists, which keeps the menu honest for the installations
 * that will never have one.
 */
export function useDocumentRowActions({
    can,
    viewDoc,
    openQr,
    openEdit,
    confirmDelete,
    relocate = null,
    relocationAvailable = false,
}) {
    const { t } = useI18n();

    return function actionsFor(doc) {
        const actions = [
            {
                key: "view",
                color: "sky",
                icon: Eye,
                title: t("shared.common.view"),
                description: t(
                    "backend.ged.documents.row_actions.view_description",
                ),
                onSelect: () => viewDoc(doc),
            },
        ];

        if (doc.fileUrl) {
            actions.push({
                key: "download",
                color: "default",
                icon: Download,
                title: t("shared.common.download"),
                description: t(
                    "backend.ged.documents.row_actions.download_description",
                ),
                href: doc.fileUrl,
            });

            actions.push({
                key: "qr",
                color: "default",
                icon: QrCode,
                title: t("shared.common.qr_code"),
                description: t(
                    "backend.ged.documents.row_actions.qr_description",
                ),
                onSelect: () => openQr(doc),
            });
        }

        if (can("ged.documents.edit")) {
            actions.push({
                key: "edit",
                color: "accent",
                icon: Pencil,
                title: t("shared.common.edit"),
                description: t(
                    "backend.ged.documents.row_actions.edit_description",
                ),
                onSelect: () => openEdit(doc),
            });
        }

        // Only when a second backend has actually been configured and reached.
        // Offering a destination that does not exist is an action that can only
        // fail, and the reader has no way to know why.
        if (relocationAvailable && relocate && can("ged.documents.relocate")) {
            const toRemote = doc.storageDisk !== "r2";
            const pending = doc.storageTransferState === "pending";

            actions.push({
                key: "relocate",
                color: "default",
                icon: toRemote ? CloudUpload : HardDriveDownload,
                title: t(
                    toRemote
                        ? "backend.ged.documents.row_actions.relocate_to_remote"
                        : "backend.ged.documents.row_actions.relocate_to_local",
                ),
                description: pending
                    ? t("backend.ged.documents.row_actions.relocate_pending")
                    : t(
                          "backend.ged.documents.row_actions.relocate_description",
                      ),
                disabled: pending,
                onSelect: () => relocate(doc, toRemote ? "r2" : "local"),
            });
        }

        if (can("ged.documents.delete")) {
            actions.push({
                key: "delete",
                color: "rose",
                icon: Trash2,
                title: t("shared.common.delete"),
                description: t(
                    "backend.ged.documents.row_actions.delete_description",
                ),
                onSelect: () => confirmDelete(doc),
            });
        }

        return actions;
    };
}
