import { computed, onBeforeUnmount, onMounted, ref } from "vue";
import { useI18n } from "vue-i18n";
import { toast } from "vue-sonner";
import { useFormAction } from "@/shared/composables/form/useFormAction.js";
import { useDelete } from "@/shared/composables/form/useDelete.js";
import { queueFlash } from "@/shared/utils/flash.js";
import { useProspectConversion } from "./useProspectConversion.js";
import { customerFormFrom, customerFormRules } from "./customerFormModel.js";

/**
 * A customer's page: their whole sheet in one form, their conversion and
 * their deletion.
 *
 * **The only place where the sheet is written.** It had two forms, the
 * list's and the one of a space's Informations tab, which did not carry the
 * same fields; all of them live here, and the save goes through the same
 * route, with the same rules.
 *
 * @param {object} options
 * @param {object} options.customer    the sheet as the server returns it
 * @param {string} options.indexPath   the list, returned to after a deletion
 * @param {string} options.updatePath  the save of this sheet
 * @param {string} options.convertPath template carrying `__id__`
 * @param {string} options.deletePath  template carrying `__id__`
 */
export function useCustomerPage({
    customer: initial,
    indexPath,
    updatePath,
    convertPath,
    deletePath,
}) {
    const { t } = useI18n();

    /** The saved sheet: the header and the title read it, not the input. */
    const customer = ref(initial);
    const form = ref(customerFormFrom(initial));

    const dirty = computed(
        () =>
            JSON.stringify(form.value) !==
            JSON.stringify(customerFormFrom(customer.value)),
    );

    const {
        errors,
        loading: saving,
        submit: save,
    } = useFormAction({
        rules: () => customerFormRules(t, form),
        url: () => updatePath,
        body: () => form.value,
        onSuccess: (data) => {
            // Read again from the response: a SIRET's digits are normalized
            // on the way, and the page must show what the database has.
            if (data.customer) {
                customer.value = data.customer;
                form.value = customerFormFrom(data.customer);
            }
            toast.success(t("suite.studio.customers.updated"));
        },
    });

    /**
     * Leaving the page with input in progress asks first.
     *
     * A whole sheet takes several minutes to fill in, and a click on the
     * breadcrumb lost them without a word.
     */
    let leaving = false;

    function onBeforeUnload(event) {
        if (leaving || !dirty.value) return;

        event.preventDefault();
        event.returnValue = "";
    }

    onMounted(() => window.addEventListener("beforeunload", onBeforeUnload));
    onBeforeUnmount(() =>
        window.removeEventListener("beforeunload", onBeforeUnload),
    );

    function leaveFor(href) {
        leaving = true;
        window.location.href = href;
    }

    const {
        pendingDelete,
        loading: deleteLoading,
        confirm: confirmDelete,
        submit: doDelete,
    } = useDelete(
        deletePath,
        () => {
            // The page has no purpose left: back to the list, and the message
            // follows there rather than disappearing with this page.
            queueFlash("success", t("suite.studio.customers.deleted"));
            leaveFor(indexPath);
        },
        "suite.studio.customers.deleted",
    );

    /**
     * Convert from the page.
     *
     * The route answers with the whole list; the page reads its own sheet
     * from it, and only takes what the conversion changes - the status and
     * the address - so as not to erase input in progress in the rest of the
     * form.
     */
    const conversion = useProspectConversion(convertPath, (data) => {
        const converted = (data?.customers ?? []).find(
            (row) => row.id === customer.value.id,
        );

        if (!converted) return;

        // The list rows also carry their spaces and contracts, which the page
        // reads elsewhere: only the sheet's fields are taken.
        const fields = { ...converted };
        delete fields.spaces;
        delete fields.contracts;
        customer.value = { ...customer.value, ...fields };
        form.value = {
            ...form.value,
            status: converted.status,
            contractualEmail: converted.contractualEmail ?? "",
        };
    });

    function openConversion() {
        conversion.open(customer.value, {
            id: customer.value.id,
            name: customer.value.legalName,
            email:
                form.value.contractualEmail || customer.value.contractualEmail,
        });
    }

    return {
        customer,
        form,
        dirty,
        errors,
        saving,
        save,
        pendingDelete,
        deleteLoading,
        confirmDelete,
        doDelete,
        conversion,
        openConversion,
    };
}
