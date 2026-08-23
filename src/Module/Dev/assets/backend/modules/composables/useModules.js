import { ref, reactive, computed } from "vue";
import { useI18n } from "vue-i18n";
import { toast } from "vue-sonner";
import { HttpMethod } from "@/shared/utils/http/httpMethod.js";
import { useRequest } from "@/shared/composables/http/backend/useRequest.js";
import { SettingErrorCode } from "@core/utils/enums/settings/settingErrorCode.js";

export function useModules(
    modulesPath,
    moduleUpdatePath,
    moduleVerifyPasswordPath,
    initialData,
) {
    const { t } = useI18n();

    const parameters = ref(initialData?.parameters ?? []);
    const fieldValues = reactive({});
    const initialValues = {};
    const parameterByKey = {};
    const saving = ref(false);
    const searchInput = ref("");

    const filteredParameters = computed(() => {
        const query = searchInput.value.trim().toLowerCase();
        if (!query) return parameters.value;
        return parameters.value.filter(
            (parameter) =>
                parameter.label.toLowerCase().includes(query) ||
                parameter.key.toLowerCase().includes(query) ||
                (parameter.subModules ?? []).some(
                    (sub) =>
                        sub.label.toLowerCase().includes(query) ||
                        sub.key.toLowerCase().includes(query),
                ),
        );
    });

    // Password gate - verified once per page load, then all toggles are free
    const passwordVerified = ref(false);
    const showPasswordModal = ref(false);
    const password = ref("");
    const passwordError = ref("");
    const verifying = ref(false);
    const pendingToggle = ref(null);

    const { request: loadRequest } = useRequest();
    const { request: patchRequest } = useRequest();
    const { request: verifyRequest } = useRequest();

    function init(params) {
        for (const parameter of params) {
            const value = parameter.value ?? "0";
            fieldValues[parameter.key] = value;
            initialValues[parameter.key] = value;
            parameterByKey[parameter.key] = parameter;

            for (const sub of parameter.subModules ?? []) {
                const subValue = sub.value ?? "0";
                fieldValues[sub.key] = subValue;
                initialValues[sub.key] = subValue;
                parameterByKey[sub.key] = sub;
            }
        }
    }

    init(parameters.value);

    async function load() {
        const data = await loadRequest(modulesPath, null, HttpMethod.Get);
        if (data) {
            parameters.value = data.parameters ?? [];
            init(parameters.value);
        }
    }

    function isLocked(parameter) {
        return parameter.requires
            ? fieldValues[parameter.requires] !== "1"
            : false;
    }

    function lockReason(parameter) {
        const parent = parameter.requires
            ? parameterByKey[parameter.requires]
            : null;
        return parent
            ? t("backend.settings.cascade_locked", { parent: parent.label })
            : "";
    }

    function applyToggle(parameter, enabled) {
        fieldValues[parameter.key] = enabled ? "1" : "0";
        if (!enabled) {
            for (const child of Object.values(parameterByKey)) {
                if (
                    child.requires === parameter.key &&
                    fieldValues[child.key] === "1"
                ) {
                    applyToggle(child, false);
                }
            }
        }
    }

    function allParameters() {
        const all = [];
        for (const param of parameters.value) {
            all.push(param);
            for (const sub of param.subModules ?? []) {
                all.push(sub);
            }
        }
        return all;
    }

    async function save() {
        if (saving.value) return;
        saving.value = true;

        const changed = allParameters()
            .filter(
                (parameter) =>
                    fieldValues[parameter.key] !== initialValues[parameter.key],
            )
            .sort((a, b) => (a.requires ? 1 : 0) - (b.requires ? 1 : 0));

        try {
            for (const parameter of changed) {
                const result = await patchRequest(
                    moduleUpdatePath.replace("__key__", parameter.key),
                    { value: fieldValues[parameter.key] },
                    { method: HttpMethod.Patch, noGuard: true },
                );

                if (!result) return;

                if (!result.success) {
                    if (result.error === SettingErrorCode.CascadeViolation) {
                        const parent = parameterByKey[result.parentKey];
                        toast.error(
                            t("backend.settings.cascade_locked", {
                                parent: parent?.label ?? result.parentKey,
                            }),
                        );
                    } else {
                        toast.error(t("shared.common.error"));
                    }
                    return;
                }

                initialValues[parameter.key] = fieldValues[parameter.key];
            }

            toast.success(t("backend.settings.saved"));
        } finally {
            saving.value = false;
        }
    }

    function onToggle(parameter, enabled) {
        if (passwordVerified.value) {
            applyToggle(parameter, enabled);
            save();
            return;
        }
        pendingToggle.value = { parameter, enabled };
        password.value = "";
        passwordError.value = "";
        showPasswordModal.value = true;
    }

    async function confirmPassword() {
        passwordError.value = "";
        verifying.value = true;
        const result = await verifyRequest(moduleVerifyPasswordPath, {
            password: password.value,
        });
        verifying.value = false;

        if (!result) {
            passwordError.value = t(
                "backend.settings.confirm_password_invalid",
            );
            return;
        }

        passwordVerified.value = true;
        showPasswordModal.value = false;

        if (pendingToggle.value) {
            applyToggle(
                pendingToggle.value.parameter,
                pendingToggle.value.enabled,
            );
            pendingToggle.value = null;
            save();
        }
    }

    return {
        parameters,
        filteredParameters,
        fieldValues,
        saving,
        searchInput,
        showPasswordModal,
        password,
        passwordError,
        verifying,
        load,
        isLocked,
        lockReason,
        onToggle,
        confirmPassword,
    };
}
