import { ref, watch } from "vue";
import { useDebounce } from "@/shared/composables/useDebounce.js";
import { useRequest } from "@/shared/composables/http/backend/useRequest.js";

/**
 * Keeps a rendered preview in step with what is being edited.
 *
 * The markup comes from the server, through the same Twig the public page
 * uses. Rebuilding it in Vue would be faster and would drift — two renderers
 * for one thing is exactly how `twoColumn` ended up writing a shape its
 * renderer could not read. A preview that can disagree with the published page
 * is worse than none, because it is believed.
 *
 * Generic over what is being previewed: the caller says what to send. The
 * banner sends its two halves, the content grid sends its two plus the locale
 * its cards link with. Everything else — the debounce, the out-of-order guard,
 * the loading flag — was identical between them, and a second copy of a guard
 * is a second place for it to be dropped.
 *
 * Debounced: every keystroke in a title would otherwise be a request. 400ms is
 * long enough to swallow typing and short enough that adjusting a width still
 * feels immediate.
 *
 * @param {() => object} payload  What to post. Read on every request, so it
 *                                sees the current state rather than a snapshot
 *                                taken when this was called.
 * @param {Array}        sources  Reactive sources to watch, deeply.
 * @param {string}       path     Where to post it.
 */
export function useServerPreview(payload, sources, path) {
    const { request } = useRequest();

    const html = ref("");
    const loading = ref(false);

    // Guards against a slow response overwriting a newer one: only the answer
    // to the most recent question is allowed to land.
    let latest = 0;

    async function fetchPreview() {
        const ticket = ++latest;
        loading.value = true;

        try {
            const data = await request(path, snapshot(payload()));

            if (ticket === latest && data?.success) {
                html.value = data.html ?? "";
            }
        } finally {
            if (ticket === latest) {
                loading.value = false;
            }
        }
    }

    /**
     * Strips the reactive proxies so what goes over the wire is plain data.
     * Tolerates undefined, which is what a translation that has not finished
     * loading looks like — throwing inside a debounced callback would surface
     * 400ms later as a preview that simply stopped updating.
     */
    function snapshot(value) {
        return undefined === value ? {} : JSON.parse(JSON.stringify(value));
    }

    const schedule = useDebounce(fetchPreview, 400);

    watch(sources, schedule, { deep: true, immediate: true });

    return { html, loading, refresh: fetchPreview };
}
