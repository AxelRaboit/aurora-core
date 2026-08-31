import { onMounted, ref } from "vue";

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

    async function load() {
        if (skip) return;

        try {
            const response = await fetch(endpoint, {
                headers: { Accept: "application/json" },
            });
            if (!response.ok) throw new Error(String(response.status));

            const payload = await response.json();
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
