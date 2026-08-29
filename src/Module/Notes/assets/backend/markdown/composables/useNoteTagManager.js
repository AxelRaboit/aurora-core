import { ref, computed, watch } from "vue";
import { useI18n } from "vue-i18n";
import { toast } from "vue-sonner";

/**
 * Full state machine for the `NoteTagManagerModal` :
 *  - tag histogram fetched from the server
 *  - in-modal text filter
 *  - multi-select set (for the merge action)
 *  - per-row Rename mini state machine (`renaming = { source, draft }`)
 *  - per-row Delete confirm (`pendingDelete = tag`)
 *  - merge target text + submit
 *  - submitting flag shared across all destructive ops
 *
 * The modal SFC is left with template + bindings only.
 *
 * @param {object}   options
 * @param {object}   options.api       - `useMarkdownTagsApi()` instance
 * @param {import('vue').Ref<boolean>} options.show
 * @param {() => void} options.onChanged - emit('changed') from the SFC
 */
export function useNoteTagManager({ api, show, onChanged }) {
    const { t } = useI18n();

    const tags = ref([]); // [{ tag, count }]
    const loading = ref(false);
    const query = ref("");
    const selected = ref(new Set()); // tag strings selected for merge
    const renaming = ref(null); // { source: string, draft: string }
    const pendingDelete = ref(null); // tag string awaiting confirm
    const mergeTarget = ref("");
    const submitting = ref(false);

    const filteredTags = computed(() => {
        const q = query.value.trim().toLowerCase();
        if (q === "") return tags.value;
        return tags.value.filter((entry) =>
            entry.tag.toLowerCase().includes(q),
        );
    });

    const selectedTags = computed(() => Array.from(selected.value));

    function resetState() {
        query.value = "";
        selected.value = new Set();
        renaming.value = null;
        pendingDelete.value = null;
        mergeTarget.value = "";
    }

    async function refresh() {
        loading.value = true;
        try {
            const data = await api.list();
            if (!data || data.success === false) {
                tags.value = [];
                return;
            }
            tags.value = data.tags ?? [];
        } finally {
            loading.value = false;
        }
    }

    // Re-load the histogram every time the modal opens; reset transient
    // sub-states (in-progress rename / delete / selection).
    watch(show, (isOpen) => {
        if (!isOpen) return;
        resetState();
        refresh();
    });

    function toggleSelected(tag) {
        const next = new Set(selected.value);
        if (next.has(tag)) {
            next.delete(tag);
        } else {
            next.add(tag);
        }
        selected.value = next;
    }

    function isSelected(tag) {
        return selected.value.has(tag);
    }

    function beginRename(entry) {
        renaming.value = { source: entry.tag, draft: entry.tag };
        pendingDelete.value = null;
    }

    function cancelRename() {
        renaming.value = null;
    }

    async function confirmRename() {
        if (!renaming.value) return;
        const { source, draft } = renaming.value;
        const newTag = draft.trim();
        if (newTag === "" || newTag === source) {
            renaming.value = null;
            return;
        }

        submitting.value = true;
        try {
            const data = await api.rename(source, newTag);
            if (!data || data.success === false) {
                toast.error(
                    t("notes.markdown.tags.manage.errors.rename_failed"),
                );
                return;
            }
            toast.success(
                t(
                    "notes.markdown.tags.manage.rename_success",
                    { count: data.affected ?? 0 },
                    data.affected ?? 0,
                ),
            );
            renaming.value = null;
            onChanged();
            await refresh();
        } finally {
            submitting.value = false;
        }
    }

    function beginDelete(entry) {
        pendingDelete.value = entry.tag;
        renaming.value = null;
    }

    function cancelDelete() {
        pendingDelete.value = null;
    }

    async function confirmDelete() {
        if (!pendingDelete.value) return;
        const tag = pendingDelete.value;

        submitting.value = true;
        try {
            const data = await api.remove(tag);
            if (!data || data.success === false) {
                toast.error(
                    t("notes.markdown.tags.manage.errors.delete_failed"),
                );
                return;
            }
            toast.success(
                t(
                    "notes.markdown.tags.manage.delete_success",
                    { count: data.affected ?? 0 },
                    data.affected ?? 0,
                ),
            );
            pendingDelete.value = null;
            const next = new Set(selected.value);
            next.delete(tag);
            selected.value = next;
            onChanged();
            await refresh();
        } finally {
            submitting.value = false;
        }
    }

    async function confirmMerge() {
        const sources = selectedTags.value;
        const target = mergeTarget.value.trim();
        if (sources.length < 2 || target === "") return;

        submitting.value = true;
        try {
            const data = await api.merge(sources, target);
            if (!data || data.success === false) {
                toast.error(
                    t("notes.markdown.tags.manage.errors.merge_failed"),
                );
                return;
            }
            toast.success(
                t(
                    "notes.markdown.tags.manage.merge_success",
                    { count: data.affected ?? 0 },
                    data.affected ?? 0,
                ),
            );
            selected.value = new Set();
            mergeTarget.value = "";
            onChanged();
            await refresh();
        } finally {
            submitting.value = false;
        }
    }

    return {
        // state
        tags,
        loading,
        query,
        renaming,
        pendingDelete,
        mergeTarget,
        submitting,
        filteredTags,
        selectedTags,
        // actions
        toggleSelected,
        isSelected,
        beginRename,
        cancelRename,
        confirmRename,
        beginDelete,
        cancelDelete,
        confirmDelete,
        confirmMerge,
    };
}
