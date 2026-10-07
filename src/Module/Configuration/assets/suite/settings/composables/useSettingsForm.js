import { reactive, ref } from "vue";
import { useI18n } from "vue-i18n";
import { toast } from "vue-sonner";
import { useRequest } from "@/shared/composables/http/suite/useRequest.js";
import { SettingErrorCode } from "@core/utils/enums/settings/settingErrorCode.js";
import { ParameterType } from "@core/utils/enums/settings/parameterType.js";

export function useSettingsForm(groups, availableGroups, updatePath) {
    const { t } = useI18n();
    const { request } = useRequest();

    const fieldValues = reactive({});
    const initialValues = {};
    const parameterByKey = {};
    const mediaState = reactive({});

    for (const groupName of availableGroups) {
        for (const parameter of groups[groupName]) {
            const value = parameter.value ?? "";
            fieldValues[parameter.key] = value;
            initialValues[parameter.key] = value;
            parameterByKey[parameter.key] = parameter;
            if (parameter.type === ParameterType.Media) {
                mediaState[parameter.key] = {
                    id: value ? Number(value) : null,
                    url: parameter.mediaUrl ?? null,
                };
            }
        }
    }

    function onMediaChange(parameter, picked) {
        const id = picked?.id ?? null;
        const url = picked?.url ?? null;
        mediaState[parameter.key] = { id, url };
        fieldValues[parameter.key] = id ? String(id) : "";
    }

    function dependencyDepth(parameter) {
        let depth = 0;
        let current = parameter.requires;
        while (current) {
            depth++;
            current = parameterByKey[current]?.requires;
        }
        return depth;
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
            ? t("suite.settings.cascade_locked", { parent: parent.label })
            : "";
    }

    /**
     * The parameter waiting on a confirmation, or null.
     *
     * A setting whose "off" is a decision rather than a preference declares
     * what to say about it, and the toggle stops on the way down until the
     * person answers. Switching one back on asks nothing: putting something
     * back the way it was needs no warning.
     */
    const pendingOff = ref(null);

    function onBoolChange(parameter, enabled) {
        if (!enabled && parameter.offWarning) {
            pendingOff.value = parameter;

            return;
        }

        // Switching the same setting back on withdraws the question: a modal
        // still asking whether to turn off something that is on again is a
        // question whose answer no longer means anything.
        if (pendingOff.value?.key === parameter.key) {
            pendingOff.value = null;
        }

        applyBool(parameter, enabled);
    }

    function confirmOff() {
        const parameter = pendingOff.value;
        pendingOff.value = null;

        if (parameter) {
            applyBool(parameter, false);
        }
    }

    function cancelOff() {
        pendingOff.value = null;
    }

    function applyBool(parameter, enabled) {
        fieldValues[parameter.key] = enabled ? "1" : "0";
        if (!enabled) {
            for (const child of Object.values(parameterByKey)) {
                if (
                    child.requires === parameter.key &&
                    fieldValues[child.key] === "1"
                ) {
                    applyBool(child, false);
                }
            }
        }
    }

    const savingGroups = reactive({});

    async function saveGroup(groupName) {
        savingGroups[groupName] = true;

        const changed = groups[groupName]
            .filter(
                (parameter) =>
                    fieldValues[parameter.key] !== initialValues[parameter.key],
            )
            .sort(
                (left, right) => dependencyDepth(left) - dependencyDepth(right),
            );

        if (changed.length === 0) {
            savingGroups[groupName] = false;
            toast.success(t("suite.settings.saved"));
            return;
        }

        try {
            for (const parameter of changed) {
                const result = await request(
                    updatePath,
                    { key: parameter.key, value: fieldValues[parameter.key] },
                    { noGuard: true },
                );

                if (!result.success) {
                    if (result.error === SettingErrorCode.InvalidPrefix) {
                        toast.error(
                            t("suite.settings.invalid_prefix", {
                                label: parameter.label ?? parameter.key,
                            }),
                        );
                    } else if (
                        result.error === SettingErrorCode.CascadeViolation
                    ) {
                        const parent = parameterByKey[result.parentKey];
                        toast.error(
                            t("suite.settings.cascade_locked", {
                                parent: parent?.label ?? result.parentKey,
                            }),
                        );
                    } else {
                        toast.error(t("shared.common.error"));
                    }
                    return;
                }

                // The server may have tidied the value (a prefix is upper-cased):
                // show what it kept.
                if (typeof result.value === "string")
                    fieldValues[parameter.key] = result.value;
                initialValues[parameter.key] = fieldValues[parameter.key];
            }

            toast.success(t("suite.settings.saved"));
        } catch {
            toast.error(t("shared.common.error"));
        } finally {
            savingGroups[groupName] = false;
        }
    }

    return {
        fieldValues,
        mediaState,
        isLocked,
        lockReason,
        onBoolChange,
        pendingOff,
        confirmOff,
        cancelOff,
        onMediaChange,
        savingGroups,
        saveGroup,
    };
}
