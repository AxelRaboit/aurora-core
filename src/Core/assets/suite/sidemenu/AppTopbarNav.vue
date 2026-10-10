<script setup>
/**
 * Back, forward and reload, in the page header.
 *
 * Browser chrome brought into the page, the way Arc puts it beside the tab
 * strip: the suite is used as an application, and an application that hides
 * its own history behind the browser's toolbar is one gesture further from
 * everything.
 *
 * Presentation only - what each one actually does, and what it honestly can and
 * cannot do, lives in `useTopbarNavigation`.
 */
import { useI18n } from "vue-i18n";
import { ArrowLeft, ArrowRight, RotateCw } from "lucide-vue-next";
import { useTopbarNavigation } from "./composables/useTopbarNavigation.js";
import { TOPBAR_BUTTON, TOPBAR_ICON } from "./topbarButton.js";

const { t } = useI18n();
const { canGoBack, back, forward, reloading, hardReload } = useTopbarNavigation();

</script>

<template>
    <div class="flex items-center gap-1.5">
        <button
            type="button"
            :class="TOPBAR_BUTTON"
            :disabled="!canGoBack"
            :title="t('suite.nav.go_back')"
            :aria-label="t('suite.nav.go_back')"
            v-on:click="back"
        >
            <ArrowLeft :class="TOPBAR_ICON" :stroke-width="2" />
        </button>

        <!-- Never disabled: nothing in the platform says whether a forward
             entry exists, so greying this out would be a guess wearing the
             costume of knowledge. It is occasionally a no-op instead. -->
        <button
            type="button"
            :class="TOPBAR_BUTTON"
            :title="t('suite.nav.go_forward')"
            :aria-label="t('suite.nav.go_forward')"
            v-on:click="forward"
        >
            <ArrowRight :class="TOPBAR_ICON" :stroke-width="2" />
        </button>

        <button
            type="button"
            :class="TOPBAR_BUTTON"
            :disabled="reloading"
            :title="t('suite.nav.hard_reload')"
            :aria-label="t('suite.nav.hard_reload')"
            v-on:click="hardReload"
        >
            <RotateCw :class="[TOPBAR_ICON, { 'animate-spin': reloading }]" :stroke-width="2" />
        </button>
    </div>
</template>
