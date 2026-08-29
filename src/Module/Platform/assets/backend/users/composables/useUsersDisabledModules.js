import { ref, reactive } from "vue";
import { useI18n } from "vue-i18n";
import { toast } from "vue-sonner";
import { useRequest } from "@/shared/composables/http/backend/useRequest.js";
import { buildPath } from "@/shared/utils/http/buildPath.js";

export function useUsersDisabledModules(props, fetchUsers) {
    const { t } = useI18n();
    const { request } = useRequest();

    const modulesModal = reactive({
        open: false,
        user: null,
        saving: false,
    });
    const pendingDisabledModules = ref([]);

    function openModules(user) {
        modulesModal.user = user;
        pendingDisabledModules.value = [...(user.disabledModules ?? [])];
        modulesModal.open = true;
    }

    function toggleModule(moduleKey) {
        const index = pendingDisabledModules.value.indexOf(moduleKey);
        if (index >= 0) {
            pendingDisabledModules.value.splice(index, 1);
        } else {
            pendingDisabledModules.value.push(moduleKey);
        }
    }

    async function saveModules() {
        if (!modulesModal.user || !props.disabledModulesPath) {
            return;
        }
        modulesModal.saving = true;
        try {
            const url = buildPath(props.disabledModulesPath, {
                id: modulesModal.user.id,
            });
            const data = await request(
                url,
                { disabledModules: pendingDisabledModules.value },
                { noGuard: true },
            );
            // Null is transport or 5xx, and `request` has already toasted it.
            if (data === null) return;

            if (data?.success) {
                toast.success(t("backend.users.modules.saved"));
                modulesModal.open = false;
                fetchUsers();
            } else if (data?.message) {
                toast.error(t(data.message, data.message));
            }
        } finally {
            modulesModal.saving = false;
        }
    }

    return {
        modulesModal,
        pendingDisabledModules,
        openModules,
        toggleModule,
        saveModules,
    };
}
