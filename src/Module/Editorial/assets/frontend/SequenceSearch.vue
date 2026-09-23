<script setup>
import { computed, onBeforeUnmount, ref, useTemplateRef, watch } from "vue";
import { useI18n } from "vue-i18n";

/**
 * The search of a documentation, above its table of contents.
 *
 * A summary of a hundred and thirty-four links answers "what is there";
 * it does not answer "where is the thing that mentions unpublishing". So
 * this asks the server, which searches the text of the pages and not only
 * their titles, and it searches *this type of content* rather than the
 * site: an answer from the blog would be an answer beside the question.
 *
 * The summary stays in the page and is hidden rather than replaced, so
 * clearing the field costs nothing and loses no scroll position.
 */
const props = defineProps({
    searchUrl: { type: String, required: true },
    locale: { type: String, required: true },
});

const { t } = useI18n();

const root = useTemplateRef("root");

const query = ref("");
const results = ref([]);
const loading = ref(false);
const searched = ref(false);

/** Two characters find everything and mean nothing. */
const MIN = 2;

const active = computed(() => query.value.trim().length >= MIN);

let pending = 0;

async function run(term) {
    // Each request carries its rank; a slow one that comes back after a
    // newer one is dropped rather than overwriting fresher results.
    pending += 1;
    const rank = pending;
    loading.value = true;

    try {
        const url = new URL(props.searchUrl, window.location.origin);
        url.searchParams.set("q", term);

        const response = await fetch(url, {
            headers: { "X-Requested-With": "XMLHttpRequest" },
        });

        if (!response.ok) return;

        const data = await response.json();

        if (rank !== pending) return;

        results.value = data?.posts ?? [];
        searched.value = true;
    } catch {
        // A search that cannot reach the server leaves the summary in place,
        // which is still a way to find a page.
    } finally {
        if (rank === pending) loading.value = false;
    }
}

let timer = null;

watch(query, (value) => {
    const term = value.trim();

    if (timer) clearTimeout(timer);

    if (term.length < MIN) {
        results.value = [];
        searched.value = false;
        loading.value = false;
        showSummary(true);

        return;
    }

    showSummary(false);
    timer = setTimeout(() => run(term), 250);
});

/**
 * Le sommaire reste rendu par le serveur et ce composant le masque, plutôt
 * que de le redessiner lui-même : ce sont cent trente liens que les moteurs
 * doivent lire et qu'un lecteur sans JavaScript doit pouvoir suivre. Les
 * déplacer dans le composant les ferait disparaître pour les deux.
 */
let summary = null;

function showSummary(visible) {
    // Retenu à la première recherche : au démontage, la référence du
    // composant est déjà rendue et le sommaire resterait masqué, donc perdu
    // pour le lecteur.
    summary ??= root.value
        ?.closest("nav")
        ?.querySelector("[data-sequence-summary]");

    if (summary) summary.hidden = !visible;
}

onBeforeUnmount(() => showSummary(true));

function clear() {
    query.value = "";
}

function urlOf(post) {
    return `/${props.locale}/${post.postTypeSlug}/${post.slug}`;
}
</script>

<template>
    <div ref="root" class="not-prose">
        <div class="relative">
            <input
                v-model="query"
                type="search"
                class="aurora-card w-full px-3 py-2 text-sm text-primary placeholder:text-muted focus:border-accent focus:outline-none"
                :placeholder="t('frontend.sequence.search.placeholder')"
                :aria-label="t('frontend.sequence.search.placeholder')"
            >
        </div>

        <div v-if="active" class="mt-4">
            <p v-if="loading && !searched" class="m-0 text-sm text-muted">
                {{ t("frontend.sequence.search.loading") }}
            </p>

            <template v-else-if="results.length">
                <p class="m-0 mb-3 text-xs uppercase tracking-wide text-muted">
                    {{ t("frontend.sequence.search.count", { count: results.length }, results.length) }}
                </p>
                <ul class="m-0 flex list-none flex-col gap-3 p-0">
                    <li v-for="post in results" :key="post.id" class="m-0">
                        <a class="block no-underline" :href="urlOf(post)">
                            <span class="block text-sm font-medium text-primary">{{ post.title }}</span>
                            <span v-if="post.description" class="mt-0.5 block text-xs text-secondary">
                                {{ post.description }}
                            </span>
                        </a>
                    </li>
                </ul>
            </template>

            <p v-else-if="searched" class="m-0 text-sm text-muted">
                {{ t("frontend.sequence.search.empty", { query: query.trim() }) }}
                <button type="button" class="ml-1 underline" v-on:click="clear">
                    {{ t("frontend.sequence.search.clear") }}
                </button>
            </p>
        </div>
    </div>
</template>
