import { ref, watch, computed } from "vue";

/**
 * State + fetch lifecycle for the Markdown notes side panel (backlinks /
 * unlinked-mentions tabs). Owns the tab ref, the two result lists, and
 * the loading flag. Refreshes on note change and on tab change so the
 * SFC stays presentation-only.
 *
 * @param {object} options
 * @param {import('vue').Ref<number|null>} options.noteIdRef - selected note id
 * @param {(id: number) => Promise<{ok: boolean, payload: any}>} options.fetchBacklinks
 * @param {(id: number) => Promise<{ok: boolean, payload: any}>} options.fetchUnlinkedMentions
 */
export function useNoteSidePanel({
    noteIdRef,
    fetchBacklinks,
    fetchUnlinkedMentions,
}) {
    const tab = ref("backlinks");
    const backlinks = ref([]);
    const mentions = ref([]);
    const loading = ref(false);

    const items = computed(() =>
        tab.value === "backlinks" ? backlinks.value : mentions.value,
    );

    async function refresh() {
        if (noteIdRef.value === null) return;
        loading.value = true;
        try {
            if (tab.value === "backlinks") {
                const { ok, payload } = await fetchBacklinks(noteIdRef.value);
                backlinks.value = ok ? (payload.backlinks ?? []) : [];
            } else {
                const { ok, payload } = await fetchUnlinkedMentions(
                    noteIdRef.value,
                );
                mentions.value = ok ? (payload.mentions ?? []) : [];
            }
        } finally {
            loading.value = false;
        }
    }

    watch(
        noteIdRef,
        async (id) => {
            if (id === null) {
                backlinks.value = [];
                mentions.value = [];
                return;
            }
            await refresh();
        },
        { immediate: true },
    );

    watch(tab, async () => {
        await refresh();
    });

    return { tab, items, loading, refresh };
}
