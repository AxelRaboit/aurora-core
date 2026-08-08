import { ref, watch } from "vue";
import { useDebounce } from "@/shared/composables/useDebounce.js";
import { useRequest } from "@/shared/composables/http/backend/useRequest.js";

/**
 * Keeps a rendered preview of the banner in step with the panel.
 *
 * The markup comes from the server, through the same Twig the public page
 * uses. Rebuilding the banner in Vue would be faster and would drift — two
 * renderers for one thing is exactly how `twoColumn` ended up writing a shape
 * its renderer could not read. A preview that can disagree with the published
 * page is worse than none, because it is believed.
 *
 * Debounced: every keystroke in a title would otherwise be a request. 400ms
 * is long enough to swallow typing and short enough that adjusting a colour
 * still feels immediate.
 */
export function useBannerPreview(banner, previewPath) {
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
            const data = await request(previewPath, {
                banner: JSON.parse(JSON.stringify(banner.value)),
            });

            if (ticket === latest && data?.success) {
                html.value = data.html ?? "";
            }
        } finally {
            if (ticket === latest) {
                loading.value = false;
            }
        }
    }

    const schedule = useDebounce(fetchPreview, 400);

    watch(banner, schedule, { deep: true, immediate: true });

    return { html, loading, refresh: fetchPreview };
}
