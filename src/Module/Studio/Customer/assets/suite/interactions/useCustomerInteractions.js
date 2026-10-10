import { ref } from "vue";
import { useI18n } from "vue-i18n";
import { toast } from "vue-sonner";
import { buildPath } from "@/shared/utils/http/buildPath.js";
import { useRequest } from "@/shared/composables/http/suite/useRequest.js";

/**
 * A customer's exchanges: the timeline, and writing in it.
 *
 * **Recording an exchange settles the follow-up.** The form offers the next
 * follow-up date with the exchange, prefilled with the one already set when
 * it is still to come: calling back is what makes a due follow-up obsolete,
 * and the moment the call is written down is when the next one is decided.
 * Left empty, the follow-up is cleared. Only a new exchange does this; editing
 * an old one leaves the follow-up alone.
 *
 * @param {object} options
 * @param {Array<object>} options.initial
 * @param {{create: string, update: string, delete: string}} options.paths update/delete carry `__id__`
 * @param {import("vue").Ref<object>} options.customer the saved sheet, for its follow-up
 * @param {(customer: object) => void} options.onCustomer told of the sheet read again
 */
export function useCustomerInteractions({
    initial,
    paths,
    customer,
    onCustomer,
}) {
    const { t } = useI18n();
    const { request } = useRequest();

    const interactions = ref(initial ?? []);
    const form = ref(null);
    const errors = ref({});
    const saving = ref(false);

    function openCreate(kind = "call") {
        errors.value = {};
        const upcoming = "upcoming" === customer.value.followUpState;
        form.value = {
            id: null,
            kind,
            // An instant with its offset: the date field shows it at the
            // site's time, whatever the computer's zone.
            occurredAt: new Date().toISOString(),
            summary: "",
            setsFollowUp: true,
            nextFollowUpOn: upcoming
                ? (customer.value.nextFollowUpOn ?? "")
                : "",
            followUpNote: upcoming ? (customer.value.followUpNote ?? "") : "",
        };
    }

    function openEdit(interaction) {
        errors.value = {};
        form.value = {
            id: interaction.id,
            kind: interaction.kind,
            occurredAt: interaction.occurredAt,
            summary: interaction.summary,
            setsFollowUp: false,
        };
    }

    function close() {
        form.value = null;
    }

    function apply(data) {
        if (Array.isArray(data?.interactions))
            interactions.value = data.interactions;
        if (data?.customer) onCustomer(data.customer);
    }

    async function save() {
        if (!form.value || saving.value) return;
        if (!form.value.summary.trim()) {
            errors.value = {
                summary: t(
                    "suite.studio.customer_interactions.errors.summary_required",
                ),
            };

            return;
        }

        saving.value = true;
        try {
            const url = form.value.id
                ? buildPath(paths.update, { id: form.value.id })
                : paths.create;
            const data = await request(url, form.value, { noGuard: true });
            if (!data) return;
            if (!data.success) {
                errors.value = data.errors ?? {};

                return;
            }
            apply(data);
            form.value = null;
            toast.success(t("suite.studio.customer_interactions.saved"));
        } finally {
            saving.value = false;
        }
    }

    const pendingDelete = ref(null);
    const deleting = ref(false);

    async function remove() {
        if (!pendingDelete.value || deleting.value) return;
        deleting.value = true;
        try {
            const data = await request(
                buildPath(paths.delete, { id: pendingDelete.value.id }),
                {},
                { noGuard: true },
            );
            if (!data?.success) return;
            apply(data);
            pendingDelete.value = null;
            toast.success(t("suite.studio.customer_interactions.deleted"));
        } finally {
            deleting.value = false;
        }
    }

    return {
        interactions,
        form,
        errors,
        saving,
        openCreate,
        openEdit,
        close,
        save,
        pendingDelete,
        deleting,
        remove,
    };
}
