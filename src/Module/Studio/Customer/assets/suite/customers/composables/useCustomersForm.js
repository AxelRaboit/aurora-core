import { ref } from "vue";
import { useI18n } from "vue-i18n";
import { toast } from "vue-sonner";
import { useFormAction } from "@/shared/composables/form/useFormAction.js";
import { useDelete } from "@/shared/composables/form/useDelete.js";
import { useClientFilteredList } from "@/shared/composables/list/useClientFilteredList.js";
import { customerFormRules, emptyCustomerForm } from "./customerFormModel.js";

/**
 * La liste des clients : la recherche, la création et la suppression.
 *
 * **La modification n'est plus ici.** Elle se fait sur la page du client, où
 * toute la fiche tient dans un formulaire ; la liste y mène (« Ouvrir »). La
 * fenêtre de création garde le même composant de champs, donc la même fiche.
 */
export function useCustomersForm(initialCustomers, createPath, deletePath) {
    const { t } = useI18n();

    const {
        items,
        searchInput: search,
        filteredItems,
    } = useClientFilteredList(initialCustomers, null, (customer, query) =>
        // Searched on the things somebody actually remembers about a company:
        // its name, its numbers, who signs for it, where to write.
        [
            customer.legalName,
            customer.siret,
            customer.siren,
            customer.representativeFullName,
            customer.contractualEmail,
        ]
            .filter(Boolean)
            .some((field) => String(field).toLowerCase().includes(query)),
    );

    function applyUpdatedList(data) {
        if (Array.isArray(data?.customers)) items.value = data.customers;
    }

    const showCreate = ref(false);
    const newCustomer = ref(emptyCustomerForm());

    const {
        errors: createErrors,
        loading: createLoading,
        submit: submitCreate,
        clearErrors: clearCreate,
    } = useFormAction({
        rules: () => customerFormRules(t, newCustomer),
        url: () => createPath,
        body: () => newCustomer.value,
        onSuccess: (data) => {
            showCreate.value = false;
            toast.success(t("suite.studio.customers.created"));
            applyUpdatedList(data);
        },
    });

    function openCreate() {
        newCustomer.value = emptyCustomerForm();
        clearCreate();
        showCreate.value = true;
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
        "suite.studio.customers.deleted",
    );

    return {
        items,
        applyUpdatedList,
        search,
        filteredItems,
        showCreate,
        newCustomer,
        createErrors,
        createLoading,
        openCreate,
        submitCreate,
        pendingDelete,
        deleteLoading,
        confirmDelete,
        doDelete,
    };
}
