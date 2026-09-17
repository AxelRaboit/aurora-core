import { computed, ref } from "vue";
import { useI18n } from "vue-i18n";
import { toast } from "vue-sonner";
import { buildPath } from "@/shared/utils/http/buildPath.js";
import { useFormAction } from "@/shared/composables/form/useFormAction.js";
import { useDelete } from "@/shared/composables/form/useDelete.js";
import { useClientFilteredList } from "@/shared/composables/list/useClientFilteredList.js";
import { required } from "@/shared/utils/validation/validators.js";
import { COLOUR_SLOTS } from "@/shared/composables/chart/paletteSlots.js";

/**
 * One form shape for create and edit, because the fields are the same either
 * way. `colourSlot` is the exception and it is empty on purpose when creating:
 * an empty slot is what tells the server to spread the new space across the
 * palette instead of handing every space the colour of the first one.
 */
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
        timezone: "Europe/Paris",
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
        timezone: space.timezone ?? "Europe/Paris",
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

    const visibleItems = computed(() =>
        showArchived.value
            ? filteredItems.value
            : filteredItems.value.filter((space) => !space.archived),
    );

    const archivedCount = computed(
        () => items.value.filter((space) => space.archived).length,
    );

    function applyUpdatedList(data) {
        if (Array.isArray(data?.spaces)) items.value = data.spaces;
    }

    function rulesFor(form) {
        return {
            name: () =>
                required(t("backend.studio.spaces.errors.name_required"))(
                    form.value.name,
                ),
            // L'un ou l'autre : une societe deja connue, ou le nom d'un
            // prospect qu'on ouvre en meme temps que l'espace. La regle porte
            // sur la paire, donc elle est signalee sous le selecteur - c'est
            // la que le lecteur choisit entre les deux.
            customerId: () =>
                form.value.customerId || form.value.prospectName
                    ? null
                    : t("backend.studio.spaces.errors.customer_required"),
            prospectEmail: () =>
                form.value.prospectName && !form.value.prospectEmail
                    ? t(
                          "backend.studio.customers.errors.contractual_email_required",
                      )
                    : null,
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
            toast.success(t("backend.studio.spaces.created"));
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
            toast.success(t("backend.studio.spaces.updated"));
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
        "backend.studio.spaces.deleted",
    );

    return {
        items,
        search,
        visibleItems,
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
