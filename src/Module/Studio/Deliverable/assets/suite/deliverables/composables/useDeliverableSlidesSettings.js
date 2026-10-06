import { ref } from "vue";
import { useI18n } from "vue-i18n";
import { toast } from "vue-sonner";
import { useRequest } from "@/shared/composables/http/suite/useRequest.js";

/**
 * What a slideshow's "Réglages" dialog sends to the deliverable save: all
 * that a page's editor would send, so that the same route serves both
 * formats.
 *
 * **Without the modification date.** The slide editor saves a slide at every
 * step, and each write dates the deliverable: the date received on opening
 * would always be stale, and the route would answer "a colleague saved
 * before you" on every setting. The settings do not touch the slides:
 * nothing gets overwritten.
 *
 * @param {object} form the deliverable as the editor serializes it
 */
export function settingsPayload(form) {
    return {
        title: form.title,
        summary: form.summary ?? "",
        locale: form.locale,
        gridLayout: form.gridLayout ?? {},
        gridContent: form.gridContent ?? {},
        appearance: form.appearance ?? {},
        readingHeader: form.readingHeader ?? {},
        // What it is, or what the toggle just decided: in a space, sending
        // `false` would hide a presentation the client reads, or be refused to
        // whoever may not show it. Studio ignores it, having no client.
        visibleToClient: true === form.visibleToClient,
        thumbnailId: form.thumbnail?.id ?? null,
        scope: form.scope,
        categoryId: form.categoryId ?? null,
        template: !!form.template,
        customerId: form.customerId ?? null,
    };
}

const clone = (value) => JSON.parse(JSON.stringify(value));

/**
 * A slideshow deliverable's settings, in a dialog: the title, the summary,
 * the image, the category, the client, the "modèle" box and the shelf.
 *
 * A dialog rather than a tab: the slide editor has no tabs, and these
 * settings rarely change. Opening always starts again from what is saved: an
 * abandoned input does not come back.
 *
 * @param {{ deliverable: object, updatePath: string }} props
 * @param {{ onSaved?: Function }} [options] receives the saved deliverable
 */
export function useDeliverableSlidesSettings(props, { onSaved = null } = {}) {
    const { t } = useI18n();
    const { request } = useRequest();

    const current = ref(clone(props.deliverable));
    const form = ref(clone(props.deliverable));
    const open = ref(false);
    const saving = ref(false);
    const errors = ref({});

    function openSettings() {
        form.value = clone(current.value);
        errors.value = {};
        open.value = true;
    }

    async function save() {
        if (saving.value) return false;

        saving.value = true;
        errors.value = {};
        try {
            const data = await request(
                props.updatePath,
                settingsPayload(form.value),
            );

            if (!data?.success) {
                errors.value = data?.errors ?? {};
                toast.error(t("suite.studio.deliverables.save_failed"));

                return false;
            }

            current.value = clone(data.deliverable ?? form.value);
            onSaved?.(current.value);
            open.value = false;
            toast.success(t("suite.studio.deliverables.saved"));

            return true;
        } finally {
            saving.value = false;
        }
    }

    return { current, form, open, saving, errors, openSettings, save };
}
