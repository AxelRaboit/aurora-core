import { ref, computed, onBeforeUnmount, watch } from "vue";
import { useI18n } from "vue-i18n";
import { toast } from "vue-sonner";
import { useAutoSave } from "@/shared/composables/useAutoSave.js";
import { toggleCheckboxInContent } from "./markedExtensions/markedCheckboxes.js";
import { updateImageDimensionInContent } from "./markedExtensions/markedImageDimensions.js";

/**
 * State + actions for the Markdown notes editor.
 *
 * Owns the flat note list, the selected id, the dirty-tracked form, and all
 * server-roundtripping actions. Keeps the SFC focused on presentation.
 *
 * Auto-save : every form change schedules a debounced save (
 * AUTO_SAVE_DEBOUNCE_MS). Switching notes / deleting / unmounting flush
 * any pending save first to avoid losing keystrokes.
 *
 * @param {object} options
 * @param {object} options.api          - useMarkdownNotesApi() instance
 * @param {Array}  options.initialNotes - flat list passed in via props
 */
export function useNotesEditor({ api, initialNotes, extraFields = {} }) {
    const { t } = useI18n();

    // Client-extension points. Each entry of `extraFields` is
    // `{ default: <value> }` - the value seeds an empty form and is
    // compared by reference equality in `isDirty`. Custom keys are
    // spread back into both the create + update payloads so the server
    // (which has the client's overridden DTO + Input factory) can
    // hydrate the entity transparently.
    const extraKeys = Object.keys(extraFields);
    function extraDefaults() {
        return Object.fromEntries(
            extraKeys.map((key) => [key, extraFields[key]?.default ?? null]),
        );
    }
    function pickExtras(source) {
        return Object.fromEntries(
            extraKeys.map((key) => [
                key,
                source?.[key] ?? extraFields[key]?.default ?? null,
            ]),
        );
    }

    const notes = ref([...initialNotes]);
    const selectedId = ref(null);
    /**
     * Ce que l'habillage d'une note ajoute au formulaire.
     *
     * Le bandeau et l'apparence se sauvegardent comme le texte - par la même
     * écriture automatique, sur la même route - parce que ce sont des champs
     * de la note et rien d'autre. Les nommer ici les fait entrer dans
     * l'instantané, donc dans la détection de modification : choisir une
     * image déclenche l'enregistrement, sans bouton.
     */
    const LOOK_DEFAULTS = {
        coverUrl: null,
        coverCreditName: null,
        coverCreditUrl: null,
        coverPosition: 50,
        appearance: "plain",
    };

    function pickLook(source) {
        return Object.fromEntries(
            Object.entries(LOOK_DEFAULTS).map(([key, fallback]) => [
                key,
                source?.[key] ?? fallback,
            ]),
        );
    }

    function blankForm() {
        return {
            title: "",
            content: "",
            tags: [],
            ...LOOK_DEFAULTS,
            ...extraDefaults(),
        };
    }

    const form = ref(blankForm());
    // Snapshot of the last known server state for the selected note -
    // includes content (which the flat `notes` list omits). The isDirty
    // comparison runs against this, not against the flat list entry.
    const loadedSnapshot = ref(null);
    // Quelle note ce formulaire contient réellement. Sans elle, un chargement
    // qui échoue laissait le texte de la note précédente en face d'un
    // identifiant déjà changé, et la sauvegarde automatique écrivait l'une
    // par-dessus l'autre.
    const loadedId = ref(null);
    const saving = ref(false);
    const deleting = ref(false);
    const pendingDelete = ref(null);

    const selectedNote = computed(
        () => notes.value.find((n) => n.id === selectedId.value) ?? null,
    );

    /**
     * Ce que le formulaire montre appartient-il à la note demandée ?
     *
     * Faux entre le clic et l'arrivée de la réponse, et faux aussi quand le
     * chargement a échoué. C'est le même `loadedId` qui garde la sauvegarde
     * automatique : une seule vérité pour « ce formulaire est-il celui de
     * cette note », plutôt qu'un drapeau de chargement à tenir à jour à côté.
     */
    const bodyReady = computed(
        () => null !== loadedId.value && loadedId.value === selectedId.value,
    );

    const isDirty = computed(() => {
        if (!loadedSnapshot.value) return false;
        if (loadedSnapshot.value.title !== form.value.title) return true;
        if (loadedSnapshot.value.content !== form.value.content) return true;
        const a = loadedSnapshot.value.tags;
        const b = form.value.tags ?? [];
        if (a.length !== b.length) return true;
        for (let i = 0; i < a.length; i++) {
            if (a[i] !== b[i]) return true;
        }
        for (const key of extraKeys) {
            if (loadedSnapshot.value[key] !== form.value[key]) return true;
        }

        // L'habillage compte autant que le texte. Sans ces clés, choisir
        // une image ou déplacer le cadrage laissait le formulaire « propre » :
        // la surveillance se déclenchait bien, mais repartait aussitôt
        // faute de différence à écrire, et le réglage disparaissait au
        // rechargement suivant. Signalé par Axel le 23/09.
        for (const key of Object.keys(LOOK_DEFAULTS)) {
            if (loadedSnapshot.value[key] !== form.value[key]) return true;
        }

        return false;
    });

    const {
        saveStatus,
        lastSavedAt,
        schedule: scheduleAutoSave,
        flush: flushPendingSave,
        cancel: cancelAutoSave,
    } = useAutoSave({
        isDirty: () => isDirty.value,
        save: performSave,
        onError: () => toast.error(t("notes.markdown.errors.save_failed")),
    });

    async function refreshList() {
        const { ok, payload } = await api.list();
        if (ok) {
            notes.value = payload.notes;
        }
    }

    async function selectNote(id) {
        // Persist any pending edits on the previous note before navigating.
        await flushPendingSave();

        selectedId.value = id;
        // Le formulaire ne contient plus rien de fiable tant que la note
        // demandée n'est pas arrivée : la marquer non chargée ferme la porte à
        // une sauvegarde qui écrirait l'ancienne note sur la nouvelle.
        loadedId.value = null;
        loadedSnapshot.value = null;
        // Et on le vide pour de bon. Le marquer non chargé suffisait à
        // protéger les données, pas les yeux : le titre, le texte et le
        // bandeau de la note qu'on venait de quitter restaient à l'écran le
        // temps de l'aller-retour, sous l'identité de la nouvelle. On voyait
        // donc, une fraction de seconde, une note qui n'a jamais existé.
        form.value = blankForm();

        // Le titre et l'entête, on les connaît déjà : ils sont dans la liste à
        // plat, qui est ce sur quoi on vient de cliquer. Les poser tout de
        // suite évite deux clignotements - un mauvais titre remplacé par un
        // champ vide, et surtout le bandeau qui disparaissait puis revenait,
        // cent soixante pixels de saut à chaque passage d'une note à bandeau à
        // une autre. Seul le texte attend la réponse.
        const connue = notes.value.find((n) => n.id === id);
        if (connue) {
            form.value.title = connue.title ?? "";
            Object.assign(form.value, pickLook(connue));
        }

        const { ok, reported, payload } = await api.show(id);

        // Une réponse en retard ne doit pas écraser une note choisie depuis.
        // L'écran en demande deux coup sur coup au chargement - la note active
        // du gabarit, puis celle de l'adresse - et quand elles revenaient dans
        // le désordre, c'est la première qui s'affichait : ouvrir le lien d'une
        // note en montrait une autre, au hasard du réseau.
        if (selectedId.value !== id) {
            return;
        }

        if (!ok) {
            // `useRequest` already reports transport and 5xx failures; a second
            // toast here stacked two messages over each other.
            if (!reported) toast.error(t("notes.markdown.errors.load_failed"));

            return;
        }

        const snapshot = {
            title: payload.note.title ?? "",
            content: payload.note.content ?? "",
            tags: [...(payload.note.tags ?? [])],
            ...pickLook(payload.note),
            ...pickExtras(payload.note),
        };
        loadedSnapshot.value = snapshot;
        form.value = { ...snapshot, tags: [...snapshot.tags] };
        loadedId.value = id;
        cancelAutoSave();
    }

    async function createNote(folderId = null) {
        const { ok, reported, payload } = await api.create({
            folderId,
            title: "",
            content: "",
        });
        if (!ok) {
            if (!reported)
                toast.error(t("notes.markdown.errors.create_failed"));
            return;
        }
        await refreshList();
        await selectNote(payload.note.id);
    }

    /**
     * Persist the currently selected note. Drives the actual HTTP call
     * from auto-save. Returns the success boolean so `useAutoSave` can
     * decide between the `saved` and `error` status.
     */
    async function performSave() {
        if (!selectedNote.value) return true;

        // Ne jamais écrire un formulaire qui n'a pas été chargé pour cette
        // note : c'est le seul point où la confusion se transformerait en
        // perte de texte.
        if (loadedId.value !== selectedNote.value.id) return true;

        saving.value = true;
        const noteId = selectedNote.value.id;
        const folderId = selectedNote.value.folderId ?? null;
        const snapshot = {
            title: form.value.title,
            content: form.value.content,
            tags: [...form.value.tags],
            ...pickLook(form.value),
            ...pickExtras(form.value),
        };

        try {
            const { ok } = await api.update(noteId, {
                folderId,
                ...snapshot,
            });
            if (!ok) return false;

            // Update the snapshot so isDirty drops to false - without
            // overwriting `form` (the user may have typed more chars
            // while the request was in flight; those stay dirty and the
            // next debounce will flush them).
            loadedSnapshot.value = snapshot;

            // Keep the flat list (sidebar tree) in sync with the new
            // title / tags. We touch the entry in place rather than
            // refetching the whole list to avoid losing scroll / state.
            const index = notes.value.findIndex((n) => n.id === noteId);
            if (index !== -1) {
                notes.value[index] = {
                    ...notes.value[index],
                    title: snapshot.title,
                    tags: snapshot.tags,
                };
            }
            return true;
        } finally {
            saving.value = false;
        }
    }

    /**
     * Manual-save entry point kept for keyboard-shortcut wiring and the
     * interactive checkbox toggle in preview mode. Defers to
     * `flushPendingSave()` which short-circuits when clean.
     */
    async function saveSelected() {
        await flushPendingSave();
    }

    /**
     * Open the delete confirmation modal for a note. Defaults to the
     * currently selected one; pass a node from the tree to delete any
     * other. The actual server call lives in `confirmDelete()` so the UI
     * can surface a styled modal instead of the native window.confirm.
     */
    function requestDelete(note = null) {
        const target = note ?? selectedNote.value;
        if (!target) return;
        pendingDelete.value = target;
    }

    function cancelDelete() {
        pendingDelete.value = null;
    }

    async function confirmDelete() {
        if (!pendingDelete.value || deleting.value) return;
        const targetId = pendingDelete.value.id;
        deleting.value = true;
        try {
            // Cancel any pending auto-save for the note we're about to
            // delete, otherwise it would 404 mid-flight.
            if (selectedId.value === targetId) {
                cancelAutoSave();
            }

            const { ok, reported } = await api.remove(targetId);
            if (!ok) {
                if (!reported)
                    toast.error(t("notes.markdown.errors.delete_failed"));
                return;
            }
            if (selectedId.value === targetId) {
                selectedId.value = null;
                loadedSnapshot.value = null;
                loadedId.value = null;
                form.value = {
                    title: "",
                    content: "",
                    tags: [],
                    ...extraDefaults(),
                };
            }
            pendingDelete.value = null;
            await refreshList();
        } finally {
            deleting.value = false;
        }
    }

    /**
     * Wiki-link click in the preview pane. If the target title resolves to
     * an existing note, navigate to it. selectNote() flushes any pending
     * save first, so unsaved keystrokes are persisted automatically.
     */
    async function onWikiLinkClick({ noteTitle, matchedId }) {
        if (matchedId === null) {
            toast.info(
                t("notes.markdown.wiki_link_not_found", { title: noteTitle }),
            );
            return;
        }
        if (matchedId === selectedId.value) return;
        await selectNote(matchedId);
    }

    /**
     * Interactive checkbox toggle in the preview pane. Mutates the source
     * markdown then auto-saves so the new state is durable server-side.
     */
    /**
     * Refresh the flat note list AND reload the currently selected note.
     * Used after global side-effects (e.g., a tag-management rename
     * touching multiple notes) so both the sidebar and the editor pane
     * reflect the new server state in one call.
     */
    async function reloadCurrent() {
        await refreshList();
        if (selectedId.value !== null) {
            await selectNote(selectedId.value);
        }
    }

    async function onCheckboxToggle(index) {
        form.value.content = toggleCheckboxInContent(form.value.content, index);
        await saveSelected();
    }

    /**
     * Drag-to-resize handler. Rewrites the matching `![alt|N](src)` in
     * the markdown source and lets the auto-save watcher debounce the
     * persistence - same flow as a normal edit.
     */
    function onImageResize({ src, width }) {
        const next = updateImageDimensionInContent(
            form.value.content,
            src,
            width,
        );
        if (next !== form.value.content) {
            form.value.content = next;
        }
    }

    // ── Lifecycle ──────────────────────────────────────────────────────────
    //
    // Plus d'ouverture automatique de la première note.
    //
    // Elle datait du temps où cette page n'était qu'un éditeur : sans note
    // ouverte il n'y avait rien à montrer, donc on en ouvrait une. Depuis
    // que le carnet a une bibliothèque, cette ligne détournait tout :
    // arriver sur « Tous les documents » ouvrait une note, entrer dans un
    // dossier en ouvrait une aussi, et cliquer sur un dossier dans le menu
    // semblait ne rien faire - la bibliothèque n'était jamais montée, donc
    // le panneau parlait à une page absente et se rabattait sur une
    // navigation qui rouvrait une note.
    //
    // C'est le serveur qui décide : une adresse de note ouvre cette note
    // (cf. `useMarkdownNotesPage`), les autres montrent la bibliothèque.

    function beforeUnloadHandler(event) {
        event.preventDefault();
        event.returnValue = "";
    }

    // Trigger auto-save on any user-driven form change. The watch fires
    // when selectNote() loads a fresh form too - isDirty is false then,
    // so no save is scheduled.
    watch(
        form,
        () => {
            if (!isDirty.value || saving.value) return;
            scheduleAutoSave();
        },
        { deep: true },
    );

    watch(isDirty, (dirty) => {
        // Belt-and-braces: even with auto-save, network failures or a
        // hard tab-close mid-debounce could lose changes. Keep the
        // beforeunload guard while anything is unsaved or in-flight.
        if (
            dirty ||
            saveStatus.value === "saving" ||
            saveStatus.value === "pending"
        ) {
            window.addEventListener("beforeunload", beforeUnloadHandler);
        } else {
            window.removeEventListener("beforeunload", beforeUnloadHandler);
        }
    });

    onBeforeUnmount(() => {
        window.removeEventListener("beforeunload", beforeUnloadHandler);
    });

    return {
        // state
        notes,
        selectedId,
        selectedNote,
        form,
        bodyReady,
        isDirty,
        saving,
        deleting,
        pendingDelete,
        saveStatus,
        lastSavedAt,
        // actions
        refreshList,
        reloadCurrent,
        selectNote,
        createNote,
        saveSelected,
        flushPendingSave,
        requestDelete,
        cancelDelete,
        confirmDelete,
        onWikiLinkClick,
        onCheckboxToggle,
        onImageResize,
    };
}
