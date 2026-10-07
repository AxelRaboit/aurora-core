import { ref } from "vue";
import { useI18n } from "vue-i18n";
import { toast } from "vue-sonner";
import { useRequest } from "@/shared/composables/http/suite/useRequest.js";

/**
 * The resources of a space, and the five writes that change them.
 *
 * **Each response returns the whole list**, which replaces the previous one:
 * adding a resource appends it to the end of the list, reordering renumbers
 * everything, and a page that patched up its copy would drift from the
 * server in three gestures. A list of resources is short, it fits in one
 * response.
 *
 * Refusals are handed back to the caller rather than shouted here: a field
 * error goes under its field in the modal, and a toast in its place would
 * make the place to fix it disappear.
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
     * A submission, and what to do with it.
     *
     * Returns `{ ok, errors }`: `ok` to close the modal, `errors` to keep it
     * open with the messages under the fields. `request` returns the envelope
     * of a 422 without announcing anything, so without this sorting a missing
     * label would produce nothing at all on screen.
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
            // Said out loud: it is the gesture that publishes, and nothing else on
            // screen tells "the client sees it" from "they do not see it" fast enough
            // to be sure of it at a glance.
            const shown = resources.value.find(
                (row) => row.id === resource.id,
            )?.visibleToClient;
            toast.success(
                t(
                    shown
                        ? "suite.studio.space_resources.now_visible"
                        : "suite.studio.space_resources.now_hidden",
                ),
            );
        }

        return result;
    }

    function remove(id) {
        return send(pathFor(props.resourceDeletePath, id));
    }

    /**
     * The new order, sent after being applied on screen.
     *
     * The local order moves first: a list being arranged must follow the hand,
     * and waiting for the server response to redraw makes the row that was just
     * moved jump.
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
