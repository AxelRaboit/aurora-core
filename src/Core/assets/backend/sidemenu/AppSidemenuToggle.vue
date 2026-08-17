<script setup>
/**
 * The one control that folds and unfolds the side menu.
 *
 * It lives in the page header rather than in the menu, which is where a menu
 * that can disappear has to put it: a control inside the thing it hides has to
 * grow a second copy somewhere else to bring it back, and Aurora had exactly
 * that — a chevron beside the logo to fold, another at the top of the account
 * block to unfold. Two buttons, two places, one gesture.
 *
 * Mounted by `page_header.html.twig` as its own Vue app, so it cannot see the
 * menu's refs. The class on `<html>` is the shared truth and
 * `SIDEMENU_COLLAPSE_EVENT` is how the two mounts stay level — see
 * pattern_cross_mount_state_sync.
 *
 * Presentation only: the state, the persistence and the announcement all live
 * in `useSidemenuCollapse`, beside the menu that also uses them.
 */
import { useI18n } from "vue-i18n";
import { PanelLeft, PanelLeftClose } from "lucide-vue-next";
import { useSidemenuCollapse } from "./composables/useSidemenuCollapse.js";

const props = defineProps({
    /** Where the choice is saved, so it survives the next page. */
    collapsedPath: { type: String, default: "" },
});

const { t } = useI18n();
const { collapsed, toggle } = useSidemenuCollapse(props.collapsedPath);
</script>

<template>
    <button
        type="button"
        class="shrink-0 rounded-lg p-1.5 text-muted transition-colors hover:bg-surface-2 hover:text-primary focus-visible:outline focus-visible:outline-2 focus-visible:outline-accent"
        :aria-pressed="collapsed"
        :title="collapsed ? t('backend.nav.expand_menu') : t('backend.nav.collapse_menu')"
        :aria-label="collapsed ? t('backend.nav.expand_menu') : t('backend.nav.collapse_menu')"
        v-on:click="toggle"
    >
        <!-- The icon shows the state, not the action: an open panel when the
             menu is open. A control that showed what it would do flips under
             the cursor at the moment of clicking, which reads as a glitch. -->
        <PanelLeftClose v-if="!collapsed" class="w-5 h-5" :stroke-width="2" />
        <PanelLeft v-else class="w-5 h-5" :stroke-width="2" />
    </button>
</template>
