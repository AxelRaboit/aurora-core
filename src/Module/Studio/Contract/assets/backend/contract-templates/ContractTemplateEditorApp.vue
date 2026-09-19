<script setup>
import { computed, ref } from "vue";
import { useI18n } from "vue-i18n";
import { useRequest } from "@/shared/composables/http/backend/useRequest.js";
import { safeContractHtml } from "../shared/contractHtml.js";
import { useContractTemplateEditor } from "./composables/useContractTemplateEditor.js";
import ContractVariablePanel from "./components/ContractVariablePanel.vue";
import AppBlockEditor from "@/shared/components/editor/AppBlockEditor.vue";
import AppButton from "@/shared/components/action/AppButton.vue";
import AppPageActions from "@/shared/components/action/AppPageActions.vue";
import AppInput from "@/shared/components/form/input/AppInput.vue";
import AppSelect from "@/shared/components/form/select/AppSelect.vue";
import AppMessage from "@/shared/components/feedback/AppMessage.vue";
import AppModal from "@/shared/components/overlay/AppModal.vue";
import AppModalFooter from "@/shared/components/overlay/AppModalFooter.vue";
import { Check, Eye, Lock, Save, ScrollText, Trash2, X } from "lucide-vue-next";

const { t } = useI18n();
const { request } = useRequest();

const props = defineProps({
    template: { type: Object, required: true },
    version: { type: Object, required: true },
    versions: { type: Array, default: () => [] },
    locales: { type: Array, default: () => [] },
    variableGroups: { type: Array, default: () => [] },
    savePath: { type: String, required: true },
    publishPath: { type: String, required: true },
    discardPath: { type: String, required: true },
    indexPath: { type: String, required: true },
    editorPath: { type: String, required: true },
    previewPath: { type: String, required: true },
});

const {
    version,
    isPublished,
    locales,
    activeLocale,
    wording,
    switchLocale,
    writtenLocales,
    governingLocale,
    governingOptions,
    needsGoverningLocale,
    canPublish,
    saving,
    publishing,
    errors,
    showPublish,
    showDiscard,
    save,
    publish,
    discard,
    versionPath,
} = useContractTemplateEditor(props);

const otherVersions = computed(() =>
    props.versions.filter((each) => each.id !== version.value.id),
);

/**
 * The wording with its variables filled, fetched rather than assembled here.
 *
 * The substitution is the application's, and a second implementation in
 * JavaScript would be a second answer to "what will the client read" - the one
 * that matters being the one the freeze uses. So the server renders, and this
 * shows what came back.
 *
 * Saved first when there is something to save: previewing the draft as it
 * stands on screen rather than as it was stored an hour ago is the whole
 * point, and a published version has nothing to save.
 */
const preview = ref({ open: false, loading: false, html: "", error: "", locale: "" });

/**
 * Everything but Save, which is what a draft editor is for.
 *
 * Publishing sits here rather than beside it, and loses nothing by it: it is
 * done once per version, it opens a confirmation of its own, and a menu row has
 * the width to carry the sentence that says what it costs. Discarding is last,
 * as everywhere.
 *
 * A published version can only be read, so all that is left of this list is the
 * preview.
 */
const templateActions = computed(() => {
    const actions = [
        {
            key: "preview",
            icon: Eye,
            title: t("backend.studio.contract_templates.preview"),
            loading: preview.value.loading && !preview.value.open,
            onSelect: openPreview,
        },
    ];

    if (!isPublished.value) {
        actions.push({
            key: "publish",
            color: "emerald",
            icon: Check,
            title: t("backend.studio.contract_templates.publish"),
            disabled: !canPublish.value,
            onSelect: () => (showPublish.value = true),
        });
        actions.push({
            key: "discard",
            color: "rose",
            icon: Trash2,
            title: t("backend.studio.contract_templates.discard"),
            onSelect: () => (showDiscard.value = true),
        });
    }

    return actions;
});

/** Cleaned on the way into the DOM, exactly like the sealed document is. */
const previewHtml = computed(() => safeContractHtml(preview.value.html));

/** Only offered when there is a choice to make. */
const previewLocales = computed(() =>
    props.locales.filter((locale) => writtenLocales.value.includes(locale.code)),
);

async function openPreview() {
    preview.value = { ...preview.value, open: true, loading: true, error: "" };

    if (!isPublished.value) {
        await save({ silent: true });
    }

    await loadPreview(preview.value.locale || activeLocale.value);
}

async function loadPreview(locale) {
    preview.value = { ...preview.value, loading: true, error: "" };

    const data = await request(`${props.previewPath}?locale=${encodeURIComponent(locale)}`, {
        method: "GET",
    });

    if (!data?.success) {
        preview.value = {
            ...preview.value,
            loading: false,
            html: "",
            error:
                data?.errors?.preview ??
                t("backend.studio.contract_templates.preview_failed"),
        };

        return;
    }

    preview.value = {
        ...preview.value,
        loading: false,
        html: data.html ?? "",
        locale: data.locale ?? locale,
        error: "",
    };
}

const governingSelectOptions = computed(() =>
    governingOptions.value.map((locale) => ({
        value: locale.code,
        label: locale.label,
    })),
);

/** The answer, in words, for a published version that can no longer be asked. */
const governingLabel = computed(
    () =>
        props.locales.find((locale) => locale.code === governingLocale.value)
            ?.label ?? null,
);
</script>

<template>
    <div class="space-y-4">
        <!-- A published version is readable but not writable, and the page says
             so before the reader tries. Hiding the fields instead would leave
             them wondering where the text went. -->
        <AppMessage v-if="isPublished" variant="info">
            <span class="flex items-center gap-2">
                <Lock class="w-4 h-4 shrink-0" :stroke-width="2" />
                {{
                    t("backend.studio.contract_templates.published_notice", {
                        number: version.number,
                    })
                }}
            </span>
        </AppMessage>

        <AppMessage v-if="errors.version" variant="danger">
            {{ errors.version }}
        </AppMessage>
        <AppMessage v-if="errors.translations" variant="danger">
            {{ errors.translations }}
        </AppMessage>

        <div class="flex flex-wrap items-center justify-between gap-3">
            <div class="flex flex-wrap items-baseline gap-2">
                <h1 class="text-lg font-semibold text-primary">
                    {{ template.name }}
                </h1>
                <span class="text-sm text-muted">
                    {{
                        t("backend.studio.contract_templates.version_label", {
                            number: version.number,
                        })
                    }}
                </span>
                <span
                    class="text-xs px-2 py-0.5 rounded-full border"
                    :class="
                        isPublished
                            ? 'border-emerald-500/40 text-emerald-500'
                            : 'border-amber-500/40 text-amber-500'
                    "
                >
                    {{
                        isPublished
                            ? t("backend.studio.contract_templates.state_published")
                            : t("backend.studio.contract_templates.state_draft")
                    }}
                </span>
            </div>

            <div class="flex flex-wrap items-center gap-2">
                <AppButton variant="ghost" size="md" :href="indexPath">
                    {{ t("shared.common.back") }}
                </AppButton>
                <AppPageActions
                    :actions="templateActions"
                    :label="template.name"
                    variant="ghost"
                    :busy="preview.loading && !preview.open"
                />
                <!-- Promoted from secondary: it is now the only button on the
                     row that does something to the draft. -->
                <AppButton
                    v-if="!isPublished"
                    variant="primary"
                    size="md"
                    :loading="saving"
                    v-on:click="save"
                >
                    <Save class="w-3.5 h-3.5" :stroke-width="2" />
                    {{ t("shared.common.save") }}
                </AppButton>
            </div>
        </div>

        <div class="grid gap-4 lg:grid-cols-[minmax(0,1fr)_18rem]">
            <div class="min-w-0 space-y-3">
                <!-- Language tabs. Each carries a dot when it has wording, so
                     "which languages does this version actually have" is
                     answered without opening all three. -->
                <div
                    class="flex flex-wrap gap-1 border-b border-line/60"
                    role="tablist"
                >
                    <button
                        v-for="locale in locales"
                        :key="locale.code"
                        type="button"
                        role="tab"
                        :aria-selected="locale.code === activeLocale"
                        class="px-3 py-2 text-sm border-b-2 -mb-px transition-colors flex items-center gap-1.5"
                        :class="
                            locale.code === activeLocale
                                ? 'border-accent-500 text-primary'
                                : 'border-transparent text-muted hover:text-primary'
                        "
                        v-on:click="switchLocale(locale.code)"
                    >
                        {{ locale.label }}
                        <span
                            v-if="writtenLocales.includes(locale.code)"
                            class="w-1.5 h-1.5 rounded-full bg-emerald-500"
                            :title="t('backend.studio.contract_templates.locale_written')"
                        />
                    </button>
                </div>

                <template v-for="locale in locales" :key="locale.code">
                    <div v-show="locale.code === activeLocale" class="space-y-3">
                        <AppInput
                            v-model="wording[locale.code].title"
                            :label="t('backend.studio.contract_templates.document_title')"
                            :placeholder="
                                t('backend.studio.contract_templates.document_title_placeholder')
                            "
                            :hint="t('backend.studio.contract_templates.document_title_hint')"
                            :readonly="isPublished"
                        />
                        <div
                            class="rounded-lg border border-line bg-surface p-3"
                            :class="{ 'opacity-70 pointer-events-none': isPublished }"
                        >
                            <AppBlockEditor
                                v-model="wording[locale.code].blocks"
                                :placeholder="
                                    t('backend.studio.contract_templates.content_placeholder')
                                "
                            />
                        </div>
                    </div>
                </template>
            </div>

            <div class="space-y-4">
                <!-- Which language prevails. Asked here rather than at
                     publication time, because it is a decision about the
                     wording somebody is writing, not a step in a dialog. -->
                <div class="rounded-lg border border-line bg-surface p-3 space-y-2">
                    <p class="text-xs font-medium uppercase tracking-wider text-muted">
                        {{ t("backend.studio.contract_templates.governing_locale") }}
                    </p>
                    <AppSelect
                        v-if="!isPublished"
                        :model-value="governingLocale ?? ''"
                        :options="governingSelectOptions"
                        :placeholder="t('backend.studio.contract_templates.governing_locale_none')"
                        :hint="t('backend.studio.contract_templates.governing_locale_hint')"
                        :error="errors.governingLocale"
                        v-on:update:model-value="governingLocale = $event === '' ? null : $event"
                    />
                    <p v-else class="text-sm text-secondary">
                        {{
                            governingLabel
                                ?? t("backend.studio.contract_templates.governing_locale_none")
                        }}
                    </p>
                    <!-- Said here, next to the field, rather than only by a
                         disabled publish button on the other side of the page. -->
                    <p v-if="needsGoverningLocale" class="text-xs text-amber-500">
                        {{ t("backend.studio.contract_templates.governing_locale_needed") }}
                    </p>
                </div>

                <ContractVariablePanel :groups="variableGroups" />

                <div
                    v-if="otherVersions.length"
                    class="rounded-lg border border-line bg-surface p-3 space-y-2"
                >
                    <p class="text-xs font-medium uppercase tracking-wider text-muted">
                        {{ t("backend.studio.contract_templates.other_versions") }}
                    </p>
                    <ul class="space-y-1">
                        <li v-for="each in otherVersions" :key="each.id">
                            <a
                                :href="versionPath(each.id)"
                                class="text-sm text-secondary hover:text-primary flex items-center gap-2"
                            >
                                <ScrollText class="w-3.5 h-3.5 shrink-0" :stroke-width="2" />
                                {{
                                    t("backend.studio.contract_templates.version_label", {
                                        number: each.number,
                                    })
                                }}
                                <span class="text-xs text-muted">
                                    {{
                                        each.isPublished
                                            ? t("backend.studio.contract_templates.state_published")
                                            : t("backend.studio.contract_templates.state_draft")
                                    }}
                                </span>
                            </a>
                        </li>
                    </ul>
                </div>
            </div>
        </div>

        <!-- The wording as the client will read it. Wide, because a contract
             read in a narrow column is not the contract: line length is part
             of what an author is checking. -->
        <AppModal
            :show="preview.open"
            max-width="4xl"
            :title="t('backend.studio.contract_templates.preview')"
            :icon="Eye"
            v-on:close="preview.open = false"
        >
            <div class="space-y-3">
                <!-- Said before the document rather than after it: a reader who
                     takes these example values for real ones would be reading a
                     contract that does not exist. -->
                <AppMessage variant="info">
                    {{ t("backend.studio.contract_templates.preview_notice") }}
                </AppMessage>

                <div
                    v-if="previewLocales.length > 1"
                    class="flex flex-wrap gap-1 border-b border-line/60"
                    role="tablist"
                >
                    <button
                        v-for="locale in previewLocales"
                        :key="locale.code"
                        type="button"
                        role="tab"
                        :aria-selected="locale.code === preview.locale"
                        class="px-3 py-2 text-sm border-b-2 -mb-px transition-colors"
                        :class="
                            locale.code === preview.locale
                                ? 'border-accent text-primary'
                                : 'border-transparent text-muted hover:text-primary'
                        "
                        v-on:click="loadPreview(locale.code)"
                    >
                        {{ locale.label }}
                    </button>
                </div>

                <AppMessage v-if="preview.error" variant="danger">
                    {{ preview.error }}
                </AppMessage>

                <p v-else-if="preview.loading" class="text-sm text-muted">
                    {{ t("shared.common.loading") }}
                </p>

                <article
                    v-else
                    class="bg-surface border border-line rounded-lg p-4 sm:p-6 prose-contract max-h-[65vh] overflow-y-auto"
                    v-html="previewHtml"
                />
            </div>
            <template #footer>
                <AppModalFooter>
                    <AppButton variant="ghost" size="md" v-on:click="preview.open = false">
                        <X class="w-3.5 h-3.5" :stroke-width="2" />
                        {{ t("shared.common.close") }}
                    </AppButton>
                </AppModalFooter>
            </template>
        </AppModal>

        <AppModal
            :show="showPublish"
            max-width="md"
            :closeable="false"
            :title="t('backend.studio.contract_templates.publish')"
            :icon="Check"
            v-on:close="showPublish = false"
        >
            <p class="text-sm text-primary">
                {{
                    t("backend.studio.contract_templates.publish_confirm", {
                        number: version.number,
                    })
                }}
            </p>
            <!-- The consequence, stated before the click rather than discovered
                 after it: this is the one action on the page that cannot be
                 undone. -->
            <p class="text-sm text-secondary">
                {{ t("backend.studio.contract_templates.publish_warning") }}
            </p>
            <template #footer>
                <AppModalFooter>
                    <AppButton variant="ghost" size="md" v-on:click="showPublish = false">
                        <X class="w-3.5 h-3.5" :stroke-width="2" />
                        {{ t("shared.common.cancel") }}
                    </AppButton>
                    <AppButton
                        variant="primary"
                        size="md"
                        :loading="publishing || saving"
                        v-on:click="publish"
                    >
                        <Check class="w-3.5 h-3.5" :stroke-width="2" />
                        {{ t("backend.studio.contract_templates.publish") }}
                    </AppButton>
                </AppModalFooter>
            </template>
        </AppModal>

        <AppModal
            :show="showDiscard"
            max-width="sm"
            :closeable="false"
            :title="t('backend.studio.contract_templates.discard')"
            :icon="Trash2"
            v-on:close="showDiscard = false"
        >
            <p class="text-sm text-primary">
                {{
                    t("backend.studio.contract_templates.discard_confirm", {
                        number: version.number,
                    })
                }}
            </p>
            <template #footer>
                <AppModalFooter>
                    <AppButton variant="ghost" size="md" v-on:click="showDiscard = false">
                        <X class="w-3.5 h-3.5" :stroke-width="2" />
                        {{ t("shared.common.cancel") }}
                    </AppButton>
                    <AppButton variant="danger" size="md" v-on:click="discard">
                        <Trash2 class="w-3.5 h-3.5" :stroke-width="2" />
                        {{ t("backend.studio.contract_templates.discard") }}
                    </AppButton>
                </AppModalFooter>
            </template>
        </AppModal>
    </div>
</template>
