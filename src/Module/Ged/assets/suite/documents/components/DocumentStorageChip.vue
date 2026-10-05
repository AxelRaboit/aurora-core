<script setup>
import { computed } from "vue";
import { useI18n } from "vue-i18n";
import { AlertTriangle, Cloud, HardDrive, LoaderCircle } from "lucide-vue-next";

/**
 * Where a document's bytes live, and whether they are on the move.
 *
 * Worth showing at all only once a second backend exists: on the installations
 * that will never have one, a chip saying "server" on every row is noise, so
 * the screens hide it rather than draw a constant.
 *
 * A failed move gets its own colour and its reason in the tooltip. The
 * alternative, a row that looks normal after a move that did not happen, is how
 * somebody concludes the button is broken.
 */
const props = defineProps({
    disk: { type: String, default: "local" },
    state: { type: String, default: "idle" },
    error: { type: String, default: null },
});

const { t } = useI18n();

const isPending = computed(() => props.state === "pending");
const isFailed = computed(() => props.state === "failed");
const isRemote = computed(() => props.disk === "r2");

const icon = computed(() => {
    if (isPending.value) return LoaderCircle;
    if (isFailed.value) return AlertTriangle;

    return isRemote.value ? Cloud : HardDrive;
});

const label = computed(() => {
    if (isPending.value) return t("suite.ged.documents.storage.moving");
    if (isFailed.value) return t("suite.ged.documents.storage.failed");

    return t(
        isRemote.value
            ? "suite.ged.documents.storage.remote"
            : "suite.ged.documents.storage.local",
    );
});

const title = computed(() => {
    if (isFailed.value && props.error) return props.error;

    return t("suite.ged.documents.storage.hint", { place: label.value });
});

const toneClass = computed(() => {
    if (isFailed.value) return "border-danger/40 bg-danger/10 text-danger";
    if (isPending.value) return "border-line bg-surface-2 text-muted";

    return isRemote.value
        ? "border-accent/40 bg-accent/10 text-accent"
        : "border-line bg-surface-2 text-secondary";
});
</script>

<template>
    <span
        class="inline-flex items-center gap-1 rounded border px-1.5 py-0.5 text-xs whitespace-nowrap"
        :class="toneClass"
        :title="title"
    >
        <component
            :is="icon"
            class="w-3 h-3 shrink-0"
            :class="isPending ? 'animate-spin motion-reduce:animate-none' : ''"
            :stroke-width="2"
        />
        {{ label }}
    </span>
</template>
