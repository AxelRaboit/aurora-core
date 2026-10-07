import { computed, ref } from "vue";
import { useI18n } from "vue-i18n";
import { useFormAction } from "@/shared/composables/form/useFormAction.js";
import { queueFlash } from "@/shared/utils/flash.js";
import {
    formFrom,
    spaceFormRules,
} from "../spaces/composables/useCustomerSpacesForm.js";

/**
 * The space form of the Settings tab: everything the list's modal used to
 * edit, saved through the same route (`suite_studio_spaces_update`), so the
 * same input, validation, rights and manager path apply.
 *
 * **The page reloads after a save.** The space's name, colour and archived
 * badge are drawn by the shell's header, on the server: a save that left them
 * stale would read as a save that did not happen. The toast waits for the
 * reloaded page (`queueFlash`).
 *
 * @param {{ space: object, updatePath: string, customers?: Array }} settings what the server hands the tab
 * @param {{ reload?: Function }} [options] the reload, swapped in tests
 */
export function useSpaceSettingsForm(
    settings,
    { reload = () => window.location.reload() } = {},
) {
    const { t } = useI18n();

    const form = ref(formFrom(settings.space));

    const customerOptions = computed(() =>
        (settings.customers ?? []).map((customer) => ({
            value: String(customer.id),
            label: customer.name,
        })),
    );

    const { errors, loading, submit } = useFormAction({
        rules: () => spaceFormRules(form, t),
        url: () => settings.updatePath,
        body: () => form.value,
        onSuccess: () => {
            queueFlash("success", t("suite.studio.spaces.settings.saved"));
            reload();
        },
    });

    return { form, customerOptions, errors, loading, submit };
}
