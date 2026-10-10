import { ref } from "vue";
import { useI18n } from "vue-i18n";
import { toast } from "vue-sonner";
import { buildPath } from "@/shared/utils/http/buildPath.js";
import { useRequest } from "@/shared/composables/http/suite/useRequest.js";
import { HttpMethod } from "@/shared/utils/http/httpMethod.js";

/** The answers a form received, a page at a time. */
export function useFormSubmissions(props) {
    const { t } = useI18n();
    const { request } = useRequest();

    const submissions = ref([]);
    /**
     * Whether a message can become a prospect, and where to ask: both from
     * the server, which knows whether the customers are switched on and
     * whether the reader may create one.
     */
    const canCreateProspect = ref(false);
    const prospectCreatePath = ref(null);
    const creatingFor = ref(null);
    const total = ref(0);
    const page = ref(1);
    const totalPages = ref(1);
    const loading = ref(false);

    async function load() {
        loading.value = true;
        try {
            const data = await request(
                `${props.submissionsPath}?page=${page.value}`,
                null,
                { method: HttpMethod.Get, noGuard: true },
            );
            if (!data?.success) return;

            submissions.value = data.submissions;
            canCreateProspect.value = true === data.canCreateProspect;
            prospectCreatePath.value = data.prospectCreatePath ?? null;
            total.value = data.total;
            page.value = data.page;
            totalPages.value = data.totalPages;
        } finally {
            loading.value = false;
        }
    }

    function goToPage(next) {
        if (next < 1 || next > totalPages.value) return;

        page.value = next;
        void load();
    }

    function exportUrl() {
        return props.exportPath;
    }

    /**
     * A message becomes a prospect. The button turns into a link to the
     * prospect's page at once: the message cannot make two.
     */
    async function createProspect(submission) {
        if (!prospectCreatePath.value || creatingFor.value) return;

        creatingFor.value = submission.id;
        try {
            const data = await request(
                buildPath(prospectCreatePath.value, { id: submission.id }),
                {},
                { noGuard: true },
            );
            if (!data?.success) return;

            submissions.value = submissions.value.map((row) =>
                row.id === submission.id
                    ? { ...row, prospectPath: data.prospectPath }
                    : row,
            );
            toast.success(t("suite.forms.submissions.prospect_created"));
        } finally {
            creatingFor.value = null;
        }
    }

    return {
        submissions,
        total,
        page,
        totalPages,
        loading,
        load,
        goToPage,
        exportUrl,
        canCreateProspect,
        creatingFor,
        createProspect,
    };
}
