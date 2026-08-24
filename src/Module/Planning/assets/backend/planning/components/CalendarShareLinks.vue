<script setup>
/**
 * The addresses that reach this calendar without an account.
 *
 * Its own component because the calendar modal was already long and this is a
 * self-contained block with a form of its own - `convention_sfc_thin_presentation`
 * again. The requests are the parent's; this draws and asks.
 *
 * Replaces the feed section, which could show one address and nothing about it.
 * What somebody actually needs a week later is which link went to whom, whether it
 * still works, and whether it has ever been opened - none of which a column on the
 * calendar had room for.
 */
import { computed, ref } from "vue";
import { useI18n } from "vue-i18n";
import { Copy, Link2, Rss } from "lucide-vue-next";
import AppButton from "@/shared/components/action/AppButton.vue";
import AppInput from "@/shared/components/form/input/AppInput.vue";
import AppSelect from "@/shared/components/form/select/AppSelect.vue";
import AppDatePicker from "@/shared/components/form/picker/AppDatePicker.vue";
import AppNoData from "@/shared/components/feedback/AppNoData.vue";
import { useClipboard } from "@/shared/composables/useClipboard.js";

const props = defineProps({
    /** Links pointing at the open calendar, already filtered by the parent. */
    links: { type: Array, default: () => [] },
    errors: { type: Object, default: () => ({}) },
    saving: { type: Boolean, default: false },
});

const emit = defineEmits(["create", "revoke"]);

const { t, d } = useI18n();
const { copy } = useClipboard();

const MODES = ["web", "ics"];

const form = ref({ label: "", mode: "web", expiresAt: "" });

const modeOptions = computed(() =>
    MODES.map((value) => ({ value, label: t(`backend.plannings.links.mode_${value}`) })),
);

/**
 * Live links first, then the closed ones.
 *
 * Both are shown: a revoked address is the answer to "did we close that, and
 * when", and hiding it turns the list into something that only ever grows.
 */
const ordered = computed(() =>
    [...props.links].sort((a, b) => Number(b.usable) - Number(a.usable)),
);

function submit() {
    emit("create", { ...form.value });
}

/** Cleared by the parent once the request came back, through `v-model`-less reset. */
function reset() {
    form.value = { label: "", mode: "web", expiresAt: "" };
}

defineExpose({ reset });

/**
 * When it stops working, or that it does not.
 *
 * A feed with no expiry is the normal case and has to read as deliberate rather
 * than as a missing value - a phone polling it for years must not have it close
 * underneath.
 */
function expiryLabel(link) {
    if (null !== link.revokedAt) {
        return t("backend.plannings.links.revoked_on", {
            date: d(new Date(link.revokedAt), { dateStyle: "medium" }),
        });
    }

    if (null === link.expiresAt) {
        return t("backend.plannings.links.no_expiry");
    }

    return t(link.usable ? "backend.plannings.links.expires_on" : "backend.plannings.links.expired_on", {
        date: d(new Date(link.expiresAt), { dateStyle: "medium", timeStyle: "short" }),
    });
}
</script>

<template>
    <div class="flex flex-col gap-2.5 border-t border-line pt-3">
        <div>
            <span class="text-sm font-medium text-primary">{{ t("backend.plannings.links.label") }}</span>
            <p class="text-xs text-muted">{{ t("backend.plannings.links.hint") }}</p>
        </div>

        <AppNoData v-if="!ordered.length" :message="t('backend.plannings.links.empty')" />

        <div v-else class="flex flex-col gap-2">
            <div
                v-for="link in ordered"
                :key="link.id"
                class="rounded-lg border border-line bg-surface-2 p-2.5"
                :class="link.usable ? '' : 'opacity-60'"
            >
                <div class="flex items-start gap-2">
                    <component
                        :is="'ics' === link.mode ? Rss : Link2"
                        class="mt-0.5 h-3.5 w-3.5 shrink-0 text-muted"
                        :stroke-width="2"
                        :aria-label="link.modeLabel"
                    />
                    <div class="min-w-0 flex-1">
                        <p class="truncate text-sm text-primary">{{ link.label }}</p>
                        <p class="text-2xs text-muted">
                            {{ expiryLabel(link) }}
                            <!-- Never opened is the useful half of this: it is what
                                 says a link can be closed without asking around. -->
                            <template v-if="link.usable">
                                &middot;
                                {{ link.lastUsedAt
                                    ? t("backend.plannings.links.last_used", {
                                        date: d(new Date(link.lastUsedAt), { dateStyle: "medium" }),
                                    })
                                    : t("backend.plannings.links.never_used") }}
                            </template>
                        </p>
                        <!-- Several calendars is the thing the old column could not
                             do, so the row has to say when a link carries more than
                             the one being looked at. -->
                        <p v-if="link.calendars.length > 1" class="text-2xs text-muted">
                            {{ t("backend.plannings.links.also_covers", { count: link.calendars.length - 1 }) }}
                        </p>
                    </div>
                    <AppButton
                        v-if="link.usable"
                        variant="ghost"
                        size="sm"
                        class="shrink-0"
                        v-on:click="emit('revoke', link)"
                    >
                        {{ t("backend.plannings.links.revoke") }}
                    </AppButton>
                </div>

                <!-- The address, on a live link only. A revoked one is shown so it
                     can be accounted for; handing its secret back would put a dead
                     credential in the page for no purpose. -->
                <div v-if="link.url" class="mt-2 flex items-center gap-1.5">
                    <input
                        :value="link.url"
                        readonly
                        class="min-w-0 flex-1 rounded-lg border border-line bg-surface px-2.5 py-1.5 font-mono text-xs text-secondary"
                        v-on:focus="$event.target.select()"
                    >
                    <AppButton
                        variant="ghost"
                        size="sm"
                        class="shrink-0"
                        :title="t('shared.common.copy')"
                        v-on:click="copy(link.url)"
                    >
                        <Copy class="h-3.5 w-3.5" :stroke-width="2" />
                    </AppButton>
                </div>
            </div>
        </div>

        <!-- Making one. Inline rather than behind another modal: it is three fields,
             and a modal over a modal is a stack nobody wants to escape out of. -->
        <div class="flex flex-col gap-2 rounded-lg border border-dashed border-line p-2.5">
            <AppInput
                v-model="form.label"
                :label="t('backend.plannings.links.new_label')"
                :placeholder="t('backend.plannings.links.new_label_placeholder')"
                :hint="t('backend.plannings.links.new_label_hint')"
                :error="errors.label"
            />
            <div class="grid grid-cols-1 gap-2 sm:grid-cols-2">
                <AppSelect
                    v-model="form.mode"
                    :label="t('backend.plannings.links.mode')"
                    :options="modeOptions"
                />
                <AppDatePicker
                    v-model="form.expiresAt"
                    enable-time
                    :label="t('backend.plannings.links.expires')"
                    :placeholder="t('backend.plannings.links.expires_placeholder')"
                    :hint="t('backend.plannings.links.expires_hint')"
                    :error="errors.expiresAt"
                />
            </div>
            <AppButton
                variant="secondary"
                size="sm"
                class="self-start"
                :loading="saving"
                :disabled="!form.label"
                v-on:click="submit"
            >
                <Link2 class="h-3.5 w-3.5" :stroke-width="2" />
                {{ t("backend.plannings.links.create") }}
            </AppButton>
        </div>
    </div>
</template>
