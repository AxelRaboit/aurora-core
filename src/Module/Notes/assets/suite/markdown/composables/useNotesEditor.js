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
     * What a note's styling adds to the form.
     *
     * The banner and the appearance are saved like the text - through the
     * same autosave, on the same route - because they are fields of the note
     * and nothing else. Naming them here brings them into the snapshot, so
     * into change detection: picking an image triggers the save, without a
     * button.
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
    // Which note this form really holds. Without it, a load that failed left
    // the previous note's text facing an id that had already changed, and
    // the autosave wrote one over the other.
    const loadedId = ref(null);
    const saving = ref(false);
    const deleting = ref(false);
    const pendingDelete = ref(null);

    /**
     * The note's version as it was loaded, and what happens when the server
     * has a more recent one.
     *
     * A save that started from an outdated version is refused: someone wrote
     * in the meantime. The editor then stops saving - it must neither retry
     * in a loop nor overwrite - and the person chooses: reload, or overwrite
     * knowingly.
     */
    const loadedVersion = ref(null);
    const conflict = ref(false);
    let forceNextSave = false;

    const selectedNote = computed(
        () => notes.value.find((n) => n.id === selectedId.value) ?? null,
    );

    /**
     * Does what the form shows belong to the requested note?
     *
     * False between the click and the arrival of the response, and false
     * too when loading failed. It is the same `loadedId` that guards the
     * autosave: a single truth for "is this form this note's", rather than a
     * loading flag to keep up to date on the side.
     */
    const bodyReady = computed(
        () => null !== loadedId.value && loadedId.value === selectedId.value,
    );

    const isDirty = computed(() => {
        if (!loadedSnapshot.value) return false;
        // In a conflict, nothing goes out on its own: the person decides.
        if (conflict.value) return false;
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

        // The styling counts as much as the text. Without these keys,
        // picking an image or moving the framing left the form "clean": the
        // watcher did fire, but went off again right away for lack of a
        // difference to write, and the setting disappeared on the next
        // reload. Reported by Axel on 23/09.
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
        onError: () => {
            // A conflict has its own modal: a second message on top would
            // say the same thing twice, and the less clear of the two.
            if (!conflict.value)
                toast.error(t("notes.markdown.errors.save_failed"));
        },
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
        // The form holds nothing reliable until the requested note has
        // arrived: marking it unloaded shuts the door on a save that would
        // write the old note over the new one.
        loadedId.value = null;
        loadedSnapshot.value = null;
        // And we empty it for good. Marking it unloaded was enough to protect
        // the data, not the eyes: the title, the text and the banner of the
        // note just left stayed on screen for the round trip, under the new
        // one's identity. So for a split second one saw a note that never
        // existed.
        form.value = blankForm();

        // The title and the header are already known: they are in the flat
        // list, which is what was just clicked. Setting them right away
        // avoids two flickers - a wrong title replaced by an empty field, and
        // above all the banner that disappeared then came back, a hundred and
        // sixty pixel jump each time one went from one note with a banner to
        // another. Only the text waits for the response.
        const connue = notes.value.find((n) => n.id === id);
        if (connue) {
            form.value.title = connue.title ?? "";
            Object.assign(form.value, pickLook(connue));
        }

        const { ok, reported, payload } = await api.show(id);

        // A late response must not overwrite a note chosen since. The screen
        // asks for two in a row on load - the template's active note, then
        // the address's - and when they came back out of order, the first one
        // was shown: opening a note's link showed another one, at the
        // network's whim.
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
        loadedVersion.value = payload.note.version ?? null;
        conflict.value = false;
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

        // Never write a form that was not loaded for this note: it is the
        // only point where the confusion would turn into lost text.
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
            const force = forceNextSave;
            forceNextSave = false;

            const { ok, payload } = await api.update(noteId, {
                folderId,
                ...snapshot,
                version: loadedVersion.value,
                force,
            });
            if (!ok) {
                if (payload?.conflict) conflict.value = true;

                return false;
            }

            if (payload?.note?.version)
                loadedVersion.value = payload.note.version;

            // Update the snapshot so isDirty drops to false - without
            // overwriting `form` (the user may have typed more chars
            // while the request was in flight; those stay dirty and the
            // next debounce will flush them).
            loadedSnapshot.value = snapshot;

            // Keep the flat list (sidebar tree, library cards) in sync. We
            // touch the entry in place rather than refetching the whole list
            // to avoid losing scroll / state. The server's copy brings the
            // new excerpt and date: without them a card kept showing the
            // text as it was when the page loaded.
            const saved = payload?.note ?? null;
            const index = notes.value.findIndex((n) => n.id === noteId);
            if (index !== -1) {
                notes.value[index] = {
                    ...notes.value[index],
                    title: snapshot.title,
                    tags: snapshot.tags,
                    ...(saved
                        ? {
                              excerpt: saved.excerpt ?? null,
                              updatedAt: saved.updatedAt,
                          }
                        : {}),
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
    /** Overwrite the server's version with what is on screen, knowingly. */
    async function saveAnyway() {
        conflict.value = false;
        forceNextSave = true;
        await flushPendingSave();
        forceNextSave = false;
    }

    /**
     * Take back the server's version, dropping what could not be saved. The
     * form is first declared clean: otherwise, the note change would attempt
     * a last save, refused in turn.
     */
    async function reloadDiscarding() {
        cancelAutoSave();
        loadedSnapshot.value = {
            ...form.value,
            tags: [...(form.value.tags ?? [])],
        };
        conflict.value = false;
        await reloadCurrent();
    }

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
    // No more automatic opening of the first note.
    //
    // It dated from the time when this page was only an editor: with no open
    // note there was nothing to show, so one was opened. Since the notebook
    // has a library, this line hijacked everything: arriving on "Tous les
    // documents" opened a note, entering a folder opened one too, and
    // clicking a folder in the menu seemed to do nothing - the library was
    // never mounted, so the panel talked to an absent page and fell back on
    // a navigation that reopened a note.
    //
    // The server decides: a note address opens that note (see
    // `useMarkdownNotesPage`), the others show the library.

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
        conflict,
        // actions
        refreshList,
        reloadCurrent,
        saveAnyway,
        reloadDiscarding,
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
