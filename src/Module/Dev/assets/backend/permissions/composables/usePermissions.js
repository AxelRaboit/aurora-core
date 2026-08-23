import { ref, onMounted } from "vue";
import { useI18n } from "vue-i18n";
import { HttpMethod } from "@/shared/utils/http/httpMethod.js";
import { useRequest } from "@/shared/composables/http/backend/useRequest.js";

/**
 * Lazy-loaded module/permission registry for the dev dashboard Permissions
 * tab. Read-only - there is no mutation API.
 */
export function usePermissions(permissionsPath, initialPermissions) {
    useI18n();
    const data = ref(initialPermissions ?? { modules: [] });
    const { loading, request } = useRequest();

    async function load() {
        const result = await request(permissionsPath, null, HttpMethod.Get);
        if (result !== null) {
            data.value = result;
        }
    }

    onMounted(() => {
        if (!data.value?.modules?.length) load();
    });

    return { data, loading, load };
}
