import { computed, ref } from "vue";
import { useI18n } from "vue-i18n";
import { toast } from "vue-sonner";
import { buildPath } from "@/shared/utils/http/buildPath.js";
import { useFormAction } from "@/shared/composables/form/useFormAction.js";
import { useDelete } from "@/shared/composables/form/useDelete.js";
import { useClientFilteredList } from "@/shared/composables/list/useClientFilteredList.js";
import { usePersistedChoice } from "@/shared/composables/usePersistedChoice.js";
import { required } from "@/shared/utils/validation/validators.js";
import { COLOUR_SLOTS } from "@/shared/composables/chart/paletteSlots.js";
import { siteZone } from "@/shared/utils/format/zonedTime.js";

/**
 * One form shape for create and edit, because the fields are the same either
 * way. `colourSlot` is the exception and it is empty on purpose when creating:
 * an empty slot is what tells the server to spread the new space across the
 * palette instead of handing every space the colour of the first one.
 */
function initialCustomerFilter() {
    try {
        const id = Number(new URL(window.location.href).searchParams.get("customer"));

        return Number.isInteger(id) && id > 0 ? id : null;
    } catch {
        return null;
    }
}

function emptyForm() {
    return {
        name: "",
        description: "",
        customerId: "",
        // Remplis a la place de customerId quand on ouvre un espace pour
        // quelqu'un dont on n'a pas encore de fiche.
        prospectName: "",
        prospectEmail: "",
        status: "active",
        colourSlot: "",
        timezone: siteZone() ?? "Europe/Paris",
        members: [],
    };
}

function formFrom(space) {
    return {
        name: space.name ?? "",
        description: space.description ?? "",
        customerId: space.customerId ?? "",
        prospectName: "",
        prospectEmail: "",
        status: space.status ?? "active",
        colourSlot: space.colourSlot ?? "",
        timezone: space.timezone ?? siteZone() ?? "Europe/Paris",
        // Copied rather than referenced: the form is edited before it is sent,
        // and mutating the row in the list would move the table under the
        // reader while a modal is open over it.
        members: (space.members ?? []).map((member) => ({
            userId: member.userId,
            role: member.role,
        })),
    };
}

export { COLOUR_SLOTS };

export function useCustomerSpacesForm(
    initialSpaces,
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
    } = useClientFilteredList(initialSpaces, null, (space, query) =>
        // The two things somebody remembers about a space: what they called it
        // and who it is for.
        [space.name, space.customerName]
            .filter(Boolean)
            .some((field) => String(field).toLowerCase().includes(query)),
    );

    const customers = ref(initialCustomers ?? []);
    const users = ref(initialUsers ?? []);

    const customerOptions = computed(() =>
        customers.value.map((customer) => ({
            value: String(customer.id),
            label: customer.name,
        })),
    );

    const userById = computed(
        () => new Map(users.value.map((user) => [user.id, user])),
    );

    /**
     * Archived spaces are off by default and one switch away.
     *
     * A filter in the page rather than a second request: the list is already
     * here in full, so hiding rows costs nothing and showing them again does
     * not wait on the network.
     */
    const showArchived = ref(false);

    /**
     * Clients ou prospects, jamais les deux.
     *
     * Retenu d'une visite a l'autre, comme les vues d'un espace : « ce sur quoi
     * je travaille » et « ce que j'essaie de decrocher » ne se lisent pas dans
     * la meme minute, et personne ne veut rechoisir a chaque retour.
     */
    const { choice: tab } = usePersistedChoice("studio.spaces.tab", "client", [
        "client",
        "prospect",
    ]);

    /**
     * Les espaces d'une seule société, quand on arrive de sa fiche.
     *
     * Par identifiant et non par la recherche : chercher sa raison sociale
     * ramenait aussi toutes celles qui la contiennent. Et l'onglet suit la
     * société, sans quoi un prospect ouvert depuis l'onglet « clients » retenu
     * tombait sur une liste vide.
     */
    const customerFilter = ref(initialCustomerFilter());
    const filteredCustomer = computed(() =>
        null === customerFilter.value
            ? null
            : (customers.value.find((customer) => customer.id === customerFilter.value) ??
              (items.value ?? []).map((space) => ({ id: space.customerId, name: space.customerName }))
                  .find((customer) => customer.id === customerFilter.value) ??
              null),
    );

    if (null !== customerFilter.value) {
        const first = (items.value ?? []).find((space) => space.customerId === customerFilter.value);
        if (first) tab.value = first.customerStatus ?? "client";
    }

    function clearCustomerFilter() {
        customerFilter.value = null;
        try {
            const url = new URL(window.location.href);
            url.searchParams.delete("customer");
            window.history.replaceState(window.history.state, "", url);
        } catch {
            // L'adresse reste telle quelle : le filtre est levé dans la page.
        }
    }

    const ofCustomer = computed(() =>
        null === customerFilter.value
            ? filteredItems.value
            : filteredItems.value.filter((space) => space.customerId === customerFilter.value),
    );

    /** Les filtres se composent : la société, l'onglet, puis les archives. */
    const ofTab = computed(() =>
        ofCustomer.value.filter(
            (space) => (space.customerStatus ?? "client") === tab.value,
        ),
    );

    const visibleItems = computed(() =>
        showArchived.value
            ? ofTab.value
            : ofTab.value.filter((space) => !space.archived),
    );

    const tabs = computed(() =>
        ["client", "prospect"].map((key) => ({
            key,
            // Le compte ignore les archives, comme l'etiquette qu'il porte :
            // il dit combien il y a de choses derriere cet onglet, pas combien
            // on en montre.
            count: ofCustomer.value.filter(
                (space) => (space.customerStatus ?? "client") === key,
            ).length,
        })),
    );

    const archivedCount = computed(
        () => ofTab.value.filter((space) => space.archived).length,
    );

    function applyUpdatedList(data) {
        if (Array.isArray(data?.spaces)) items.value = data.spaces;
    }

    function rulesFor(form) {
        return {
            name: () =>
                required(t("suite.studio.spaces.errors.name_required"))(
                    form.value.name,
                ),
            // L'un ou l'autre : une societe deja connue, ou le nom d'un
            // prospect qu'on ouvre en meme temps que l'espace. La regle porte
            // sur la paire, donc elle est signalee sous le selecteur - c'est
            // la que le lecteur choisit entre les deux.
            customerId: () =>
                form.value.customerId || form.value.prospectName
                    ? null
                    : t("suite.studio.spaces.errors.customer_required"),
        };
    }

    const showCreate = ref(false);
    const newSpace = ref(emptyForm());

    const {
        errors: createErrors,
        loading: createLoading,
        submit: submitCreate,
        clearErrors: clearCreate,
    } = useFormAction({
        rules: () => rulesFor(newSpace),
        url: () => createPath,
        body: () => newSpace.value,
        onSuccess: (data) => {
            showCreate.value = false;
            toast.success(t("suite.studio.spaces.created"));
            applyUpdatedList(data);
        },
    });

    function openCreate() {
        newSpace.value = emptyForm();
        clearCreate();
        showCreate.value = true;
    }

    const showEdit = ref(false);
    const editingSpace = ref(null);
    const editForm = ref(emptyForm());

    const {
        errors: editErrors,
        loading: editLoading,
        submit: submitEdit,
        clearErrors: clearEdit,
    } = useFormAction({
        rules: () => rulesFor(editForm),
        url: () => buildPath(updatePath, { id: editingSpace.value.id }),
        body: () => editForm.value,
        onSuccess: (data) => {
            showEdit.value = false;
            toast.success(t("suite.studio.spaces.updated"));
            applyUpdatedList(data);
        },
    });

    function openEdit(space) {
        editingSpace.value = space;
        editForm.value = formFrom(space);
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
            items.value = items.value.filter((space) => space.id !== id);
        },
        "suite.studio.spaces.deleted",
    );

    return {
        items,
        search,
        filteredCustomer,
        clearCustomerFilter,
        visibleItems,
        tab,
        tabs,
        showArchived,
        archivedCount,
        customerOptions,
        users,
        userById,
        showCreate,
        newSpace,
        createErrors,
        createLoading,
        openCreate,
        submitCreate,
        showEdit,
        editingSpace,
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
