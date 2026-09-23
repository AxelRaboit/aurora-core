<script setup>
/**
 * Choisir le bandeau d'une note, chez Pexels, sans rien télécharger.
 *
 * **L'image ne passe pas par la médiathèque, et c'est voulu.** Une photo
 * décorative n'a pas de vie propre : personne ne la cherchera, ne la
 * renommera, ne la rangera dans un dossier. La verser en GED la mettrait
 * dans la même liste que les contrats et les factures, où elle ne ferait que
 * du bruit. La note garde l'adresse de l'image et le crédit de son auteur ;
 * si elle disparaît un jour de chez eux, on en choisit une autre.
 *
 * Le crédit n'est pas une politesse : la licence Pexels demande de nommer le
 * photographe, et hors de la médiathèque il n'y a plus de fiche pour le
 * porter à notre place.
 *
 * La recherche passe par notre serveur, comme celle de la médiathèque : la
 * clé d'API reste côté serveur.
 */
import { computed, ref, watch } from "vue";
import { useI18n } from "vue-i18n";
import { Check, Image, Search, Trash2 } from "lucide-vue-next";
import AppModal from "@/shared/components/overlay/AppModal.vue";
import AppModalFooter from "@/shared/components/overlay/AppModalFooter.vue";
import AppButton from "@/shared/components/action/AppButton.vue";
import AppInput from "@/shared/components/form/input/AppInput.vue";
import AppNoData from "@/shared/components/feedback/AppNoData.vue";
import { useRequest } from "@/shared/composables/http/backend/useRequest.js";
import { HttpMethod } from "@/shared/utils/http/httpMethod.js";

const props = defineProps({
    show: { type: Boolean, default: false },
    searchPath: { type: String, default: "" },
    /** Ce que la note porte déjà : `{url, creditName, creditUrl, position}`. */
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
 * Les apparences, dans l'ordre où on les essaie.
 *
 * Les noms viennent de l'enum du serveur : ajouter une apparence là-bas et
 * l'oublier ici la rendrait injoignable, donc la liste est courte et se lit
 * d'un coup d'œil à côté du `case` qui la déclare.
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

        // La liste ne se garde pas d'une ouverture à l'autre : on ne
        // cherche pas deux fois la même image, et une grille de la veille
        // ferait croire à un résultat.
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
        // La grande plutôt que l'originale : un bandeau de deux cents pixels
        // de haut n'a rien à faire d'une image de cinq mille de large.
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
        <!-- Ce que la note porte déjà, avec son réglage de cadrage : un
             bandeau montre une bande d'une photo qui n'a pas été prise pour
             ça, donc le point de coupe doit être réglable. -->
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
            <AppButton variant="primary" size="md" :loading="searching" v-on:click="search">
                <Search class="h-3.5 w-3.5" :stroke-width="2" />
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

        <!-- L'apparence au même endroit que l'image : les deux répondent à
             « de quoi cette note a l'air », et un second bouton dans la barre
             pour six pastilles ne valait pas sa place. -->
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

        <!-- « Fermer » et non « Annuler ».
             
             Rien ici n'attend d'être validé : choisir une photo, la recadrer
             ou changer d'apparence écrit dans la note, et l'enregistrement
             automatique s'en charge comme pour le texte - la barre de la
             note dit « enregistré » quand c'est fait. Un bouton nommé
             « Annuler » promettait un retour en arrière qu'il ne faisait
             pas : il ne faisait que fermer. -->
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
