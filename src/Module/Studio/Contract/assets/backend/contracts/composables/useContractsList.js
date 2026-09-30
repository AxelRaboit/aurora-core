import { computed, ref } from "vue";
import { useI18n } from "vue-i18n";
import { toast } from "vue-sonner";
import { buildPath } from "@/shared/utils/http/buildPath.js";
import { useRequest } from "@/shared/composables/http/backend/useRequest.js";
import { useFormAction } from "@/shared/composables/form/useFormAction.js";
import { useClientFilteredList } from "@/shared/composables/list/useClientFilteredList.js";
import { required } from "@/shared/utils/validation/validators.js";

function emptyForm(locales) {
    return {
        // Null rather than "" so a contract that amends nothing sends no
        // parent at all: an empty string would reach the factory as an id to
        // resolve, and be refused for naming no row.
        amendsId: null,
        customerId: "",
        bodyTemplateId: "",
        annexTemplateId: "",
        locale: locales?.[0]?.code ?? "fr",
        amount: "",
        amountCurrency: "EUR",
        effectiveDate: "",
        // Keyed by the token the trame writes, without its prefix. Empty until
        // a trame is chosen: which blanks exist is the wording's answer, not
        // this form's.
        customFields: {},
    };
}

export function useContractsList(props) {
    const { t } = useI18n();
    const { request } = useRequest();

    const {
        items,
        searchInput: search,
        filteredItems,
    } = useClientFilteredList(props.contracts, null, (contract, query) =>
        [contract.reference, contract.customerName]
            .filter(Boolean)
            .some((field) => String(field).toLowerCase().includes(query)),
    );

    /**
     * Drafts are what somebody is working on; the rest is history. Both are
     * always reachable, but the one being worked on comes first.
     */
    const drafts = computed(() =>
        filteredItems.value.filter((contract) => contract.isEditable),
    );

    const sealed = computed(() =>
        filteredItems.value.filter((contract) => !contract.isEditable),
    );

    function applyList(data) {
        if (Array.isArray(data?.contracts)) items.value = data.contracts;
    }

    const showCreate = ref(false);
    const newContract = ref(emptyForm(props.locales));

    const {
        errors: createErrors,
        loading: createLoading,
        submit: submitCreate,
        clearErrors: clearCreate,
    } = useFormAction({
        rules: () => ({
            customerId: () =>
                required(
                    t("backend.studio.contracts.errors.customer_required"),
                )(newContract.value.customerId),
            bodyTemplateId: () =>
                required(t("backend.studio.contracts.errors.body_required"))(
                    newContract.value.bodyTemplateId,
                ),
        }),
        url: () => props.createPath,
        body: () => newContract.value,
        onSuccess: (data) => {
            showCreate.value = false;
            toast.success(t("backend.studio.contracts.created"));
            applyList(data);
        },
    });

    /**
     * Opens the form, optionally on an amendment of a given contract.
     *
     * The document page links here with `?amends=<id>`, which is how an
     * amendment is started from the thing it amends rather than from a select
     * of every contract ever signed. The customer follows the parent, exactly
     * as the manager requires.
     */
    function openCreate(amendsId = null) {
        const form = emptyForm(props.locales);
        const parent = amendsId
            ? props.amendable.find((contract) => contract.id === amendsId)
            : null;

        if (parent) {
            form.amendsId = parent.id;
            form.customerId = parent.customerId;
        }

        newContract.value = form;
        clearCreate();
        showCreate.value = true;
    }

    const showEdit = ref(false);
    const editing = ref(null);
    const editForm = ref(emptyForm(props.locales));

    const {
        errors: editErrors,
        loading: editLoading,
        submit: submitEdit,
        clearErrors: clearEdit,
    } = useFormAction({
        rules: () => ({
            customerId: () =>
                required(
                    t("backend.studio.contracts.errors.customer_required"),
                )(editForm.value.customerId),
            bodyTemplateId: () =>
                required(t("backend.studio.contracts.errors.body_required"))(
                    editForm.value.bodyTemplateId,
                ),
        }),
        url: () => buildPath(props.updatePath, { id: editing.value.id }),
        body: () => editForm.value,
        onSuccess: (data) => {
            showEdit.value = false;
            toast.success(t("backend.studio.contracts.updated"));
            applyList(data);
        },
    });

    function openEdit(contract) {
        editing.value = contract;
        editForm.value = {
            // Kept, or saving the draft of an amendment detached it from the
            // contract it amends: the form sent no parent, and the manager
            // read that as « not an amendment ».
            amendsId: contract.amends?.id ?? null,
            customerId: String(contract.customerId ?? ""),
            bodyTemplateId: String(contract.body?.templateId ?? ""),
            annexTemplateId: String(contract.annex?.templateId ?? ""),
            locale: contract.locale,
            amount:
                contract.amountCents === null ||
                contract.amountCents === undefined
                    ? ""
                    : String(contract.amountCents / 100),
            amountCurrency: contract.amountCurrency ?? "EUR",
            effectiveDate: contract.effectiveDate ?? "",
            customFields: { ...(contract.customFields ?? {}) },
        };
        clearEdit();
        showEdit.value = true;
    }

    const pendingDelete = ref(null);
    const pendingFreeze = ref(null);
    const pendingSend = ref(null);
    const pendingRevoke = ref(null);
    const busy = ref(false);

    async function confirmDelete() {
        const contract = pendingDelete.value;
        pendingDelete.value = null;

        if (!contract || busy.value) return;
        busy.value = true;

        try {
            const data = await request(
                buildPath(props.deletePath, { id: contract.id }),
                {},
            );

            if (data?.errors) {
                toast.error(Object.values(data.errors)[0]);

                return;
            }

            applyList(data);
            toast.success(t("backend.studio.contracts.deleted"));
        } finally {
            busy.value = false;
        }
    }

    /**
     * Sealing, which is the point of no return.
     *
     * On success the page goes to the document: what somebody wants to see
     * immediately after sealing is what was sealed.
     */
    async function confirmFreeze() {
        const contract = pendingFreeze.value;

        if (!contract || busy.value) return;
        busy.value = true;

        try {
            const data = await request(
                buildPath(props.freezePath, { id: contract.id }),
                {},
            );

            if (data?.errors) {
                // Named on the toast: the refusals here say which trame or
                // which token is at fault, and that sentence is the whole
                // value of the message.
                toast.error(Object.values(data.errors)[0]);
                pendingFreeze.value = null;

                return;
            }

            applyList(data);

            if (data?.showPath) {
                window.location.assign(data.showPath);
            }
        } finally {
            busy.value = false;
        }
    }

    /**
     * Sending, which is the act that reaches somebody outside.
     *
     * Kept apart from sealing on purpose: sealing makes the document final,
     * sending hands out an address. A day can pass between the two.
     */
    async function confirmSend() {
        const contract = pendingSend.value;

        if (!contract || busy.value) return;
        busy.value = true;

        try {
            const data = await request(
                buildPath(props.sendPath, { id: contract.id }),
                {},
            );

            if (data?.errors) {
                toast.error(Object.values(data.errors)[0]);

                return;
            }

            applyList(data);
            toast.success(
                t("backend.studio.contracts.sent_to", {
                    email: data?.sentTo ?? "",
                }),
            );
        } finally {
            pendingSend.value = null;
            busy.value = false;
        }
    }

    async function confirmRevoke() {
        const contract = pendingRevoke.value;

        if (!contract || busy.value) return;
        busy.value = true;

        try {
            const data = await request(
                buildPath(props.revokeLinkPath, { id: contract.id }),
                {},
            );

            if (data?.errors) {
                toast.error(Object.values(data.errors)[0]);

                return;
            }

            applyList(data);
            toast.success(t("backend.studio.contracts.revoke_link"));
        } finally {
            pendingRevoke.value = null;
            busy.value = false;
        }
    }

    function documentPath(contract) {
        return buildPath(props.showPath, { id: contract.id });
    }

    function formatAmount(contract) {
        if (
            contract.amountCents === null ||
            contract.amountCents === undefined
        ) {
            return null;
        }

        return new Intl.NumberFormat(undefined, {
            style: "currency",
            currency: contract.amountCurrency ?? "EUR",
            minimumFractionDigits: contract.amountCents % 100 === 0 ? 0 : 2,
        }).format(contract.amountCents / 100);
    }

    return {
        items,
        search,
        drafts,
        sealed,
        showCreate,
        newContract,
        createErrors,
        createLoading,
        openCreate,
        submitCreate,
        showEdit,
        editing,
        editForm,
        editErrors,
        editLoading,
        openEdit,
        submitEdit,
        pendingDelete,
        pendingFreeze,
        pendingSend,
        pendingRevoke,
        busy,
        confirmDelete,
        confirmFreeze,
        confirmSend,
        confirmRevoke,
        documentPath,
        formatAmount,
    };
}
