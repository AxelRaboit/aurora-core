import { ref, reactive, computed } from "vue";
import { useI18n } from "vue-i18n";
import { toast } from "vue-sonner";
import { buildPath } from "@/shared/utils/http/buildPath.js";
import { useRequest } from "@/shared/composables/http/backend/useRequest.js";

export function useUsersEdit(props, fetchUsers, options = {}) {
    const { t } = useI18n();
    const { request } = useRequest();
    const extraFields = options.extraFields ?? {};

    const selectableUsers = ref([]);

    async function loadSelectableUsers() {
        if (selectableUsers.value.length) return;
        const data = await request(props.selectablePath, null, {
            method: "GET",
            noGuard: true,
        });
        selectableUsers.value = data?.success ? data.items : [];
    }

    const editModal = reactive({
        open: false,
        editing: null,
        errors: {},
        saving: false,
        photoUploading: false,
    });
    const editForm = reactive({
        name: "",
        email: "",
        role: "",
        password: "",
        managerId: null,
        ...Object.fromEntries(
            Object.entries(extraFields).map(([key, def]) => [key, def.default]),
        ),
    });

    const managerOptions = computed(() => {
        const editingId = editModal.editing?.id ?? 0;
        return [
            { value: "", label: "-" },
            ...selectableUsers.value
                .filter((user) => user.id !== editingId)
                .map((user) => ({ value: String(user.id), label: user.name })),
        ];
    });

    function openEdit(user) {
        editModal.editing = user;
        editModal.errors = {};
        editForm.name = user.name;
        editForm.email = user.email;
        editForm.role = user.role ?? props.roles[0]?.value ?? "";
        editForm.password = "";
        editForm.managerId = user.managerId ? String(user.managerId) : "";
        for (const [key, def] of Object.entries(extraFields)) {
            editForm[key] = def.fromEntity
                ? def.fromEntity(user)
                : (user[key] ?? def.default);
        }
        editModal.open = true;
        loadSelectableUsers();
    }

    async function onPhotoSelected(file) {
        if (!file || !editModal.editing) return;
        editModal.photoUploading = true;
        const formData = new FormData();
        formData.append("photo", file);
        const url = buildPath(props.photoUploadPath, {
            id: editModal.editing.id,
        });
        const data = await request(url, null, { rawBody: formData });
        editModal.photoUploading = false;
        if (!data) return;
        if (!data.success) {
            const message =
                data.errors?.photo ?? data.error ?? "shared.common.error";
            toast.error(t(message));
            return;
        }
        editModal.editing = data.user;
        toast.success(t("backend.users.photo.uploaded"));
        fetchUsers();
    }

    async function removePhoto() {
        if (!editModal.editing) return;
        editModal.photoUploading = true;
        const url = buildPath(props.photoDeletePath, {
            id: editModal.editing.id,
        });
        const data = await request(url);
        editModal.photoUploading = false;
        if (!data) return;
        if (!data.success) {
            toast.error(t(data.error ?? "shared.common.error"));
            return;
        }
        editModal.editing = data.user;
        toast.success(t("backend.users.photo.removed"));
        fetchUsers();
    }

    async function submitEdit() {
        if (!editModal.editing) return;
        editModal.saving = true;
        editModal.errors = {};
        const url = buildPath(props.updatePath, {
            id: editModal.editing.id,
        });
        const payload = {
            ...editForm,
            managerId: editForm.managerId ? Number(editForm.managerId) : null,
        };
        const data = await request(url, payload);
        editModal.saving = false;
        if (!data) return;
        if (!data.success) {
            editModal.errors = data.errors ?? {};
            return;
        }
        toast.success(t("shared.common.saved"));
        editModal.open = false;
        fetchUsers();
    }

    return {
        editModal,
        editForm,
        managerOptions,
        openEdit,
        onPhotoSelected,
        removePhoto,
        submitEdit,
    };
}
