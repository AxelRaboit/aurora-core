import { ref } from "vue";
import { useI18n } from "vue-i18n";
import { toast } from "vue-sonner";
import { useFormAction } from "@/shared/composables/form/useFormAction.js";
import { useRequest } from "@/shared/composables/http/suite/useRequest.js";

/** What the Settings tab edits, read from the form as the server last sent it. */
export function settingsFrom(form, locales) {
    return {
        notifyEmail: form.notifyEmail ?? "",
        webhookUrl: form.webhookUrl ?? "",
        crmSync: form.crmSync ?? false,
        standalonePageIndexed: form.standalonePageIndexed ?? false,
        active: form.active ?? true,
        translations: Object.fromEntries(
            locales.map((locale) => [
                locale,
                {
                    title: form.translations?.[locale]?.title ?? "",
                    slug: form.translations?.[locale]?.slug ?? "",
                    description: form.translations?.[locale]?.description ?? "",
                },
            ]),
        ),
    };
}

/**
 * The form's own settings, and its steps.
 *
 * Both go through the one update endpoint, which takes the whole form: the
 * steps are therefore saved with the settings as they stand on the server, not
 * with whatever the Settings tab holds unsaved - renaming a step must not
 * publish a half-typed title from another tab.
 */
export function useFormSettings(props, form, upsert) {
    const { t } = useI18n();
    const { request } = useRequest();

    const settings = ref(settingsFrom(form.value, props.locales));

    const { errors, loading, submit } = useFormAction({
        url: () => props.updatePath,
        body: () => ({ ...settings.value, steps: form.value.steps ?? null }),
        onSuccess: (data) => {
            toast.success(t("suite.forms.updated"));
            upsert(data?.form);
            settings.value = settingsFrom(
                data?.form ?? form.value,
                props.locales,
            );
        },
    });

    const savingSteps = ref(false);

    /** @param {Array<{title: string}>|null} steps */
    async function saveSteps(steps) {
        if (savingSteps.value) return;

        savingSteps.value = true;
        try {
            const data = await request(props.updatePath, {
                ...settingsFrom(form.value, props.locales),
                steps,
            });
            if (data?.success) upsert(data.form);
        } finally {
            savingSteps.value = false;
        }
    }

    return { settings, errors, loading, submit, savingSteps, saveSteps };
}
