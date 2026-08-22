import { ref, onMounted, onBeforeUnmount } from "vue";
import { useI18n } from "vue-i18n";
import { buildPath } from "@/shared/utils/http/buildPath.js";
import { HttpMethod } from "@/shared/utils/http/httpMethod.js";
import { useRequest } from "@/shared/composables/http/backend/useRequest.js";

const POLL_INTERVAL_MS = 30_000; // 30s — light enough not to hammer.

// Module-level singleton state. The bell is mounted twice — once in the page
// header for desktop, once in the mobile top bar — because neither is on screen
// at the breakpoint the other serves. Without this singleton each instance
// would fetch and poll independently, doubling the network traffic.
let sharedState = null;
let refCount = 0;
let pollTimer = null;

export function useNotifications(paths) {
    useI18n();

    if (!sharedState) {
        sharedState = {
            entries: ref([]),
            unreadCount: ref(0),
            open: ref(false),
        };
    }
    const { entries, unreadCount, open } = sharedState;

    const { request } = useRequest();

    async function load() {
        const data = await request(paths.list, null, HttpMethod.Get);
        if (data?.success) {
            entries.value = data.entries ?? [];
            unreadCount.value = data.unreadCount ?? 0;
        }
    }

    async function markRead(notification) {
        const url = buildPath(paths.markRead, { id: notification.id });
        await request(url);
        await load();
    }

    async function markAllRead() {
        await request(paths.markAllRead);
        await load();
    }

    async function deleteOne(notification) {
        const url = buildPath(paths.deletePath, { id: notification.id });
        await request(url, null, HttpMethod.Delete);
        await load();
    }

    async function deleteAll() {
        await request(paths.deleteAllPath, null, HttpMethod.Delete);
        await load();
    }

    function toggle() {
        open.value = !open.value;
        if (open.value) load();
    }

    onMounted(() => {
        // First mount triggers the initial fetch + the shared poll timer.
        // Subsequent mounts (the desktop and mobile bells) just join the
        // existing ref-counted singleton.
        refCount += 1;
        if (1 === refCount) {
            load();
            pollTimer = setInterval(load, POLL_INTERVAL_MS);
        }
    });

    onBeforeUnmount(() => {
        refCount = Math.max(0, refCount - 1);
        if (0 === refCount && pollTimer) {
            clearInterval(pollTimer);
            pollTimer = null;
        }
    });

    return {
        entries,
        unreadCount,
        open,
        toggle,
        load,
        markRead,
        markAllRead,
        deleteOne,
        deleteAll,
    };
}
