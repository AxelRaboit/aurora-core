import { ref, computed } from "vue";
import { useI18n } from "vue-i18n";
import { toast } from "vue-sonner";
import { HttpMethod } from "@/shared/utils/http/httpMethod.js";
import { useServerErrors } from "@/shared/composables/form/useServerErrors.js";
import { useRequest } from "@/shared/composables/http/backend/useRequest.js";
import { required } from "@/shared/utils/validation/validators.js";

export function useMountPoints(
    mountPointsPath,
    mountPointCreatePath,
    mountPointUpdatePath,
    mountPointDeletePath,
    mountPointTestPath,
    initialData,
) {
    const { t } = useI18n();
    const { request } = useRequest();

    const mountPoints = ref(initialData?.mountPoints ?? []);
    const types = ref(initialData?.types ?? []);
    const searchInput = ref("");
    const saving = ref(false);

    const { loading, request: loadRequest } = useRequest();
    const { request: createRequest } = useRequest();
    const { request: editRequest } = useRequest();
    const { request: deleteRequest } = useRequest();

    const testModal = ref({
        show: false,
        testing: false,
        mountPoint: null,
        result: null,
    });
    let testAbortController = null;

    const showCreateModal = ref(false);
    const showEditModal = ref(false);
    const showDeleteModal = ref(false);
    const editingMountPoint = ref(null);
    const pendingDelete = ref(null);

    const emptyForm = () => ({
        name: "",
        type: "database",
        host: "",
        port: "",
        username: "",
        password: "",
        database: "",
        sshPublicKey: "",
        sshPrivateKey: "",
        config: { sshTunnel: false, sshHost: "", sshPort: 22, sshUser: "" },
    });

    const createForm = ref(emptyForm());
    const {
        errors: createErrors,
        validate: validateCreate,
        handleErrors: handleCreateErrors,
        clearErrors: clearCreateErrors,
    } = useServerErrors();

    const editForm = ref(emptyForm());
    const {
        errors: editErrors,
        validate: validateEdit,
        handleErrors: handleEditErrors,
        clearErrors: clearEditErrors,
    } = useServerErrors();

    const filteredMountPoints = computed(() => {
        const query = searchInput.value.trim().toLowerCase();
        if (!query) return mountPoints.value;
        return mountPoints.value.filter(
            (mountPoint) =>
                mountPoint.name.toLowerCase().includes(query) ||
                mountPoint.host.toLowerCase().includes(query) ||
                mountPoint.type.toLowerCase().includes(query),
        );
    });

    async function load() {
        const data = await loadRequest(mountPointsPath, null, HttpMethod.Get);
        if (data) {
            mountPoints.value = data.mountPoints ?? [];
            types.value = data.types ?? [];
        }
    }

    function formValidationRules(form) {
        return {
            name: () =>
                required(t("backend.mount_points.errors.name_required"))(
                    form.name,
                ),
            host: () =>
                required(t("backend.mount_points.errors.host_required"))(
                    form.host,
                ),
        };
    }

    function buildPayload(form) {
        return {
            name: form.name,
            type: form.type,
            host: form.host,
            port: form.port !== "" ? Number(form.port) : null,
            username: form.username || null,
            password: form.password || null,
            database: form.database || null,
            sshPublicKey: form.sshPublicKey || null,
            sshPrivateKey: form.sshPrivateKey || null,
            config: {
                ...form.config,
                sshPort: form.config.sshPort ? Number(form.config.sshPort) : 22,
                sshHost: form.config.sshHost || null,
                sshUser: form.config.sshUser || null,
            },
        };
    }

    function openCreate() {
        createForm.value = emptyForm();
        clearCreateErrors();
        showCreateModal.value = true;
    }

    function closeCreate() {
        showCreateModal.value = false;
    }

    async function submitCreate() {
        if (saving.value) return;
        if (!validateCreate(formValidationRules(createForm.value))) return;

        saving.value = true;
        const data = await createRequest(
            mountPointCreatePath,
            buildPayload(createForm.value),
        );
        saving.value = false;

        if (!data) return;

        if (!data.success) {
            handleCreateErrors(data.errors);
            return;
        }

        mountPoints.value.push(data.mountPoint);
        mountPoints.value.sort((a, b) => a.name.localeCompare(b.name));
        showCreateModal.value = false;
        toast.success(t("shared.common.saved"));
    }

    function openEdit(mountPoint) {
        editingMountPoint.value = mountPoint;
        editForm.value = {
            name: mountPoint.name,
            type: mountPoint.type,
            host: mountPoint.host,
            port: mountPoint.port ?? "",
            username: mountPoint.username ?? "",
            password: "",
            database: mountPoint.database ?? "",
            sshPublicKey: mountPoint.sshPublicKey ?? "",
            sshPrivateKey: "",
            config: {
                sshTunnel: mountPoint.config?.sshTunnel ?? false,
                sshHost: mountPoint.config?.sshHost ?? "",
                sshPort: mountPoint.config?.sshPort ?? 22,
                sshUser: mountPoint.config?.sshUser ?? "",
            },
        };
        clearEditErrors();
        showEditModal.value = true;
    }

    function closeEdit() {
        showEditModal.value = false;
        editingMountPoint.value = null;
    }

    async function submitEdit() {
        if (saving.value || !editingMountPoint.value) return;
        if (!validateEdit(formValidationRules(editForm.value))) return;

        saving.value = true;
        const url = mountPointUpdatePath.replace(
            "__id__",
            editingMountPoint.value.id,
        );

        const data = await editRequest(
            url,
            buildPayload(editForm.value),
            HttpMethod.Patch,
        );
        saving.value = false;

        if (!data) return;

        if (!data.success) {
            handleEditErrors(data.errors);
            return;
        }

        const index = mountPoints.value.findIndex(
            (mountPoint) => mountPoint.id === data.mountPoint.id,
        );
        if (index !== -1) mountPoints.value[index] = data.mountPoint;
        mountPoints.value.sort((a, b) => a.name.localeCompare(b.name));
        showEditModal.value = false;
        editingMountPoint.value = null;
        toast.success(t("shared.common.saved"));
    }

    function confirmDelete(mountPoint) {
        pendingDelete.value = mountPoint;
        showDeleteModal.value = true;
    }

    async function doDelete() {
        if (!pendingDelete.value) return;

        const url = mountPointDeletePath.replace(
            "__id__",
            pendingDelete.value.id,
        );
        const data = await deleteRequest(url, null, HttpMethod.Delete);

        if (!data) return;

        if (!data.success) {
            toast.error(t("shared.common.error"));
            return;
        }

        mountPoints.value = mountPoints.value.filter(
            (mountPoint) => mountPoint.id !== pendingDelete.value.id,
        );
        showDeleteModal.value = false;
        pendingDelete.value = null;
        toast.success(t("shared.common.deleted"));
    }

    function openTestModal(mountPoint) {
        testModal.value = {
            show: true,
            testing: true,
            mountPoint,
            result: null,
        };
        runTest(mountPoint);
    }

    function cancelTest() {
        testAbortController?.abort();
        testModal.value = {
            show: false,
            testing: false,
            mountPoint: null,
            result: null,
        };
    }

    function closeTestModal() {
        testModal.value = {
            show: false,
            testing: false,
            mountPoint: null,
            result: null,
        };
    }

    async function runTest(mountPoint) {
        testAbortController = new AbortController();
        const url = mountPointTestPath.replace("__id__", mountPoint.id);
        try {
            // `signal` so cancelling still aborts; `noGuard` because the modal
            // tracks its own `testing` state. An aborted request comes back as
            // null and is indistinguishable here from a failure, which is why
            // the abort is checked before the result is written.
            const data = await request(url, null, {
                signal: testAbortController.signal,
                noGuard: true,
            });

            if (testAbortController === null) return;

            if (data === null) {
                // Transport or 5xx: `request` has toasted, and the panel still
                // needs to say the test did not run.
                testModal.value.result = {
                    success: false,
                    message: t("shared.common.error"),
                };
                return;
            }

            if (data.success && data.mountPoint) {
                const index = mountPoints.value.findIndex(
                    (mp) => mp.id === data.mountPoint.id,
                );
                if (index !== -1) mountPoints.value[index] = data.mountPoint;
            }

            testModal.value.result = {
                success: data.testSuccess ?? false,
                message: data.testMessage ?? null,
            };
        } finally {
            testAbortController = null;
            testModal.value.testing = false;
        }
    }

    return {
        mountPoints,
        types,
        filteredMountPoints,
        searchInput,
        loading,
        saving,
        testModal,
        showCreateModal,
        showEditModal,
        showDeleteModal,
        editingMountPoint,
        pendingDelete,
        createForm,
        createErrors,
        editForm,
        editErrors,
        load,
        openCreate,
        closeCreate,
        submitCreate,
        openEdit,
        closeEdit,
        submitEdit,
        confirmDelete,
        doDelete,
        openTestModal,
        closeTestModal,
        cancelTest,
        retryTest: () => {
            testModal.value.result = null;
            testModal.value.testing = true;
            runTest(testModal.value.mountPoint);
        },
    };
}
