import { computed, ref, watch } from "vue";
import { useI18n } from "vue-i18n";
import { useMoneyFormat } from "@/shared/composables/format/useMoneyFormat.js";
import { toast } from "vue-sonner";
import { buildPath } from "@/shared/utils/http/buildPath.js";
import { useFormAction } from "@/shared/composables/form/useFormAction.js";
import { required } from "@/shared/utils/validation/validators.js";
import { formFromContract } from "./contractForm.js";

/** The steps of the journey, in the order a contract travels them. */
export const STEPS = [
    "draft",
    "to_send",
    "with_customer",
    "to_countersign",
    "active",
    "ended",
];

/** Rows per page: a screen's worth, and the rest one click away. */
export const PAGE_SIZE = 25;

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

/**
 * The list of contracts: one list, read by step.
 *
 * It used to be two tables, « En préparation » and « Scellés », which did not
 * show the same columns, sorted in an order nobody chose, and let a concluded
 * contract sit beside one waiting for a signature with nothing to tell them
 * apart but a grey word. Now every contract is in one list, filed by the step
 * it is at (see the serializer's `step`), searchable by reference, customer or
 * trame, filtered by customer or trame, newest activity first.
 *
 * Creating and editing a draft stay here; every other gesture belongs to the
 * contract's own screen, which the rows open.
 */
export function useContractsList(props) {
    const { t } = useI18n();
    const { formatMoney } = useMoneyFormat();

    const items = ref([...(props.contracts ?? [])]);

    const query = new URLSearchParams(window.location.search);
    const step = ref(
        STEPS.includes(query.get("step")) ? query.get("step") : "all",
    );
    const customerFilter = ref(query.get("customer") ?? "");
    const templateFilter = ref(query.get("template") ?? "");
    const search = ref("");
    const page = ref(1);

    function applyList(data) {
        if (Array.isArray(data?.contracts)) items.value = data.contracts;
    }

    function matchesSearch(contract, needle) {
        if (!needle) return true;

        return [
            contract.reference,
            contract.customerName,
            contract.body?.templateName,
            contract.annex?.templateName,
        ]
            .filter(Boolean)
            .some((value) => value.toLowerCase().includes(needle));
    }

    function matchesFilters(contract) {
        if (
            customerFilter.value &&
            String(contract.customerId) !== String(customerFilter.value)
        )
            return false;

        if (
            templateFilter.value &&
            String(contract.body?.templateId ?? "") !==
                String(templateFilter.value) &&
            String(contract.annex?.templateId ?? "") !==
                String(templateFilter.value)
        ) {
            return false;
        }

        return true;
    }

    /** Everything the search and the filters keep, before the step. */
    const filtered = computed(() => {
        const needle = search.value.trim().toLowerCase();

        return (
            items.value
                .filter(
                    (contract) =>
                        matchesSearch(contract, needle) &&
                        matchesFilters(contract),
                )
                // Newest activity first, then newest contract: two rows with the
                // same day used to come in whatever order the query returned.
                .sort(
                    (left, right) =>
                        String(right.lastActivityAt ?? "").localeCompare(
                            String(left.lastActivityAt ?? ""),
                        ) || (right.id ?? 0) - (left.id ?? 0),
                )
        );
    });

    /** How many at each step, so a tab says whether it is worth opening. */
    const counts = computed(() => {
        const result = { all: filtered.value.length };

        for (const key of STEPS) result[key] = 0;
        for (const contract of filtered.value)
            result[contract.step] = (result[contract.step] ?? 0) + 1;

        return result;
    });

    const inStep = computed(() =>
        "all" === step.value
            ? filtered.value
            : filtered.value.filter((contract) => contract.step === step.value),
    );

    const totalPages = computed(() =>
        Math.max(1, Math.ceil(inStep.value.length / PAGE_SIZE)),
    );
    const rows = computed(() =>
        inStep.value.slice(
            (page.value - 1) * PAGE_SIZE,
            page.value * PAGE_SIZE,
        ),
    );

    watch([step, search, customerFilter, templateFilter], () => {
        page.value = 1;
    });

    /* Creation: the draft opens on its own screen, where « Sceller » is. */

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
                required(t("suite.studio.contracts.errors.customer_required"))(
                    newContract.value.customerId,
                ),
            bodyTemplateId: () =>
                required(t("suite.studio.contracts.errors.body_required"))(
                    newContract.value.bodyTemplateId,
                ),
        }),
        url: () => props.createPath,
        body: () => newContract.value,
        onSuccess: (data) => {
            showCreate.value = false;
            toast.success(t("suite.studio.contracts.created"));
            applyList(data);

            if (data?.contract?.id)
                window.location.assign(
                    buildPath(props.showPath, { id: data.contract.id }),
                );
        },
    });

    /**
     * Opens the form, optionally on an amendment of a given contract.
     *
     * An amendment is started from the contract it amends (its screen links
     * here with `?amends=<id>`), never from the general form: a new contract
     * used to open on « Avenant au contrat », before even the customer.
     */
    function openCreate(amendsId = null) {
        const form = emptyForm(props.locales);
        const parent = amendsId
            ? props.amendable.find((contract) => contract.id === amendsId)
            : null;

        if (parent) {
            form.amendsId = parent.id;
            form.customerId = String(parent.customerId);
        }

        newContract.value = form;
        clearCreate();
        showCreate.value = true;
    }

    /* Editing a draft from its row. */

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
                required(t("suite.studio.contracts.errors.customer_required"))(
                    editForm.value.customerId,
                ),
            bodyTemplateId: () =>
                required(t("suite.studio.contracts.errors.body_required"))(
                    editForm.value.bodyTemplateId,
                ),
        }),
        url: () => buildPath(props.updatePath, { id: editing.value.id }),
        body: () => editForm.value,
        onSuccess: (data) => {
            showEdit.value = false;
            toast.success(t("suite.studio.contracts.updated"));
            applyList(data);
        },
    });

    function openEdit(contract) {
        editing.value = contract;
        // Built by the helper the contract's own screen uses too: two copies
        // drifted, and this one forgot the parent of an amendment.
        editForm.value = formFromContract(contract);
        clearEdit();
        showEdit.value = true;
    }

    function formatAmount(contract) {
        return formatMoney(
            contract.amountCents,
            contract.amountCurrency ?? "EUR",
        );
    }

    return {
        items,
        search,
        step,
        customerFilter,
        templateFilter,
        page,
        totalPages,
        counts,
        rows,
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
        formatAmount,
    };
}
