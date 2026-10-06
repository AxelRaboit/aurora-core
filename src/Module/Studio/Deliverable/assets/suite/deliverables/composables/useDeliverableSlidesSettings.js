import { ref } from "vue";
import { useI18n } from "vue-i18n";
import { toast } from "vue-sonner";
import { useRequest } from "@/shared/composables/http/suite/useRequest.js";

/**
 * Ce que la fenêtre « Réglages » d'un diaporama envoie à l'enregistrement d'un
 * livrable : tout ce que l'éditeur d'une page enverrait, pour que la même
 * route serve les deux formats.
 *
 * **Sans la date de modification.** L'éditeur de diapositives enregistre une
 * diapositive à chaque pas, et chaque écriture date le livrable : la date reçue
 * à l'ouverture serait toujours périmée, et la route répondrait « un collègue
 * a enregistré avant vous » à chaque réglage. Les réglages ne touchent pas aux
 * diapositives : rien ne s'écrase.
 *
 * @param {object} form le livrable tel que le sérialise l'éditeur
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
        visibleToClient: false,
        thumbnailId: form.thumbnail?.id ?? null,
        scope: form.scope,
        categoryId: form.categoryId ?? null,
        template: !!form.template,
        customerId: form.customerId ?? null,
    };
}

const clone = (value) => JSON.parse(JSON.stringify(value));

/**
 * Les réglages d'un livrable au format diaporama, dans une fenêtre : le titre,
 * le résumé, l'image, la catégorie, le client, la case « modèle » et le rayon.
 *
 * Une fenêtre plutôt qu'un onglet : l'éditeur de diapositives n'a pas
 * d'onglets, et ces réglages se changent rarement. Ouvrir repart toujours de
 * ce qui est enregistré : une saisie abandonnée ne revient pas.
 *
 * @param {{ deliverable: object, updatePath: string }} props
 * @param {{ onSaved?: Function }} [options] reçoit le livrable enregistré
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
