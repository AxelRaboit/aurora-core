import { ref } from "vue";
import { useI18n } from "vue-i18n";
import { HttpMethod } from "@/shared/utils/http/httpMethod.js";
import { useRequest } from "@/shared/composables/http/suite/useRequest.js";

/**
 * Audit log fetcher with module filtering. Exposes `module` (ref) so the
 * dropdown is two-way bound; setting it triggers an XHR reload.
 */
export function useAudit(auditPath, initialAudit) {
    useI18n();
    const data = ref(initialAudit ?? {});
    const module = ref(initialAudit?.module ?? "");
    const { loading, request } = useRequest();

    async function load() {
        const url = new URL(auditPath, window.location.origin);
        if (module.value) url.searchParams.set("module", module.value);
        const result = await request(url.toString(), null, HttpMethod.Get);
        if (result !== null) {
            data.value = result;
        }
    }

    function setModule(value) {
        module.value = value;
        load();
    }

    return { data, loading, load, module, setModule };
}
