<script setup>
/**
 * The placeholders a template may carry.
 *
 * A panel with copy buttons rather than an editor tool that inserts at the
 * caret. The tool is the nicer gesture and it is worth building later; what
 * matters first is that the tokens are visible at all, because the failure
 * this replaces is somebody typing `{{client.siret}}` from memory and
 * discovering in a signed PDF that the token was `customer.siret` and never
 * resolved.
 *
 * Each row shows what the value looks like. A token list without an example
 * leaves the writer guessing whether a date arrives as 08/09/2026 or
 * 2026-09-08, which is the same class of discovery.
 */
import { ref } from "vue";
import { useI18n } from "vue-i18n";
import { Copy, Check } from "lucide-vue-next";

const props = defineProps({
    groups: { type: Array, default: () => [] },
});

const { t } = useI18n();
const copied = ref(null);

/**
 * The token as it is written in a document.
 *
 * Built here rather than in the template: a literal pair of braces inside a
 * Vue interpolation is read as the start of another one, and the file stops
 * parsing.
 */
function tokenText(token) {
    return `{{${token}}}`;
}

/** Same reason as `tokenText`: the braces cannot be typed in the template. */
const customExample = tokenText("contract.custom.ma_cle");

async function copy(token) {
    try {
        await navigator.clipboard.writeText(tokenText(token));
        copied.value = token;
        setTimeout(() => {
            if (copied.value === token) copied.value = null;
        }, 1600);
    } catch {
        // A clipboard the browser refuses (no permission, insecure context) is
        // not an error worth a toast: the token is written on screen and can be
        // selected by hand, which is what happens anyway.
        copied.value = null;
    }
}
</script>

<template>
    <div class="aurora-card p-3 space-y-3">
        <div class="space-y-1">
            <p class="text-xs font-medium uppercase tracking-wider text-muted">
                {{ t("backend.studio.contract_templates.variables.title") }}
            </p>
            <p class="text-xs text-muted">
                {{ t("backend.studio.contract_templates.variables.hint") }}
            </p>
        </div>

        <div v-for="group in props.groups" :key="group.group" class="space-y-1.5">
            <p class="text-xs font-medium text-secondary">
                {{ t(group.labelKey) }}
            </p>
            <ul class="space-y-0.5">
                <li
                    v-for="variable in group.variables"
                    :key="variable.token"
                    class="group flex items-start gap-2 rounded px-1.5 py-1 hover:bg-surface-2/60"
                >
                    <button
                        type="button"
                        class="mt-0.5 shrink-0 text-muted hover:text-primary"
                        :title="t('shared.common.copy')"
                        v-on:click="copy(variable.token)"
                    >
                        <Check
                            v-if="copied === variable.token"
                            class="w-3.5 h-3.5 text-emerald-500"
                            :stroke-width="2"
                        />
                        <Copy v-else class="w-3.5 h-3.5" :stroke-width="2" />
                    </button>
                    <span class="min-w-0">
                        <code class="block text-2xs font-mono text-primary break-all">
                            {{ tokenText(variable.token) }}
                        </code>
                        <span class="block text-2xs text-muted">
                            {{ t(variable.labelKey) }} · {{ variable.example }}
                        </span>
                    </span>
                </li>
            </ul>
        </div>

        <!-- The escape hatch, documented where somebody writing a clause will
             look for it: a blank that varies per contract has no catalogue
             variable and does not need one. -->
        <div class="rounded-lg border border-dashed border-line bg-surface p-3 space-y-1">
            <p class="text-2xs font-medium uppercase tracking-wider text-muted">
                {{ t("backend.studio.contract_templates.variables.custom") }}
            </p>
            <code class="block text-2xs font-mono text-primary break-all">
                {{ customExample }}
            </code>
            <p class="text-2xs text-muted">
                {{ t("backend.studio.contract_templates.variables.custom_hint") }}
            </p>
        </div>
    </div>
</template>
