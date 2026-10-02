<script setup>
import { computed } from "vue";
import { useI18n } from "vue-i18n";
import { Eye, FileText, PanelTop } from "lucide-vue-next";
import AppInput from "@/shared/components/form/input/AppInput.vue";
import AppSelect from "@/shared/components/form/select/AppSelect.vue";
import AppTextarea from "@/shared/components/form/input/AppTextarea.vue";
import AppToggle from "@/shared/components/form/toggle/AppToggle.vue";

/**
 * Ce qui entoure le document : son nom, sa langue, ce que dit l'en-tête de sa
 * page, et si le client le voit.
 *
 * La visibilité est ici, en toutes lettres, et pas sous la forme d'un statut :
 * rien ne se programme ni ne se relit, une case décide si le client le lit
 * dans son espace. Les liens de lecture, eux, ouvrent le livrable qu'il soit
 * visible ou non : c'est un envoi décidé à part.
 */
const title = defineModel("title", { type: String, required: true });
const summary = defineModel("summary", { type: String, default: "" });
const locale = defineModel("locale", { type: String, required: true });
const readingHeader = defineModel("readingHeader", { type: Object, required: true });
const visibleToClient = defineModel("visibleToClient", { type: Boolean, default: false });

const props = defineProps({
    locales: { type: Array, default: () => [] },
    errors: { type: Object, default: () => ({}) },
    customerName: { type: String, default: "" },
});

const { t } = useI18n();

/** Le nom de chaque langue dans la sienne : c'est ainsi qu'on la cherche. */
const localeOptions = computed(() =>
    props.locales.map((code) => {
        let label = code.toUpperCase();
        try {
            const name = new Intl.DisplayNames([code], { type: "language" }).of(code);
            if (name) label = name.charAt(0).toUpperCase() + name.slice(1);
        } catch {
            // Un navigateur sans Intl.DisplayNames garde le code.
        }

        return { value: code, label };
    }),
);

function setHeader(key, value) {
    readingHeader.value = { ...readingHeader.value, [key]: value };
}

const preparedFor = computed({
    get: () => readingHeader.value.preparedFor ?? "",
    set: (value) => setHeader("preparedFor", value),
});
const showDate = computed({
    get: () => false !== readingHeader.value.showDate,
    set: (value) => setHeader("showDate", value),
});
const showLogo = computed({
    get: () => false !== readingHeader.value.showLogo,
    set: (value) => setHeader("showLogo", value),
});
</script>

<template>
    <div class="space-y-4">
        <section class="aurora-card space-y-4 p-3 sm:p-5">
            <h3 class="m-0 flex items-center gap-2 text-sm font-semibold text-primary">
                <FileText class="h-4 w-4 text-muted" :stroke-width="2" /> {{ t("backend.studio.space_deliverables.settings.document_title") }}
            </h3>
            <AppInput
                v-model="title"
                :label="t('backend.studio.space_deliverables.title')"
                :placeholder="t('backend.studio.space_deliverables.title_placeholder')"
                :error="errors.title ?? ''"
                required
            />
            <AppTextarea
                v-model="summary"
                :label="t('backend.studio.space_deliverables.settings.summary')"
                :placeholder="t('backend.studio.space_deliverables.settings.summary_placeholder')"
                :hint="t('backend.studio.space_deliverables.settings.summary_hint')"
                :rows="2"
            />
            <AppSelect
                v-if="locales.length > 1"
                v-model="locale"
                :label="t('backend.studio.space_deliverables.settings.locale')"
                :hint="t('backend.studio.space_deliverables.settings.locale_hint')"
                :options="localeOptions"
                :error="errors.locale ?? ''"
            />
        </section>

        <section class="aurora-card space-y-4 p-3 sm:p-5">
            <h3 class="m-0 flex items-center gap-2 text-sm font-semibold text-primary">
                <Eye class="h-4 w-4 text-muted" :stroke-width="2" /> {{ t("backend.studio.space_deliverables.settings.client_title") }}
            </h3>
            <AppToggle
                v-model="visibleToClient"
                :label="t('backend.studio.space_deliverables.settings.visible')"
                :hint="t('backend.studio.space_deliverables.settings.visible_hint')"
            />
        </section>

        <section class="aurora-card space-y-4 p-3 sm:p-5">
            <div>
                <h3 class="m-0 flex items-center gap-2 text-sm font-semibold text-primary">
                    <PanelTop class="h-4 w-4 text-muted" :stroke-width="2" /> {{ t("backend.studio.space_deliverables.settings.header_title") }}
                </h3>
                <p class="m-0 mt-0.5 text-xs text-muted">{{ t("backend.studio.space_deliverables.settings.header_hint") }}</p>
            </div>
            <AppInput
                v-model="preparedFor"
                :label="t('backend.studio.space_deliverables.settings.prepared_for')"
                :placeholder="customerName"
                :hint="t('backend.studio.space_deliverables.settings.prepared_for_hint')"
            />
            <AppToggle v-model="showDate" :label="t('backend.studio.space_deliverables.settings.show_date')" />
            <AppToggle v-model="showLogo" :label="t('backend.studio.space_deliverables.settings.show_logo')" />
        </section>
    </div>
</template>
