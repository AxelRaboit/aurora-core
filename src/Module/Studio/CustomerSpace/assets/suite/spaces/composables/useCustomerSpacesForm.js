import { computed, ref } from "vue";
import { useI18n } from "vue-i18n";
import { toast } from "vue-sonner";
import { useFormAction } from "@/shared/composables/form/useFormAction.js";
import { useDelete } from "@/shared/composables/form/useDelete.js";
import { useClientFilteredList } from "@/shared/composables/list/useClientFilteredList.js";
import { usePersistedChoice } from "@/shared/composables/usePersistedChoice.js";
import { required } from "@/shared/utils/validation/validators.js";
import { COLOUR_SLOTS } from "@/shared/composables/chart/paletteSlots.js";
import { siteZone } from "@/shared/utils/format/zonedTime.js";

/**
 * One form shape for create (the list's modal) and edit (the space's Settings
 * tab, see `useSpaceSettingsForm`), because the fields are the same either
 * way. `colourSlot` is the exception and it is empty on purpose when creating:
 * an empty slot is what tells the server to spread the new space across the
 * palette instead of handing every space the colour of the first one.
 */
function initialCustomerFilter() {
    try {
        const id = Number(
            new URL(window.location.href).searchParams.get("customer"),
        );

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
        // Filled in instead of customerId when a space is opened for someone
        // who has no sheet yet.
        prospectName: "",
        prospectEmail: "",
        status: "active",
        colourSlot: "",
        timezone: siteZone() ?? "Europe/Paris",
        members: [],
    };
}

/**
 * The rules the space form checks before sending, for the creation modal and
 * the Settings tab alike.
 *
 * @param {import("vue").Ref<object>} form
 * @param {Function} t
 */
export function spaceFormRules(form, t) {
    return {
        name: () =>
            required(t("suite.studio.spaces.errors.name_required"))(
                form.value.name,
            ),
        // One or the other: an already known company, or the name of a
        // prospect opened at the same time as the space. The rule covers the
        // pair, so it is reported under the selector - that is where the
        // reader chooses between the two.
        customerId: () =>
            form.value.customerId || form.value.prospectName
                ? null
                : t("suite.studio.spaces.errors.customer_required"),
    };
}

/** The form for an existing space, as its serializer hands it over. */
export function formFrom(space) {
    return {
        name: space.name ?? "",
        description: space.description ?? "",
        customerId: space.customerId ?? "",
        prospectName: "",
        prospectEmail: "",
        status: space.status ?? "active",
        colourSlot: space.colourSlot ?? "",
        timezone: space.timezone ?? siteZone() ?? "Europe/Paris",
        // Copied rather than referenced: the form is edited before it is
        // sent, and mutating the space it came from would change the screen
        // before anything is saved.
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
     * Customers or prospects, never both.
     *
     * Remembered from one visit to the next, like a space's views: "what I am
     * working on" and "what I am trying to land" are not read in the same
     * minute, and nobody wants to choose again on every return.
     */
    const { choice: tab } = usePersistedChoice("studio.spaces.tab", "client", [
        "client",
        "prospect",
    ]);

    /**
     * The spaces of a single company, when arriving from its sheet.
     *
     * By id and not through the search: searching its company name also
     * brought back every one that contains it. And the tab follows the
     * company, otherwise a prospect opened from the remembered "clients" tab
     * landed on an empty list.
     */
    const customerFilter = ref(initialCustomerFilter());
    const filteredCustomer = computed(() =>
        null === customerFilter.value
            ? null
            : (customers.value.find(
                  (customer) => customer.id === customerFilter.value,
              ) ??
              (items.value ?? [])
                  .map((space) => ({
                      id: space.customerId,
                      name: space.customerName,
                  }))
                  .find((customer) => customer.id === customerFilter.value) ??
              null),
    );

    if (null !== customerFilter.value) {
        const first = (items.value ?? []).find(
            (space) => space.customerId === customerFilter.value,
        );
        if (first) tab.value = first.customerStatus ?? "client";
    }

    function clearCustomerFilter() {
        customerFilter.value = null;
        try {
            const url = new URL(window.location.href);
            url.searchParams.delete("customer");
            window.history.replaceState(window.history.state, "", url);
        } catch {
            // The address stays as is: the filter is lifted within the page.
        }
    }

    const ofCustomer = computed(() =>
        null === customerFilter.value
            ? filteredItems.value
            : filteredItems.value.filter(
                  (space) => space.customerId === customerFilter.value,
              ),
    );

    /** The filters compose: the company, the tab, then the archives. */
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
            // The count ignores the archives, like the label it sits on: it
            // says how many things are behind this tab, not how many are
            // shown.
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

    const rulesFor = (form) => spaceFormRules(form, t);

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
        pendingDelete,
        deleteLoading,
        confirmDelete,
        doDelete,
    };
}
