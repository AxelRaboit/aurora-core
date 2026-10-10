<script setup>
/**
 * The site's logo in the suite, in the mode the suite is shown in.
 *
 * Three cases, one place: the logo uploaded in Réglages > Image de marque,
 * its dark-mode version when one is set (10/10/2026), and Aurora's own mark
 * when the site has none; a dark version alone replaces that mark in dark
 * mode only. The side menu and the phone bar drew the first and the last
 * each on their own; the dark version would have made that two copies of
 * three branches.
 *
 * Both pictures are in the page and CSS shows one: the `dark` variant follows
 * the class the light/dark button puts on `<html>`, so switching mode swaps
 * the logo at once, with no script watching it.
 */
import AppLogo from "@/shared/components/display/AppLogo.vue";

defineProps({
    /** The site's logo, from `logo_media_id`; empty draws Aurora's mark. */
    url: { type: String, default: "" },
    /** The dark-mode version, from `logo_dark_media_id`; empty keeps the light one in both modes. */
    darkUrl: { type: String, default: "" },
    size: { type: Number, default: 28 },
});
</script>

<template>
    <span class="inline-flex shrink-0" :style="{ width: `${size}px`, height: `${size}px` }">
        <img
            v-if="url"
            data-site-logo-light
            :src="url"
            alt="Logo"
            class="h-full w-full object-contain"
            :class="darkUrl ? 'dark:hidden' : ''"
        >
        <AppLogo v-else :size="size" :class="darkUrl ? 'dark:hidden' : ''" />
        <img
            v-if="darkUrl"
            data-site-logo-dark
            :src="darkUrl"
            alt="Logo"
            class="hidden h-full w-full object-contain dark:block"
        >
    </span>
</template>
