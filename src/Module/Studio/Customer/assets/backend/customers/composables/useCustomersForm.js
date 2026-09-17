import { computed, ref } from "vue";
import { useI18n } from "vue-i18n";
import { toast } from "vue-sonner";
import { buildPath } from "@/shared/utils/http/buildPath.js";
import { useFormAction } from "@/shared/composables/form/useFormAction.js";
import { useDelete } from "@/shared/composables/form/useDelete.js";
import { useClientFilteredList } from "@/shared/composables/list/useClientFilteredList.js";
import { required } from "@/shared/utils/validation/validators.js";

/**
 * The form is one shape for create and edit, because the fields are the same
 * ones either way: a customer is a block of legal identity, and there is no
 * field that only makes sense the first time it is filled in.
 */
function emptyForm() {
    return {
        legalName: "",
        legalForm: "",
        shareCapital: "",
        shareCapitalCurrency: "EUR",
        registeredOffice: "",
        siret: "",
        tradeRegister: "",
        vatNumber: "",
        activitySector: "",
        representativeFirstName: "",
        representativeLastName: "",
        representativeRole: "",
        contractualEmail: "",
        phone: "",
        userId: "",
        // Ce qu'est une fiche au moment ou on la cree.
        status: "prospect",
    };
}

/**
 * A stored amount is cents; a typed amount is units. Shown with decimals only
 * when it has them, so a capital of 10 000 € does not read as 10 000,00 € in a
 * field somebody is about to retype.
 */
function centsToInput(cents) {
    if (cents === null || cents === undefined) return "";

    const units = cents / 100;

    return Number.isInteger(units) ? String(units) : units.toFixed(2);
}

function formFrom(customer) {
    return {
        legalName: customer.legalName ?? "",
        legalForm: customer.legalForm ?? "",
        shareCapital: centsToInput(customer.shareCapitalCents),
        shareCapitalCurrency: customer.shareCapitalCurrency ?? "EUR",
        registeredOffice: customer.registeredOffice ?? "",
        siret: customer.siret ?? "",
        tradeRegister: customer.tradeRegister ?? "",
        vatNumber: customer.vatNumber ?? "",
        activitySector: customer.activitySector ?? "",
        representativeFirstName: customer.representativeFirstName ?? "",
        representativeLastName: customer.representativeLastName ?? "",
        representativeRole: customer.representativeRole ?? "",
        contractualEmail: customer.contractualEmail ?? "",
        phone: customer.phone ?? "",
        userId: customer.userId ?? "",
        status: customer.status ?? "prospect",
    };
}

export function useCustomersForm(
    initialCustomers,
    initialUsers,
    createPath,
    updatePath,
    deletePath,
) {
    const { t } = useI18n();

    const {
        items,
        searchInput: search,
        filteredItems,
    } = useClientFilteredList(initialCustomers, null, (customer, query) =>
        // Searched on the three things somebody actually remembers about a
        // company: its name, its number, and who signs for it.
        [
            customer.legalName,
            customer.siret,
            customer.representativeFullName,
            customer.contractualEmail,
        ]
            .filter(Boolean)
            .some((field) => String(field).toLowerCase().includes(query)),
    );

    const users = ref(initialUsers ?? []);

    const userOptions = computed(() =>
        users.value.map((user) => ({
            value: String(user.id),
            label: user.email ? `${user.name} (${user.email})` : user.name,
        })),
    );

    function applyUpdatedList(data) {
        if (Array.isArray(data?.customers)) items.value = data.customers;
    }

    function rulesFor(form) {
        return {
            legalName: () =>
                required(
                    t("backend.studio.customers.errors.legal_name_required"),
                )(form.value.legalName),
            // Exigee d'un client, facultative d'un prospect : c'est la seule
            // chose que le statut impose, et le serveur la tient aussi.
            contractualEmail: () =>
                "prospect" === form.value.status
                    ? null
                    : required(
                          t(
                              "backend.studio.customers.errors.contractual_email_required",
                          ),
                      )(form.value.contractualEmail),
        };
    }

    const showCreate = ref(false);
    const newCustomer = ref(emptyForm());

    const {
        errors: createErrors,
        loading: createLoading,
        submit: submitCreate,
        clearErrors: clearCreate,
    } = useFormAction({
        rules: () => rulesFor(newCustomer),
        url: () => createPath,
        body: () => newCustomer.value,
        onSuccess: (data) => {
            showCreate.value = false;
            toast.success(t("backend.studio.customers.created"));
            applyUpdatedList(data);
        },
    });

    function openCreate() {
        newCustomer.value = emptyForm();
        clearCreate();
        showCreate.value = true;
    }

    const showEdit = ref(false);
    const editingCustomer = ref(null);
    const editForm = ref(emptyForm());

    const {
        errors: editErrors,
        loading: editLoading,
        submit: submitEdit,
        clearErrors: clearEdit,
    } = useFormAction({
        rules: () => rulesFor(editForm),
        url: () => buildPath(updatePath, { id: editingCustomer.value.id }),
        body: () => editForm.value,
        onSuccess: (data) => {
            showEdit.value = false;
            toast.success(t("backend.studio.customers.updated"));
            applyUpdatedList(data);
        },
    });

    function openEdit(customer) {
        editingCustomer.value = customer;
        editForm.value = formFrom(customer);
        clearEdit();
        showEdit.value = true;
    }

    const {
        pendingDelete,
        loading: deleteLoading,
        confirm: confirmDelete,
        submit: doDelete,
    } = useDelete(
        deletePath,
        (id) => {
            items.value = items.value.filter((customer) => customer.id !== id);
        },
        "backend.studio.customers.deleted",
    );

    return {
        items,
        applyUpdatedList,
        search,
        filteredItems,
        userOptions,
        showCreate,
        newCustomer,
        createErrors,
        createLoading,
        openCreate,
        submitCreate,
        showEdit,
        editingCustomer,
        editForm,
        editErrors,
        editLoading,
        openEdit,
        submitEdit,
        pendingDelete,
        deleteLoading,
        confirmDelete,
        doDelete,
    };
}
