import { ref } from "vue";
import { useI18n } from "vue-i18n";
import { toast } from "vue-sonner";
import { useRequest } from "@/shared/composables/http/backend/useRequest.js";

/**
 * The rooms of a conversation, as the panel holds them.
 *
 * **Every write answers with the whole list**, like the messages beside it and
 * for the same reason: a page that patched its own copy is the first place two
 * readers disagree. Renaming a room from one tab and opening one from another
 * would otherwise leave each tab right about its own change and wrong about the
 * other's.
 *
 * The client's page holds this too, with every path null - it lists the rooms
 * it was handed and nothing here can be called. Splitting it in two would mean
 * two components drawing one rail.
 *
 * @param {Array}  initial the rooms the page was rendered with
 * @param {object} paths   createPath, renamePath, audiencePath, deletePath, invitePath
 */
export function useSpaceChatChannels(initial, paths) {
    const { t } = useI18n();
    const { request } = useRequest();

    const channels = ref([...(initial ?? [])]);
    const working = ref(false);

    function forChannel(path, channel) {
        return path ? path.replace("__channel__", String(channel?.id)) : path;
    }

    async function send(path, body) {
        if (!path || working.value) return false;

        working.value = true;
        try {
            const data = await request(path, body ?? {});

            if (!data?.success) return false;

            channels.value = data.chatChannels ?? [];

            return true;
        } finally {
            working.value = false;
        }
    }

    async function create(name) {
        await send(paths.createPath, { name });
    }

    async function rename({ channel, name }) {
        await send(forChannel(paths.renamePath, channel), { name });
    }

    async function setAudience({ channel, openToClient }) {
        await send(forChannel(paths.audiencePath, channel), { openToClient });
    }

    async function drop(channel) {
        await send(forChannel(paths.deletePath, channel));
    }

    /**
     * Ouvre une conversation privée, ou rouvre celle qui existe.
     *
     * Le serveur répond la liste **et** l'identifiant du salon : deux personnes
     * n'ont qu'une conversation entre elles, donc le second appel renvoie le
     * premier salon, et l'appelant doit pouvoir s'y rendre dans les deux cas.
     */
    async function openDirect(userId) {
        if (!paths.directPath || working.value) return null;

        working.value = true;
        try {
            const data = await request(paths.directPath, { userId });

            if (!data?.success) return null;

            channels.value = data.chatChannels ?? [];

            return data.chatChannelId ?? null;
        } finally {
            working.value = false;
        }
    }

    async function invite({ channel, userId }) {
        if (await send(forChannel(paths.invitePath, channel), { userId })) {
            toast.success(t("shared.space_chat.channels.invited"));
        }
    }

    return {
        channels,
        working,
        create,
        rename,
        setAudience,
        drop,
        invite,
        openDirect,
    };
}
