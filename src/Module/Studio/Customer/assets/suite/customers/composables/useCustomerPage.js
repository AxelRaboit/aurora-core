import { computed, onBeforeUnmount, onMounted, ref } from "vue";
import { useI18n } from "vue-i18n";
import { toast } from "vue-sonner";
import { useFormAction } from "@/shared/composables/form/useFormAction.js";
import { useDelete } from "@/shared/composables/form/useDelete.js";
import { queueFlash } from "@/shared/utils/flash.js";
import { useProspectConversion } from "./useProspectConversion.js";
import { customerFormFrom, customerFormRules } from "./customerFormModel.js";

/**
 * La page d'un client : sa fiche entière en un formulaire, sa conversion et sa
 * suppression.
 *
 * **Le seul endroit où la fiche s'écrit.** Elle avait deux formulaires, celui
 * de la liste et celui de l'onglet Informations d'un espace, qui ne portaient
 * pas les mêmes champs ; tous vivent ici, et l'enregistrement passe par la
 * même route, avec les mêmes règles.
 *
 * @param {object} options
 * @param {object} options.customer    la fiche telle que le serveur la rend
 * @param {string} options.indexPath   la liste, où l'on revient après une suppression
 * @param {string} options.updatePath  l'enregistrement de cette fiche
 * @param {string} options.convertPath gabarit portant `__id__`
 * @param {string} options.deletePath  gabarit portant `__id__`
 */
export function useCustomerPage({
    customer: initial,
    indexPath,
    updatePath,
    convertPath,
    deletePath,
}) {
    const { t } = useI18n();

    /** La fiche enregistrée : l'entête et le titre la lisent, pas la saisie. */
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
            // Relue depuis la réponse : les chiffres d'un SIRET sont
            // normalisés en chemin, et la page doit montrer ce que la base a.
            if (data.customer) {
                customer.value = data.customer;
                form.value = customerFormFrom(data.customer);
            }
            toast.success(t("suite.studio.customers.updated"));
        },
    });

    /**
     * Quitter la page avec une saisie en cours le demande d'abord.
     *
     * Une fiche entière se remplit en plusieurs minutes, et un clic sur le
     * fil d'Ariane les perdait sans un mot.
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
            // La page n'a plus d'objet : retour à la liste, et le message
            // l'y suit plutôt que de disparaître avec cette page.
            queueFlash("success", t("suite.studio.customers.deleted"));
            leaveFor(indexPath);
        },
        "suite.studio.customers.deleted",
    );

    /**
     * Convertir depuis la page.
     *
     * La route répond avec la liste entière ; la page y relit sa propre fiche,
     * et ne reprend que ce que la conversion change - le statut et l'adresse -
     * pour ne pas effacer une saisie en cours dans le reste du formulaire.
     */
    const conversion = useProspectConversion(convertPath, (data) => {
        const converted = (data?.customers ?? []).find(
            (row) => row.id === customer.value.id,
        );

        if (!converted) return;

        // Les lignes de la liste portent aussi ses espaces et ses contrats, que
        // la page lit ailleurs : seuls les champs de la fiche sont repris.
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
