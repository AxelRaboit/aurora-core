import { ref } from "vue";
import { useI18n } from "vue-i18n";
import { toast } from "vue-sonner";
import { buildPath } from "@/shared/utils/http/buildPath.js";
import { useRequest } from "@/shared/composables/http/backend/useRequest.js";
import { openDocumentPicker } from "@/shared/utils/documentPicker.js";

/**
 * Les fichiers de l'espace lui-même, et les trois écritures dessus.
 *
 * **Déposer, choisir, retirer**, exactement comme sur une fiche : c'est le même
 * geste, sur un autre rattachement. Le sélecteur est celui de la médiathèque,
 * pour que la liste qu'on parcourt ici soit celle qu'on connaît de l'écran des
 * documents plutôt qu'une seconde construite pour cette page.
 *
 * Chaque écriture répond par la liste entière : elle est courte, et une page
 * qui rafistolerait sa copie s'écarterait du serveur en trois gestes.
 *
 * @param {Array} initial
 * @param {object} paths  uploadPath, attachPath, removePath
 * @param {(data: object) => void} offerOrphaned
 */
export function useSpaceOwnFiles(initial, paths, offerOrphaned) {
    const { t } = useI18n();
    const { request } = useRequest();

    const files = ref(initial ?? []);
    const loading = ref(false);

    function apply(data) {
        if (Array.isArray(data?.spaceFiles)) files.value = data.spaceFiles;
    }

    /**
     * Dépose un fichier sur l'espace.
     *
     * `rawBody` plutôt que du JSON : les octets montent en multipart, et
     * l'aide de requête laisse le navigateur poser sa propre frontière.
     */
    async function upload(file) {
        if (!file || loading.value) return;

        const form = new FormData();
        form.append("file", file);

        loading.value = true;
        try {
            const data = await request(paths.uploadPath, null, {
                rawBody: form,
            });

            if (!data?.success) return;

            apply(data);
            toast.success(t("backend.studio.space_files.added"));
        } finally {
            loading.value = false;
        }
    }

    /** Rattache un document déjà dans la médiathèque. */
    async function pick() {
        if (loading.value) return;

        const document = await openDocumentPicker();

        if (!document?.id) return;

        loading.value = true;
        try {
            const data = await request(paths.attachPath, {
                documentId: document.id,
            });

            if (!data?.success) {
                if (data?.errors) toast.error(Object.values(data.errors)[0]);

                return;
            }

            apply(data);
            toast.success(t("backend.studio.space_files.added"));
        } finally {
            loading.value = false;
        }
    }

    /** Retire le fichier de l'espace. Le document reste dans la médiathèque. */
    async function remove(file) {
        if (!file || loading.value) return;

        loading.value = true;
        try {
            const data = await request(
                buildPath(paths.removePath, { id: file.id }),
            );

            if (!data?.success) return;

            apply(data);
            toast.success(t("backend.studio.space_files.removed"));

            // Ce que plus rien n'utilise, proposé plutôt que jeté : le même
            // contrat que les pièces jointes d'une fiche et les images d'une
            // note, donc le même composable le lit.
            offerOrphaned(data);
        } finally {
            loading.value = false;
        }
    }

    return { files, loading, upload, pick, remove };
}
