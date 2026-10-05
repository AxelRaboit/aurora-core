import { ref, computed } from "vue";
import { buildPath } from "@/shared/utils/http/buildPath.js";
import { HttpMethod } from "@/shared/utils/http/httpMethod.js";
import { useI18n } from "vue-i18n";
import { useFormAction } from "@/shared/composables/form/useFormAction.js";
import { useRequest } from "@/shared/composables/http/suite/useRequest.js";
import { submitForm } from "@/shared/utils/http/formSubmit.js";
import {
    required,
    email,
    compose,
} from "@/shared/utils/validation/validators.js";
import { Locale } from "@/shared/utils/lang.js";

const DEFAULT_LOCALE = Locale.Fr;

export function useUsers(
    usersPath,
    userCreatePath,
    userUpdatePath,
    userToggleRolePath,
    userDeletePath,
    impersonatePath,
    csrfToken,
    initialUsers,
    initialSearch,
) {
    const { t } = useI18n();

    const usersData = ref(initialUsers ?? { items: [] });
    const parsedUsers = computed(() => usersData.value ?? { items: [] });

    const searchInput = ref(initialSearch);
    const { loading, request: loadRequest } = useRequest();

    async function load() {
        const url = new URL(usersPath, window.location.origin);
        if (searchInput.value)
            url.searchParams.set("search", searchInput.value);
        const data = await loadRequest(url.toString(), null, HttpMethod.Get);
        if (data !== null) {
            usersData.value = data;
        }
    }

    function performSearch() {
        load();
    }

    const showCreateModal = ref(false);
    const newUser = ref({
        name: "",
        email: "",
        password: "",
        locale: DEFAULT_LOCALE,
    });

    const {
        errors: createErrors,
        loading: createLoading,
        submit: submitCreate,
        clearErrors: clearCreateErrors,
    } = useFormAction({
        rules: () => ({
            name: () =>
                required(t("suite.profile.errors.name_required"))(
                    newUser.value.name,
                ),
            email: () =>
                compose(
                    required(t("suite.profile.errors.email_invalid")),
                    email(t("suite.profile.errors.email_invalid")),
                )(newUser.value.email),
            password: () => {
                if (
                    !newUser.value.password ||
                    newUser.value.password.length < 8
                )
                    return t("suite.profile.errors.password_too_short");
                return null;
            },
        }),
        url: () => userCreatePath,
        body: () => newUser.value,
        onSuccess: () => {
            window.location.reload();
        },
    });

    function openCreate() {
        showCreateModal.value = true;
        newUser.value = {
            name: "",
            email: "",
            password: "",
            locale: DEFAULT_LOCALE,
        };
        clearCreateErrors();
    }

    const showEditModal = ref(false);
    const editingUser = ref(null);
    const editUserForm = ref({
        name: "",
        email: "",
        password: "",
        locale: DEFAULT_LOCALE,
    });

    const {
        errors: editErrors,
        loading: editLoading,
        submit: submitEdit,
        clearErrors: clearEditErrors,
    } = useFormAction({
        rules: () => ({
            name: () =>
                required(t("suite.profile.errors.name_required"))(
                    editUserForm.value.name,
                ),
            email: () =>
                compose(
                    required(t("suite.profile.errors.email_invalid")),
                    email(t("suite.profile.errors.email_invalid")),
                )(editUserForm.value.email),
            password: () => {
                if (
                    editUserForm.value.password &&
                    editUserForm.value.password.length < 8
                )
                    return t("suite.profile.errors.password_too_short");
                return null;
            },
        }),
        url: () => buildPath(userUpdatePath, { id: editingUser.value.id }),
        body: () => editUserForm.value,
        onSuccess: () => {
            window.location.reload();
        },
    });

    function openEdit(user) {
        editingUser.value = user;
        editUserForm.value = {
            name: user.name,
            email: user.email,
            password: "",
            locale: user.locale ?? DEFAULT_LOCALE,
        };
        clearEditErrors();
        showEditModal.value = true;
    }

    function closeEdit() {
        showEditModal.value = false;
        editingUser.value = null;
    }

    async function submitEditGuarded() {
        if (!editingUser.value) return;
        await submitEdit();
    }

    const pendingDelete = ref(null);

    function confirmDelete(user) {
        pendingDelete.value = user;
    }

    function doDelete() {
        if (!pendingDelete.value) return;
        const url = buildPath(userDeletePath, { id: pendingDelete.value.id });
        submitForm(url, csrfToken);
        pendingDelete.value = null;
    }

    const pendingToggleRole = ref(null);

    function confirmToggleRole(user) {
        pendingToggleRole.value = user;
    }

    function doToggleRole() {
        if (!pendingToggleRole.value) return;
        const url = userToggleRolePath.replace(
            "__id__",
            pendingToggleRole.value.id,
        );
        submitForm(url, csrfToken);
        pendingToggleRole.value = null;
    }

    return {
        parsedUsers,
        loading,
        load,
        searchInput,
        performSearch,
        showCreateModal,
        newUser,
        createLoading,
        createErrors,
        openCreate,
        submitCreate,
        showEditModal,
        editingUser,
        editUserForm,
        editLoading,
        editErrors,
        openEdit,
        closeEdit,
        submitEdit: submitEditGuarded,
        pendingDelete,
        confirmDelete,
        doDelete,
        pendingToggleRole,
        confirmToggleRole,
        doToggleRole,
        impersonatePath,
    };
}
