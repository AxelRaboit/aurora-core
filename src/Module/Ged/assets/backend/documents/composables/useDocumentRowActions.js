import { useI18n } from "vue-i18n";
import { Download, Eye, Pencil, QrCode, Trash2 } from "lucide-vue-next";

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
 */
export function useDocumentRowActions({
    can,
    viewDoc,
    openQr,
    openEdit,
    confirmDelete,
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
