import { computed, onBeforeUnmount, onMounted, provide, ref } from "vue";
import { useI18n } from "vue-i18n";
import { toast } from "vue-sonner";
import { useRequest } from "@/shared/composables/http/backend/useRequest.js";

/** Ce qui part au serveur. */
function snapshot(form) {
    return JSON.stringify({
        title: form.title,
        summary: form.summary,
        locale: form.locale,
        gridLayout: form.gridLayout,
        gridContent: form.gridContent,
        appearance: form.appearance,
        readingHeader: form.readingHeader,
        visibleToClient: form.visibleToClient,
        thumbnailId: form.thumbnail?.id ?? null,
        // Le rayon et la catégorie d'un livrable de Studio ; absents pour un
        // livrable d'espace.
        ...(form.scope
            ? { scope: form.scope, categoryId: form.categoryId ?? null }
            : {}),
    });
}

/**
 * Une valeur sans ses vides, pour comparer.
 *
 * La grille d'édition complète la structure du contenu en la lisant : une zone
 * sans texte reçoit ses champs vides, une liste vide arrivée en `[]` devient
 * `{}`. Rien n'a changé pour autant, et l'éditeur ne doit pas se dire modifié
 * à l'ouverture. Un champ vidé reste un changement : la valeur qu'il avait
 * disparaît de la comparaison.
 */
function pruned(value) {
    if (Array.isArray(value)) {
        const list = value.map(pruned).filter((entry) => undefined !== entry);

        return list.length ? list : undefined;
    }

    if (value && "object" === typeof value) {
        const entries = Object.entries(value)
            .map(([key, entry]) => [key, pruned(entry)])
            .filter(([, entry]) => undefined !== entry);

        return entries.length ? Object.fromEntries(entries) : undefined;
    }

    return null === value || "" === value ? undefined : value;
}

/** Ce qu'on compare pour savoir s'il reste à enregistrer. */
function fingerprint(form) {
    return JSON.stringify(pruned(JSON.parse(snapshot(form))) ?? null);
}

/**
 * L'état d'un livrable ouvert dans l'éditeur, et son enregistrement.
 *
 * **Les éditeurs de blocs se vident avant d'enregistrer.** Chaque zone de
 * texte tient sa saisie dans son propre éditeur et ne la rend qu'à la
 * demande : c'est le contrat `registerEditor` que les éditeurs de blocs
 * attendent de leur hôte, le même que celui de l'éditeur des publications.
 *
 * **Quitter la page avec des changements prévient**, comme partout où l'on
 * compose longtemps : une grille de vingt zones ne se retape pas.
 */
export function useDeliverableEditor(props) {
    const { t } = useI18n();
    const { request } = useRequest();

    const form = ref(JSON.parse(JSON.stringify(props.deliverable)));
    const saved = ref(fingerprint(form.value));
    const saving = ref(false);
    const errors = ref({});

    const editors = new Set();

    provide("registerEditor", (handlers) => {
        editors.add(handlers);

        return () => editors.delete(handlers);
    });

    async function flushEditors() {
        await Promise.all([...editors].map((editor) => editor.flush()));
    }

    const dirty = computed(() => fingerprint(form.value) !== saved.value);

    async function save() {
        if (saving.value) return false;

        await flushEditors();

        saving.value = true;
        errors.value = {};
        try {
            const sent = snapshot(form.value);
            const data = await request(props.updatePath, JSON.parse(sent));

            if (!data?.success) {
                errors.value = data?.errors ?? {};
                toast.error(t("backend.studio.deliverables.save_failed"));

                return false;
            }

            // Ce qui a été envoyé, et pas ce que le serveur renvoie : remplacer
            // le formulaire rechargerait chaque éditeur de texte de la grille,
            // curseur compris, au milieu d'une saisie. La version normalisée
            // s'affiche au prochain chargement.
            saved.value = fingerprint(JSON.parse(sent));
            form.value.updatedAt =
                data.deliverable?.updatedAt ?? form.value.updatedAt;
            toast.success(t("backend.studio.deliverables.saved"));

            return true;
        } finally {
            saving.value = false;
        }
    }

    function onBeforeUnload(event) {
        if (!dirty.value) return;

        event.preventDefault();
        event.returnValue = "";
    }

    function onKeydown(event) {
        if (
            (event.metaKey || event.ctrlKey) &&
            "s" === event.key.toLowerCase()
        ) {
            event.preventDefault();
            void save();
        }
    }

    onMounted(() => {
        window.addEventListener("beforeunload", onBeforeUnload);
        window.addEventListener("keydown", onKeydown);
    });

    onBeforeUnmount(() => {
        window.removeEventListener("beforeunload", onBeforeUnload);
        window.removeEventListener("keydown", onKeydown);
    });

    /** Plus rien à garder : après une suppression, la page ne retient pas le départ. */
    function markClean() {
        saved.value = fingerprint(form.value);
    }

    return { form, saving, errors, dirty, save, flushEditors, markClean };
}
