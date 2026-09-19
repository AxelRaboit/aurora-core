<script setup>
/**
 * The files shown on one piece of content, on both surfaces.
 *
 * It sits in `assets/shared/` next to the thread and for the same reason: the
 * studio's board and the client's page both mount it, and its words are
 * `shared.attachments.*` because a key under `backend.` rendered on a page a
 * customer reads is a namespace that has stopped meaning anything.
 *
 * **A preview only for what previews.** The payload sends `preview: null` for
 * anything that is not an image, and a tile then draws an icon from the mime
 * type. Pointing an `<img>` at a PDF is how a file that uploaded correctly ends
 * up looking like a failure.
 *
 * **Removing is per-tile and asks first.** The sentence says the file stays in
 * GED, because that is the part nobody would guess: this takes a file off a
 * card, it does not destroy an asset. The two are different enough that a
 * button which did the second while looking like the first would be a trap.
 *
 * Both abilities arrive as props rather than being read from privileges here:
 * the client's page grants them from the link's own rights, and a component
 * that asked the session would answer "no" there without saying why.
 */
import { computed, ref } from "vue";
import { useI18n } from "vue-i18n";
import { FileText, FileSpreadsheet, Film, Music, File, Upload, Trash2 } from "lucide-vue-next";

const props = defineProps({
    attachments: { type: Array, default: () => [] },
    canAdd: { type: Boolean, default: false },
    canRemove: { type: Boolean, default: false },
    /** Only the studio has a GED to pick from; the client's page has none. */
    canPick: { type: Boolean, default: false },
    /** Shown under the drop zone when the two sides differ on what is allowed. */
    notice: { type: String, default: "" },
    loading: { type: Boolean, default: false },
});

const emit = defineEmits(["upload", "remove", "pick"]);

const { t } = useI18n();

const dragging = ref(false);
const input = ref(null);

const count = computed(() => props.attachments.length);

/**
 * The icon a non-image file gets.
 *
 * Coarse on purpose: the point is to tell a spreadsheet from a film at a
 * glance, not to name the exact format, which the filename under it already
 * does.
 */
function iconFor(mimeType) {
    const mime = mimeType ?? "";

    if (mime.startsWith("video/")) return Film;
    if (mime.startsWith("audio/")) return Music;
    if (mime.includes("spreadsheet") || mime.includes("excel") || mime === "text/csv") return FileSpreadsheet;
    if (mime === "application/pdf" || mime.startsWith("text/")) return FileText;

    return File;
}

function humanSize(bytes) {
    if (!bytes) return "";

    const units = ["o", "ko", "Mo", "Go"];
    let value = bytes;
    let unit = 0;

    while (value >= 1024 && unit < units.length - 1) {
        value /= 1024;
        unit += 1;
    }

    return `${value < 10 && unit > 0 ? value.toFixed(1) : Math.round(value)} ${units[unit]}`;
}

function send(files) {
    for (const file of files) {
        emit("upload", file);
    }
}

function onDrop(event) {
    dragging.value = false;

    if (!props.canAdd) return;

    send(event.dataTransfer?.files ?? []);
}

function onPick(event) {
    send(event.target.files ?? []);
    // Cleared so the same file chosen twice in a row still fires `change`.
    event.target.value = "";
}

function remove(attachment) {
    if (!window.confirm(t("shared.attachments.remove_confirm"))) return;

    emit("remove", attachment);
}
</script>

<template>
    <section class="space-y-2">
        <header class="flex items-center justify-between gap-2">
            <h3 class="text-sm font-medium text-primary">
                {{ t("shared.attachments.title") }}
            </h3>
            <span v-if="count" class="text-xs text-muted">
                {{ t("shared.attachments.count", { count }, count) }}
            </span>
        </header>

        <p v-if="!count && !canAdd" class="text-xs text-muted">
            {{ t("shared.attachments.empty") }}
        </p>

        <ul v-if="count" class="grid grid-cols-2 gap-2 sm:grid-cols-3">
            <li
                v-for="attachment in attachments"
                :key="attachment.id"
                class="group relative overflow-hidden rounded-lg border border-line/60 bg-surface-2/40"
            >
                <a
                    :href="attachment.url"
                    target="_blank"
                    rel="noopener"
                    :title="t('shared.attachments.open')"
                    class="block"
                >
                    <img
                        v-if="attachment.preview"
                        :src="attachment.preview"
                        :alt="attachment.title"
                        class="h-24 w-full object-cover"
                        loading="lazy"
                    >
                    <span v-else class="flex h-24 w-full items-center justify-center text-muted">
                        <component :is="iconFor(attachment.mimeType)" class="h-7 w-7" />
                    </span>
                </a>

                <div class="space-y-0.5 px-2 py-1.5">
                    <p class="truncate text-xs text-primary" :title="attachment.originalName ?? attachment.title">
                        {{ attachment.originalName ?? attachment.title }}
                    </p>
                    <p class="flex items-center gap-1 text-[11px] text-muted">
                        <span v-if="attachment.size">{{ humanSize(attachment.size) }}</span>
                        <span v-if="attachment.fromClient" class="rounded bg-surface-3 px-1">
                            {{ t("shared.thread.from_client") }}
                        </span>
                    </p>
                </div>

                <button
                    v-if="canRemove"
                    type="button"
                    :title="t('shared.attachments.remove')"
                    :aria-label="t('shared.attachments.remove')"
                    class="absolute right-1 top-1 rounded-md bg-surface-1/90 p-1 text-muted transition-opacity hover:text-danger focus:opacity-100 sm:opacity-0 sm:group-hover:opacity-100"
                    v-on:click="remove(attachment)"
                >
                    <Trash2 class="h-3.5 w-3.5" />
                </button>
            </li>
        </ul>

        <div
            v-if="canAdd"
            class="rounded-lg border border-dashed px-3 py-3 text-center transition-colors sm:py-4"
            :class="dragging ? 'border-accent bg-accent/5' : 'border-line/60'"
            v-on:dragover.prevent="dragging = true"
            v-on:dragleave.prevent="dragging = false"
            v-on:drop.prevent="onDrop"
        >
            <p class="text-xs text-muted">{{ t("shared.attachments.drop") }}</p>

            <div class="mt-2 flex flex-wrap items-center justify-center gap-2">
                <button
                    type="button"
                    class="inline-flex items-center gap-1.5 rounded-md border border-line/60 px-2.5 py-1 text-xs text-primary transition-colors hover:bg-surface-2 disabled:opacity-50"
                    :disabled="loading"
                    v-on:click="input?.click()"
                >
                    <Upload class="h-3.5 w-3.5" />
                    {{ t("shared.attachments.add") }}
                </button>

                <!-- Offered only where there is a GED to pick from. Declared
                     as a prop rather than sniffed from `$attrs`: `pick` is in
                     `defineEmits`, so its listener never reaches `$attrs` and
                     the button was silently never drawn. -->
                <button
                    v-if="canPick"
                    type="button"
                    class="rounded-md border border-line/60 px-2.5 py-1 text-xs text-primary transition-colors hover:bg-surface-2"
                    v-on:click="emit('pick')"
                >
                    {{ t("shared.attachments.pick") }}
                </button>
            </div>

            <input
                ref="input"
                type="file"
                multiple
                class="hidden"
                v-on:change="onPick"
            >

            <p v-if="notice" class="mt-2 text-[11px] text-muted">{{ notice }}</p>
        </div>
    </section>
</template>
