<script setup>
/**
 * Says when the server has moved to a newer version than this page (09/10/2026).
 *
 * A tab left open across a deployment keeps the scripts it was served and
 * nothing tells: a share link was created that morning from a page still on
 * the version before, without the box the new one had added. The page asks
 * the server which version it runs when it comes back to the front, and every
 * few minutes while it stays there, and offers to reload when the answer
 * differs from the version it was rendered with.
 *
 * Silent everywhere else: no version (`dev`), no answer, an answer that is not
 * JSON (a session that expired answers with the sign-in page) - none of them is
 * a reason to interrupt anybody.
 */
import { onMounted, onUnmounted, ref } from "vue";
import { useI18n } from "vue-i18n";
import { RefreshCw, X } from "lucide-vue-next";
import { useRequest } from "@/shared/composables/http/suite/useRequest.js";
import { HttpMethod } from "@/shared/utils/http/httpMethod.js";

const props = defineProps({
    version: { type: String, default: "" },
    versionPath: { type: String, default: "" },
});

/** While the page stays in front: a deployment is not a matter of seconds. */
const CHECK_INTERVAL_MS = 10 * 60 * 1000;
/** Switching tabs back and forth asks once, not at every switch. */
const MINIMUM_GAP_MS = 60 * 1000;

const { t } = useI18n();
const { request } = useRequest();

const newVersion = ref("");
const dismissed = ref(false);
const watching = Boolean(props.version) && props.version !== "dev" && Boolean(props.versionPath);

let lastCheck = 0;
let timer = null;

async function check() {
    if (!watching || newVersion.value || document.visibilityState === "hidden") return;
    const now = Date.now();
    if (now - lastCheck < MINIMUM_GAP_MS) return;
    lastCheck = now;

    let response = null;
    try {
        response = await request(props.versionPath, null, { method: HttpMethod.Get, silent: true, noGuard: true });
    } catch {
        return;
    }
    const current = response?.version;
    if (typeof current === "string" && current !== "" && current !== props.version) {
        newVersion.value = current;
    }
}

function reload() {
    window.location.reload();
}

function onVisibilityChange() {
    if (document.visibilityState === "visible") check();
}

onMounted(() => {
    if (!watching) return;
    // The page was just rendered by the server: it is the current version.
    lastCheck = Date.now();
    document.addEventListener("visibilitychange", onVisibilityChange);
    timer = window.setInterval(check, CHECK_INTERVAL_MS);
});

onUnmounted(() => {
    document.removeEventListener("visibilitychange", onVisibilityChange);
    if (timer) window.clearInterval(timer);
});

defineExpose({ check });
</script>

<template>
    <div
        v-if="newVersion && !dismissed"
        data-new-version
        role="status"
        class="flex items-center justify-center gap-3 border-b border-sky-300 bg-sky-50 px-4 py-2 text-xs text-sky-800 dark:border-sky-500/40 dark:bg-sky-500/15 dark:text-sky-300"
    >
        <RefreshCw class="h-4 w-4 shrink-0" :stroke-width="2" />
        <span>{{ t("suite.version.available", { version: newVersion }) }}</span>
        <button
            type="button"
            data-new-version-reload
            class="font-medium underline underline-offset-2 hover:no-underline"
            v-on:click="reload"
        >
            {{ t("suite.version.reload") }}
        </button>
        <button
            type="button"
            data-new-version-dismiss
            class="ml-1 rounded-md p-1 opacity-70 transition-opacity hover:opacity-100 focus-visible:opacity-100"
            :title="t('suite.version.later')"
            :aria-label="t('suite.version.later')"
            v-on:click="dismissed = true"
        >
            <X class="h-3.5 w-3.5" :stroke-width="2" />
        </button>
    </div>
</template>
