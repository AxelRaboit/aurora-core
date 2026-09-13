import { computed, ref } from "vue";
import { useI18n } from "vue-i18n";
import { toast } from "vue-sonner";
import { buildPath } from "@/shared/utils/http/buildPath.js";
import { useFormAction } from "@/shared/composables/form/useFormAction.js";
import { useDelete } from "@/shared/composables/form/useDelete.js";
import { useRequest } from "@/shared/composables/http/backend/useRequest.js";
import { useClientFilteredList } from "@/shared/composables/list/useClientFilteredList.js";
import { required } from "@/shared/utils/validation/validators.js";

/**
 * One shape for create and edit: the fields of a deck are the same the first
 * time and the tenth. What differs is only where the answer is sent.
 */
function emptyForm() {
    return { title: "", description: "", categoryId: "", customerId: "" };
}

function formFrom(deck) {
    return {
        title: deck.title ?? "",
        description: deck.description ?? "",
        categoryId: deck.category?.id ? String(deck.category.id) : "",
        customerId: deck.customer?.id ? String(deck.customer.id) : "",
    };
}

/** The form speaks strings, the API speaks ids or nothing. */
function toPayload(form) {
    return {
        title: form.title,
        description: form.description || null,
        categoryId: form.categoryId ? Number(form.categoryId) : null,
        customerId: form.customerId ? Number(form.customerId) : null,
    };
}

export function useDecksList(props) {
    const { t } = useI18n();
    const { request } = useRequest();

    const {
        items,
        searchInput: search,
        filteredItems: searched,
    } = useClientFilteredList(props.decks, null, (deck, query) =>
        [deck.title, deck.description, deck.customer?.legalName]
            .filter(Boolean)
            .some((field) => String(field).toLowerCase().includes(query)),
    );

    const categories = ref(props.categories ?? []);

    /**
     * The category filter sits beside the search rather than inside it.
     *
     * Typing a category name would match decks that merely mention it in their
     * description, which is not what somebody clicking a category means.
     */
    const categoryFilter = ref("");

    const filteredItems = computed(() => {
        if (!categoryFilter.value) return searched.value;

        return searched.value.filter(
            (deck) => String(deck.category?.id ?? "") === categoryFilter.value,
        );
    });

    const categoryOptions = computed(() =>
        categories.value.map((category) => ({
            value: String(category.id),
            label: category.name,
        })),
    );

    const customerOptions = computed(() =>
        (props.customers ?? []).map((customer) => ({
            value: String(customer.id),
            label: customer.legalName,
        })),
    );

    function rulesFor(form) {
        return {
            title: () =>
                required(t("backend.studio.decks.errors.title_required"))(
                    form.value.title,
                ),
        };
    }

    /**
     * A deck written into the list, replacing the row of the same id.
     *
     * The server answers with the deck it just saved rather than the whole
     * list: the list is the caller's and it knows where the row goes, while a
     * full list on every keystroke of a title would be the table sent back
     * thirty times for one changed cell.
     */
    function upsert(deck) {
        if (!deck?.id) return;

        const at = items.value.findIndex((row) => row.id === deck.id);

        if (at === -1) items.value = [deck, ...items.value];
        else items.value = items.value.toSpliced(at, 1, deck);
    }

    const showCreate = ref(false);
    const newDeck = ref(emptyForm());

    const {
        errors: createErrors,
        loading: createLoading,
        submit: submitCreate,
        clearErrors: clearCreate,
    } = useFormAction({
        rules: () => rulesFor(newDeck),
        url: () => props.createPath,
        body: () => toPayload(newDeck.value),
        onSuccess: (data) => {
            showCreate.value = false;
            toast.success(t("backend.studio.decks.created"));
            upsert(data?.deck);
        },
    });

    function openCreate() {
        newDeck.value = emptyForm();
        clearCreate();
        showCreate.value = true;
    }

    /**
     * A written document, in as a deck.
     *
     * The blocks are sent as they are and converted on the server, which is
     * where the rule lives. On success the page goes straight to the new deck:
     * an import is not finished when the row appears, it is finished when
     * somebody has seen what it made of their document.
     */
    const showImport = ref(false);
    const importDeck = ref(emptyForm());
    const importBlocks = ref([]);

    const {
        errors: importErrors,
        loading: importLoading,
        submit: submitImport,
        clearErrors: clearImport,
    } = useFormAction({
        rules: () => rulesFor(importDeck),
        url: () => props.importPath,
        body: () => ({
            ...toPayload(importDeck.value),
            blocks: importBlocks.value,
        }),
        onSuccess: (data) => {
            showImport.value = false;
            toast.success(t("backend.studio.decks.imported"));

            if (data?.deck?.id) {
                window.location.assign(
                    buildPath(props.showPath, { id: data.deck.id }),
                );
            }
        },
    });

    function openImport() {
        importDeck.value = emptyForm();
        importBlocks.value = [];
        clearImport();
        showImport.value = true;
    }

    const showEdit = ref(false);
    const editingDeck = ref(null);
    const editForm = ref(emptyForm());

    const {
        errors: editErrors,
        loading: editLoading,
        submit: submitEdit,
        clearErrors: clearEdit,
    } = useFormAction({
        rules: () => rulesFor(editForm),
        url: () => buildPath(props.updatePath, { id: editingDeck.value?.id }),
        body: () => toPayload(editForm.value),
        onSuccess: (data) => {
            showEdit.value = false;
            toast.success(t("backend.studio.decks.updated"));
            upsert(data?.deck);
        },
    });

    function openEdit(deck) {
        editingDeck.value = deck;
        editForm.value = formFrom(deck);
        clearEdit();
        showEdit.value = true;
    }

    const {
        pendingDelete,
        loading: deleteLoading,
        confirm: confirmDelete,
        submit: doDelete,
    } = useDelete(
        props.deletePath,
        (id) => {
            items.value = items.value.filter((row) => row.id !== id);
        },
        "backend.studio.decks.deleted",
    );

    const duplicatingId = ref(null);

    /**
     * Duplicate, and put the copy at the top.
     *
     * Not a `useFormAction`: there is no form and nothing to validate, only a
     * button that asks the server to make a copy.
     */
    async function duplicate(deck) {
        duplicatingId.value = deck.id;

        try {
            const data = await request(
                buildPath(props.duplicatePath, { id: deck.id }),
            );

            if (data?.deck) {
                upsert(data.deck);
                toast.success(t("backend.studio.decks.duplicated"));
            }
        } finally {
            duplicatingId.value = null;
        }
    }

    return {
        search,
        categoryFilter,
        filteredItems,
        categories,
        categoryOptions,
        customerOptions,
        showCreate,
        newDeck,
        createErrors,
        createLoading,
        openCreate,
        submitCreate,
        showImport,
        importDeck,
        importBlocks,
        importErrors,
        importLoading,
        openImport,
        submitImport,
        showEdit,
        editingDeck,
        editForm,
        editErrors,
        editLoading,
        openEdit,
        submitEdit,
        pendingDelete,
        deleteLoading,
        confirmDelete,
        doDelete,
        duplicatingId,
        duplicate,
    };
}
