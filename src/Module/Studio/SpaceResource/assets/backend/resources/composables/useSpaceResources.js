import { ref } from "vue";
import { useI18n } from "vue-i18n";
import { toast } from "vue-sonner";
import { useRequest } from "@/shared/composables/http/backend/useRequest.js";

/**
 * Les ressources d'un espace, et les cinq écritures qui les changent.
 *
 * **Chaque réponse rend la liste entière**, qui remplace la précédente : poser
 * une ressource l'ajoute en fin de liste, la ranger renumérote tout, et une
 * page qui rafistolerait sa copie s'écarterait du serveur en trois gestes. Une
 * liste de ressources est courte, elle tient dans une réponse.
 *
 * Les refus sont rendus à l'appelant plutôt que criés ici : une erreur de
 * champ se pose sous son champ dans la modale, et un toast à sa place ferait
 * disparaître l'endroit où corriger.
 */
export function useSpaceResources(props) {
    const { t } = useI18n();
    const { request } = useRequest();

    const resources = ref([...(props.resources ?? [])]);
    const saving = ref(false);

    function pathFor(template, id) {
        return (template ?? "").replace("__id__", id);
    }

    /**
     * Un envoi, et ce qu'il faut en faire.
     *
     * Rend `{ ok, errors }` : `ok` pour fermer la modale, `errors` pour la
     * garder ouverte avec les messages sous les champs. `request` rend
     * l'enveloppe d'un 422 sans rien annoncer, donc sans ce tri un libellé
     * manquant ne produirait rien du tout à l'écran.
     */
    async function send(url, payload = null) {
        saving.value = true;

        try {
            const data = await request(url, payload);

            if (!data?.success) {
                const errors = data?.errors ?? {};

                if (0 === Object.keys(errors).length) {
                    toast.error(t(data?.error ?? "shared.common.error"));
                }

                return { ok: false, errors };
            }

            resources.value = data.resources ?? [];

            return { ok: true, errors: {} };
        } finally {
            saving.value = false;
        }
    }

    function create(payload) {
        return send(props.resourceCreatePath, payload);
    }

    function update(id, payload) {
        return send(pathFor(props.resourceUpdatePath, id), payload);
    }

    async function toggleVisibility(resource) {
        const result = await send(
            pathFor(props.resourceVisibilityPath, resource.id),
        );

        if (result.ok) {
            // Dit à voix haute : c'est le geste qui publie, et rien d'autre
            // à l'écran ne distingue « le client le voit » de « il ne le voit
            // pas » assez vite pour qu'on s'en assure d'un coup d'œil.
            const shown = resources.value.find(
                (row) => row.id === resource.id,
            )?.visibleToClient;
            toast.success(
                t(
                    shown
                        ? "backend.studio.space_resources.now_visible"
                        : "backend.studio.space_resources.now_hidden",
                ),
            );
        }

        return result;
    }

    function remove(id) {
        return send(pathFor(props.resourceDeletePath, id));
    }

    /**
     * Le nouvel ordre, envoyé après avoir été appliqué à l'écran.
     *
     * L'ordre local bouge d'abord : une liste qu'on range doit suivre la main,
     * et attendre la réponse du serveur pour redessiner fait sauter la ligne
     * qu'on vient de déplacer.
     */
    async function move(id, direction) {
        const index = resources.value.findIndex((row) => row.id === id);
        const target = index + direction;

        if (-1 === index || target < 0 || target >= resources.value.length)
            return { ok: true, errors: {} };

        const ordered = [...resources.value];
        [ordered[index], ordered[target]] = [ordered[target], ordered[index]];
        resources.value = ordered;

        return send(props.resourceReorderPath, {
            ids: ordered.map((row) => row.id),
        });
    }

    return {
        resources,
        saving,
        create,
        update,
        toggleVisibility,
        remove,
        move,
    };
}
