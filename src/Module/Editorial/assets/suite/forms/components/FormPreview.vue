<script setup>
import { computed, inject } from "vue";
import { useI18n } from "vue-i18n";
import { Eye } from "lucide-vue-next";
import AppTab from "@/shared/components/nav/AppTab.vue";
import FormRender from "../../../frontend/FormRender.vue";

/**
 * The form as the visitor will see it, while it is being built.
 *
 * **The site's real component, not an imitation.** A mockup drawn separately
 * would end up lying about a detail - an order, a condition, a step - and
 * that is precisely what one comes here to check. It is only mounted in
 * preview mode, which sends nothing.
 *
 * The question being edited already shows in it as it is typed: you see the
 * label change, the choices appear, the condition apply, before saving.
 */
const props = defineProps({
    /** The form in the site's format: one language, flat labels. */
    form: { type: Object, required: true },
});

const { t } = useI18n();
const { locales, editLocale } = inject("formEditor");

/**
 * Remounted on every question change. The site's component prepares its
 * answers when it opens, one slot per question: a question added afterwards
 * would not have its own, and checkboxes without a list to store their
 * choices cannot be ticked.
 */
const renderKey = computed(() => JSON.stringify(props.form.fields.map((field) => [field.id, field.type, field.options, field.step])) + JSON.stringify(props.form.steps));
</script>

<template>
    <div class="aurora-card space-y-4 p-3 sm:p-5">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <h3 class="flex items-center gap-2 text-sm font-semibold text-primary">
                <Eye class="h-4 w-4 text-muted" :stroke-width="2" /> {{ t("suite.forms.preview.title") }}
            </h3>
            <div v-if="locales.length > 1" class="inline-flex gap-1 rounded-lg border border-line bg-surface-2 p-1">
                <AppTab
                    v-for="code in locales"
                    :key="code"
                    size="xs"
                    :active="editLocale === code"
                    active-class="bg-surface text-primary shadow-sm"
                    inactive-class="text-secondary hover:text-primary"
                    v-on:click="editLocale = code"
                >
                    {{ code.toUpperCase() }}
                </AppTab>
            </div>
        </div>

        <p class="m-0 text-xs text-muted">{{ t("suite.forms.preview.hint") }}</p>

        <div class="rounded-lg border border-dashed border-line p-3 sm:p-4">
            <h4 v-if="form.title" class="mb-1 text-base font-semibold text-primary">{{ form.title }}</h4>
            <p v-if="form.description" class="mb-4 text-sm text-secondary">{{ form.description }}</p>
            <p v-if="!form.fields.length" class="m-0 text-sm text-muted">{{ t("suite.forms.preview.empty") }}</p>
            <FormRender
                v-else
                :key="renderKey"
                :form="form"
                submit-path=""
                preview
            />
        </div>
    </div>
</template>
