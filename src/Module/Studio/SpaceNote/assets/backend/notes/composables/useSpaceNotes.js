import { computed, ref } from "vue";
import { useI18n } from "vue-i18n";
import { toast } from "vue-sonner";
import { buildPath } from "@/shared/utils/http/buildPath.js";
import { useRequest } from "@/shared/composables/http/backend/useRequest.js";
import { useListViewMode } from "@/shared/composables/list/useListViewMode.js";

/**
 * Le mur de notes d'un espace, et les quatre écritures dessus.
 *
 * **Deux vues, une seule liste.** Le mur et la liste lisent les mêmes notes
 * dans le même ordre - épinglées d'abord, puis les plus récemment touchées -
 * parce que ce sont deux lectures de la même chose : une note troisième dans la
 * liste doit être troisième sur le mur. Le choix vit dans l'adresse, comme
 * celui de la vue Fichiers, sous son propre paramètre pour qu'ouvrir l'une ne
 * décide pas de l'autre.
 *
 * **Chaque écriture répond par le mur entier.** Épingler réordonne tout, et une
 * page qui rafistolerait sa copie s'écarterait du serveur en trois gestes. Un
 * mur de notes est petit : c'est un carnet, pas une archive.
 *
 * @param {Array} initial
 * @param {object} paths  createPath, updatePath, deletePath, pinPath
 * @param {(data: object) => void} offerOrphaned
 */
export function useSpaceNotes(initial, paths, offerOrphaned) {
    const { t } = useI18n();
    const { request } = useRequest();

    const notes = ref(initial ?? []);
    const loading = ref(false);

    const { viewMode, storedViewMode, setViewMode, container } =
        useListViewMode(["grid", "list"], "grid", "notes");

    const isEmpty = computed(() => 0 === notes.value.length);

    /** La note ouverte dans l'éditeur, ou null. */
    const editing = ref(null);
    const showForm = ref(false);
    const form = ref(emptyNote());
    const errors = ref({});

    function emptyNote() {
        return { title: "", body: [], colourSlot: "", pinned: false };
    }

    function openCreate() {
        editing.value = null;
        form.value = emptyNote();
        errors.value = {};
        showForm.value = true;
    }

    function openEdit(note) {
        editing.value = note;
        form.value = {
            title: note.title ?? "",
            // Copié, pas partagé : l'éditeur écrit dans ce tableau, et annuler
            // doit laisser la note telle qu'elle était.
            body: JSON.parse(JSON.stringify(note.body ?? [])),
            colourSlot: note.colourSlot ?? "",
            pinned: !!note.pinned,
        };
        errors.value = {};
        showForm.value = true;
    }

    function apply(data) {
        if (Array.isArray(data?.notes)) notes.value = data.notes;
    }

    async function submit() {
        if (loading.value) return;

        const title = (form.value.title ?? "").trim();

        if ("" === title) {
            errors.value = {
                title: t("backend.studio.space_notes.errors.title_required"),
            };

            return;
        }

        loading.value = true;
        try {
            const body = {
                ...form.value,
                title,
                colourSlot:
                    "" === form.value.colourSlot ? null : form.value.colourSlot,
            };

            const data = await request(
                editing.value
                    ? buildPath(paths.updatePath, { id: editing.value.id })
                    : paths.createPath,
                body,
            );

            if (!data?.success) {
                errors.value = data?.errors ?? {};

                return;
            }

            apply(data);
            showForm.value = false;
            toast.success(
                t(
                    editing.value
                        ? "backend.studio.space_notes.updated"
                        : "backend.studio.space_notes.created",
                ),
            );
        } finally {
            loading.value = false;
        }
    }

    async function togglePin(note) {
        if (loading.value) return;

        loading.value = true;
        try {
            const data = await request(
                buildPath(paths.pinPath, { id: note.id }),
            );

            if (data?.success) apply(data);
        } finally {
            loading.value = false;
        }
    }

    const pendingDelete = ref(null);

    function confirmDelete(note) {
        pendingDelete.value = note;
    }

    async function doDelete() {
        if (!pendingDelete.value || loading.value) return;

        loading.value = true;
        try {
            const data = await request(
                buildPath(paths.deletePath, { id: pendingDelete.value.id }),
            );

            if (!data?.success) return;

            apply(data);
            pendingDelete.value = null;
            toast.success(t("backend.studio.space_notes.deleted"));

            // Les images que la note portait et que plus personne n'utilise.
            // Proposées, jamais jetées d'office : elles peuvent servir ailleurs,
            // et c'est une décision qui appartient à quelqu'un. Le contrat est
            // celui de la vue Fichiers, donc le même composable le lit.
            offerOrphaned(data);
        } finally {
            loading.value = false;
        }
    }

    return {
        notes,
        isEmpty,
        loading,
        viewMode,
        storedViewMode,
        setViewMode,
        container,
        showForm,
        editing,
        form,
        errors,
        openCreate,
        openEdit,
        submit,
        togglePin,
        pendingDelete,
        confirmDelete,
        doDelete,
    };
}
