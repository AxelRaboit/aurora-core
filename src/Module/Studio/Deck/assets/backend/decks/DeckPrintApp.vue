<script setup>
/**
 * The deck as paper: one slide per page, landscape.
 *
 * **The browser's own print is the export.** dompdf is already a dependency,
 * but its own docblock says what it is for - a legal document with a fixed
 * layout and no JavaScript - and it knows neither flex nor grid, which is why
 * the contract PDF carries a stylesheet built from tables. Rebuilding six
 * layouts a second time in that subset, and keeping the two in agreement,
 * would buy a server-side export for a deck nobody has asked to generate
 * unattended. The browser that drew the slide prints the slide it drew, and
 * "Save as PDF" sits in the same dialog.
 *
 * This page carries no navigation at all, which is why it is a page of its own
 * rather than a print stylesheet over the editor: hiding a back office by
 * selector means knowing its markup, and knowing it again every time it
 * changes.
 */
import { onMounted } from "vue";
import { useI18n } from "vue-i18n";
import SlideFrame from "./components/SlideFrame.vue";

const props = defineProps({
    deck: { type: Object, required: true },
    /** Opened straight from the editor's button: print without a second click. */
    autoPrint: { type: Boolean, default: false },
});

const { t } = useI18n();

onMounted(() => {
    if (!props.autoPrint) return;

    // After paint, so the first page is not an empty frame. `print` is
    // synchronous and blocks, which is why nothing follows it here.
    requestAnimationFrame(() => window.print());
});
</script>

<template>
    <div class="deck-print">
        <header class="deck-print-head">
            <h1>{{ deck.title }}</h1>
            <p>{{ t("backend.studio.decks.print_hint") }}</p>
        </header>

        <div v-for="(slide, at) in deck.slides" :key="slide.id" class="deck-print-page">
            <SlideFrame
                :slide="slide"
                :appearance="deck.appearance"
                :index="at + 1"
            />
        </div>
    </div>
</template>

<style>
/* Non scopé, et il le faut : `@page` est une règle de document, elle n'a pas
   de sélecteur à qui attacher une portée. */
@page {
    size: landscape;
    margin: 0;
}
</style>

<style scoped>
.deck-print {
    display: flex;
    flex-direction: column;
    gap: 1.5rem;
    max-width: 60rem;
    margin: 0 auto;
    padding: 2rem 1rem;
}

.deck-print-head h1 { margin: 0; font-size: 1.25rem; font-weight: 600; }
.deck-print-head p { margin: 0.25rem 0 0; font-size: 0.85rem; opacity: 0.7; }

@media print {
    .deck-print { gap: 0; max-width: none; margin: 0; padding: 0; }

    /* Le titre et la consigne sont pour l'écran : une page imprimée qui porte
       un mode d'emploi de l'impression est une page qu'on ne montre pas. */
    .deck-print-head { display: none; }

    .deck-print-page {
        /* Une slide, une page. `avoid` à l'intérieur, sans quoi une citation
           longue se coupe au milieu d'une phrase. */
        break-after: page;
        break-inside: avoid;
        /* Centrée sur la page : une slide en 16/9 sur une page en 4/3 laisse
           forcément une bande, et la laisser entièrement en bas donne une
           page qui a l'air inachevée plutôt qu'une slide cadrée. */
        height: 100vh;
        display: grid;
        place-items: center;
    }

    .deck-print-page:last-child { break-after: auto; }
}
</style>
