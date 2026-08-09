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
 * @param {() => object}  payload  What to post. Read on every request, so it
 *                                 sees the current state rather than a snapshot
 *                                 taken when this was called.
 * @param {Array}         sources  Reactive sources to watch, deeply.
 * @param {string}        path     Where to post it.
 * @param {object}        options
 * @param {() => boolean} options.enabled  Whether anyone is looking. A preview
 *                                 behind a button spends the whole editing
 *                                 session closed, and rendering Twig on every
 *                                 keystroke for markup nobody sees is work the
 *                                 server does for nothing. Defaults to always
 *                                 on, which is what an inline preview wants.
 */
export function useServerPreview(
    payload,
    sources,
    path,
    { enabled = () => true } = {},
) {
    const { request } = useRequest();

    const html = ref("");
    const loading = ref(false);

    // Guards against a slow response overwriting a newer one: only the answer
    // to the most recent question is allowed to land.
    let latest = 0;

    // Whether what we hold has been overtaken by an edit. Tracked rather than
    // assumed, so re-opening a preview nothing has changed under costs nothing.
    let stale = true;

    async function fetchPreview() {
        const ticket = ++latest;
        stale = false;
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

    watch(
        sources,
        () => {
            stale = true;

            if (enabled()) {
                schedule();
            }
        },
        { deep: true, immediate: true },
    );

    // Opening asks straight away rather than through the debounce: the wait
    // exists to swallow typing, and somebody who just opened a preview is not
    // typing.
    watch(enabled, (on) => {
        if (on && stale) {
            fetchPreview();
        }
    });

    return { html, loading, refresh: fetchPreview };
}
