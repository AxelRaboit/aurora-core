<script setup>
/**
 * Pick a note's banner, from Pexels, without downloading anything.
 *
 * **The image does not go through the media library, on purpose.** A
 * decorative photo has no life of its own: nobody will search for it, rename
 * it, or file it in a folder. Pouring it into the GED would put it in the
 * same list as the contracts and invoices, where it would only be noise. The
 * note keeps the image's address and its author's credit; if it disappears
 * from their side one day, another one is picked.
 *
 * The credit is not a courtesy: the Pexels licence asks for the photographer
 * to be named, and outside the media library there is no record left to
 * carry it for us.
 *
 * The search goes through our server, like the media library's: the API key
 * stays on the server side.
 */
import { computed, ref, watch } from "vue";
import { useI18n } from "vue-i18n";
import { Check, Image, Search, Trash2 } from "lucide-vue-next";
import AppModal from "@/shared/components/overlay/AppModal.vue";
import AppModalFooter from "@/shared/components/overlay/AppModalFooter.vue";
import AppButton from "@/shared/components/action/AppButton.vue";
import AppInput from "@/shared/components/form/input/AppInput.vue";
import AppNoData from "@/shared/components/feedback/AppNoData.vue";
import { useRequest } from "@/shared/composables/http/suite/useRequest.js";
import { HttpMethod } from "@/shared/utils/http/httpMethod.js";

const props = defineProps({
    show: { type: Boolean, default: false },
    searchPath: { type: String, default: "" },
    /** What the note already carries: `{url, creditName, creditUrl, position}`. */
    cover: { type: Object, default: null },
    /** {@see NoteAppearanceEnum} */
    appearance: { type: String, default: "plain" },
});

const emit = defineEmits([
    "close",
    "choose",
    "remove",
    "position",
    "appearance",
]);

/**
 * The appearances, in the order they are tried.
 *
 * The names come from the server's enum: adding an appearance there and
 * forgetting it here would make it unreachable, so the list is short and
 * reads at a glance next to the `case` that declares it.
 */
const LOOKS = [
    { value: "plain", swatch: "var(--color-surface)" },
    { value: "sepia", swatch: "#f6efe2" },
    { value: "paper", swatch: "#ffffff" },
    { value: "mint", swatch: "#eef7f2" },
    { value: "slate", swatch: "#1b2430" },
    { value: "midnight", swatch: "#0d1220" },
];

const { t } = useI18n();
const { request } = useRequest();

const query = ref("");
const results = ref([]);
const configured = ref(true);
const searching = ref(false);
const position = ref(50);

watch(
    () => props.show,
    (open) => {
        if (!open) return;

        position.value = Number(props.cover?.position ?? 50);

        // The list is not kept from one opening to the next: nobody looks
        // for the same image twice, and yesterday's grid would look like a
        // result.
        results.value = [];
        query.value = "";
    },
);

async function search() {
    if ("" === query.value.trim() || "" === props.searchPath) return;

    searching.value = true;

    const payload = await request(
        `${props.searchPath}?q=${encodeURIComponent(query.value.trim())}`,
        null,
        { method: HttpMethod.Get, noGuard: true },
    );

    searching.value = false;

    if (!payload) return;

    configured.value = false !== payload.configured;
    results.value = payload.results ?? [];
}

function choose(photo) {
    emit("choose", {
        // The large one rather than the original: a banner two hundred
        // pixels high has no use for an image five thousand wide.
        url: photo.largeUrl || photo.url,
        creditName: photo.authorName || null,
        creditUrl: photo.authorUrl || null,
    });
}

const hasCover = computed(() => Boolean(props.cover?.url));

function applyPosition(value) {
    position.value = Number(value);
    emit("position", position.value);
}
</script>

<template>
    <AppModal
        :show="show"
        max-width="lg"
        :title="t('notes.markdown.cover.title')"
        :icon="Image"
        v-on:close="emit('close')"
    >
        <!-- What the note already carries, with its framing setting: a
             banner shows a strip of a photo that was not taken for it, so
             the cut point must be adjustable. -->
        <div v-if="hasCover" class="mb-4">
            <div class="overflow-hidden rounded-lg border border-line">
                <img
                    :src="cover.url"
                    alt=""
                    class="h-32 w-full object-cover"
                    :style="{ objectPosition: `50% ${position}%` }"
                >
            </div>

            <div class="mt-2 flex items-center gap-3">
                <label class="text-xs text-muted" for="note-cover-position">
                    {{ t('notes.markdown.cover.position') }}
                </label>
                <input
                    id="note-cover-position"
                    type="range"
                    min="0"
                    max="100"
                    step="1"
                    class="flex-1"
                    :value="position"
                    v-on:input="applyPosition($event.target.value)"
                >
                <AppButton variant="ghost" size="sm" v-on:click="emit('remove')">
                    <Trash2 class="h-3.5 w-3.5" :stroke-width="2" />
                    {{ t('notes.markdown.cover.remove') }}
                </AppButton>
            </div>

            <p class="mt-1 text-2xs text-muted">
                {{ t('notes.markdown.cover.autosaved') }}
            </p>
        </div>

        <form class="flex items-center gap-2" v-on:submit.prevent="search">
            <AppInput
                v-model="query"
                class="flex-1"
                :placeholder="t('notes.markdown.cover.search_placeholder')"
            />
            <AppButton
                variant="primary"
                size="md"
                :loading="searching"
                :label="t('shared.common.search')"
                icon-only
                v-on:click="search"
            >
                <Search class="h-4 w-4" :stroke-width="2" />
            </AppButton>
        </form>

        <AppNoData
            v-if="!configured"
            class="mt-4"
            :message="t('notes.markdown.cover.not_configured')"
            :hint="t('notes.markdown.cover.not_configured_hint')"
            :icon="Image"
        />

        <div v-else-if="results.length" class="mt-4 grid grid-cols-2 gap-2 sm:grid-cols-4">
            <button
                v-for="photo in results"
                :key="photo.id"
                type="button"
                class="group overflow-hidden rounded-lg border border-line transition-colors hover:border-accent-500"
                :title="photo.authorName"
                v-on:click="choose(photo)"
            >
                <img :src="photo.thumbUrl" alt="" class="h-20 w-full object-cover" loading="lazy">
                <span class="block truncate px-1.5 py-1 text-left text-2xs text-muted">
                    {{ photo.authorName }}
                </span>
            </button>
        </div>

        <p v-else-if="!searching && '' !== query" class="mt-4 text-sm text-muted">
            {{ t('notes.markdown.cover.search_empty') }}
        </p>

        <!-- The appearance in the same place as the image: both answer
             "what this note looks like", and a second button in the bar for
             six swatches was not worth its place. -->
        <div class="mt-5 border-t border-line pt-4">
            <p class="mb-2 text-xs font-medium text-secondary">
                {{ t('notes.markdown.appearance.title') }}
            </p>

            <div class="flex flex-wrap gap-2">
                <button
                    v-for="look in LOOKS"
                    :key="look.value"
                    type="button"
                    class="flex items-center gap-2 rounded-lg border px-2 py-1.5 text-xs transition-colors"
                    :class="appearance === look.value
                        ? 'border-accent-500 text-primary'
                        : 'border-line text-muted hover:text-primary'"
                    v-on:click="emit('appearance', look.value)"
                >
                    <span
                        class="h-4 w-4 shrink-0 rounded border border-line"
                        :style="{ backgroundColor: look.swatch }"
                    />
                    {{ t(`notes.markdown.appearance.${look.value}`) }}
                </button>
            </div>
        </div>

        <!-- "Fermer" and not "Annuler".

             Nothing here waits to be confirmed: picking a photo, reframing
             it or changing the appearance writes into the note, and the
             autosave takes care of it as for the text - the note's bar says
             "enregistré" when it is done. A button named "Annuler" promised
             a step back it did not take: it only closed. -->
        <template #footer>
            <AppModalFooter>
                <AppButton variant="primary" size="md" v-on:click="emit('close')">
                    <Check class="h-3.5 w-3.5" :stroke-width="2" />
                    {{ t('shared.common.close') }}
                </AppButton>
            </AppModalFooter>
        </template>
    </AppModal>
</template>
