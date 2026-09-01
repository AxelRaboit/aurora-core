import { onMounted, ref } from "vue";
import { HttpMethod } from "@/shared/utils/http/httpMethod.js";
import { useRequest } from "@/shared/composables/http/backend/useRequest.js";

/**
 * What a side-menu panel needs to get its own data.
 *
 * Every module panel has the same problem: the menu mounts it with no props -
 * and should, because the menu has no business knowing what a folder or a note
 * is - so the panel cannot receive anything with the page payload. It fetches.
 *
 * **A failure here is silent, deliberately.** The panel is furniture beside the
 * navigation, not something the reader asked for. A toast on every page of the
 * module because one auxiliary GET failed would be louder than what it reports,
 * and the links above the panel - the actual navigation - are unaffected.
 * `failed` is exposed so the panel can remove itself rather than sit there
 * empty.
 *
 * @param {string}  endpoint
 * @param {object}  options
 * @param {boolean} options.skip do not fetch at all (the page already draws this)
 * @param {string}  options.key  property to read out of the JSON payload
 */
export function useModulePanelData(endpoint, { skip = false, key } = {}) {
    const data = ref([]);
    const loading = ref(!skip);
    const failed = ref(false);

    // Through `useRequest`, not `fetch` - `convention_no_raw_fetch`. It is the
    // only thing that sends `X-Requested-With`, which is the contract Symfony
    // reads to answer JSON instead of an HTML page. A hand-rolled `fetch` works
    // right up to the day the route it calls starts branching on that header,
    // and then it parses a page as JSON.
    const { request } = useRequest();

    async function load() {
        if (skip) return;

        try {
            // `silent`, because nobody asked for this: the panel fills itself
            // on arrival, and a toast on every page of the module would be
            // louder than what it reports. `noGuard`, because the panel and
            // the page legitimately talk to the server at the same time.
            const payload = await request(endpoint, null, {
                method: HttpMethod.Get,
                silent: true,
                noGuard: true,
            });

            if (null === payload) throw new Error("request failed");

            data.value = (key ? payload[key] : payload) ?? [];
            failed.value = false;
        } catch {
            failed.value = true;
        } finally {
            loading.value = false;
        }
    }

    onMounted(load);

    return { data, loading, failed, reload: load };
}
