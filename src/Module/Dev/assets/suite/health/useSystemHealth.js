import { onMounted, ref } from "vue";
import { useI18n } from "vue-i18n";
import { toast } from "vue-sonner";
import { useRequest } from "@/shared/composables/http/suite/useRequest.js";
import { HttpMethod } from "@/shared/utils/http/httpMethod.js";
import { buildPath } from "@/shared/utils/http/buildPath.js";

/**
 * The « État du système » report, asked for once the page is on screen.
 *
 * The probes call the live hub, the storage and the site's certificate: the
 * overview opens with its figures and this block fills in a moment later,
 * rather than the whole page waiting for the slowest of them.
 *
 * @param {{ healthPath: string, retryPath: string, deletePath: string, csrfToken: string }} paths
 */
export function useSystemHealth(paths) {
    const { t } = useI18n();
    const { request } = useRequest();

    const report = ref(null);
    const loading = ref(false);
    const failed = ref(false);
    const acting = ref(null);

    async function load() {
        loading.value = true;
        failed.value = false;

        try {
            const data = await request(paths.healthPath, null, {
                method: HttpMethod.Get,
                noGuard: true,
                silent: true,
            });
            if (data?.success) {
                report.value = data.health;
            } else {
                failed.value = true;
            }
        } finally {
            loading.value = false;
        }
    }

    async function act(path, id, doneKey) {
        acting.value = id;

        try {
            const data = await request(
                buildPath(path, { id }),
                { _token: paths.csrfToken },
                { noGuard: true, accept: [403, 404] },
            );
            if (data?.success) {
                report.value = data.health;
                toast.success(t(doneKey));
            } else if (data?.error) {
                toast.error(t(data.error));
            }
        } finally {
            acting.value = null;
        }
    }

    const retry = (id) =>
        act(paths.retryPath, id, "suite.health.failures.retried");
    const remove = (id) =>
        act(paths.deletePath, id, "suite.health.failures.deleted");

    onMounted(load);

    return { report, loading, failed, acting, load, retry, remove };
}
