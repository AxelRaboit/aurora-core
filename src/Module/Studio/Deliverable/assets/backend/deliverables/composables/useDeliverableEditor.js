import { computed, onBeforeUnmount, onMounted, provide, ref } from "vue";
import { useI18n } from "vue-i18n";
import { toast } from "vue-sonner";
import { useRequest } from "@/shared/composables/http/backend/useRequest.js";

/**
 * Ce qui part au serveur : ce que compare l'empreinte, plus la date de
 * modification que l'éditeur a reçue (voir {@see payload}).
 */
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
 * L'envoi : le contenu, et la date de modification qu'on a reçue.
 *
 * Elle n'entre pas dans l'empreinte, qui ne dit que ce que l'auteur a changé.
 * Le serveur la compare à la sienne : si un collègue a enregistré entre-temps,
 * il répond 409 plutôt que d'effacer son travail. Un livrable partagé a
 * plusieurs auteurs, et la dernière sauvegarde ne doit pas gagner en silence.
 */
function payload(form, force) {
    return {
        ...JSON.parse(snapshot(form)),
        updatedAt: form.updatedAt ?? null,
        ...(force ? { force: true } : {}),
    };
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
 *
 * **Sans le droit d'écrire, rien ne s'enregistre ni ne s'arme** : Ctrl+S ne
 * part pas au serveur pour y recevoir un 403, et la page n'avertit pas
 * qu'elle perd des changements qu'on n'a pas pu faire.
 *
 * **Un enregistrement refusé pour cause de version dit pourquoi** : `conflict`
 * s'allume, l'écran propose de recharger ou d'enregistrer quand même
 * ({@see saveAnyway}).
 */
export function useDeliverableEditor(props) {
    const { t } = useI18n();
    const { request } = useRequest();

    const form = ref(JSON.parse(JSON.stringify(props.deliverable)));
    const saved = ref(fingerprint(form.value));
    const saving = ref(false);
    const errors = ref({});
    const conflict = ref(false);

    const editors = new Set();

    provide("registerEditor", (handlers) => {
        editors.add(handlers);

        return () => editors.delete(handlers);
    });

    async function flushEditors() {
        await Promise.all([...editors].map((editor) => editor.flush()));
    }

    const canEdit = computed(() => !!props.canEdit);
    const dirty = computed(
        () => canEdit.value && fingerprint(form.value) !== saved.value,
    );

    /** Le premier message d'erreur du serveur, traduit : ce qui manque, pas « n'a pas pu être enregistré ». */
    function firstError(failures) {
        const key = Object.values(failures ?? {}).find(
            (value) => "string" === typeof value && "" !== value,
        );

        return key ? t(key) : null;
    }

    async function save(force = false) {
        if (saving.value || !canEdit.value) return false;

        await flushEditors();

        saving.value = true;
        errors.value = {};
        conflict.value = false;
        try {
            const sent = snapshot(form.value);
            const data = await request(
                props.updatePath,
                payload(form.value, true === force),
            );

            if (!data?.success) {
                // Quelqu'un a enregistré avant : ni le message générique ni
                // un faux « titre invalide », la vraie raison.
                if (data?.conflict) {
                    conflict.value = true;

                    return false;
                }

                errors.value = data?.errors ?? {};
                toast.error(
                    firstError(data?.errors) ??
                        t("backend.studio.deliverables.save_failed"),
                );

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
            // Le navigateur ne propose pas d'enregistrer la page : le geste
            // est celui de l'éditeur, qu'il puisse écrire ou non.
            event.preventDefault();
            if (canEdit.value) void save();
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

    /** Écraser ce que le collègue a enregistré : le choix de l'auteur, après avoir lu le conflit. */
    function saveAnyway() {
        return save(true);
    }

    function dismissConflict() {
        conflict.value = false;
    }

    return {
        form,
        saving,
        errors,
        conflict,
        dirty,
        canEdit,
        save,
        saveAnyway,
        dismissConflict,
        flushEditors,
        markClean,
    };
}
