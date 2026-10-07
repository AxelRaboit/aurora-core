<script setup>
import AppGuide from "@/shared/components/feedback/AppGuide.vue";
import { HttpMethod } from "@/shared/utils/http/httpMethod.js";
import { computed, onBeforeUnmount, onMounted, ref } from "vue";
import { useI18n } from "vue-i18n";
import { useRequest } from "@/shared/composables/http/suite/useRequest.js";
import { usePrivileges } from "@/shared/composables/usePrivileges.js";
import { buildPath } from "@/shared/utils/http/buildPath.js";
import { toast } from "vue-sonner";
import { safeContractHtml } from "../shared/contractHtml.js";
import { useContractTemplateEditor } from "./composables/useContractTemplateEditor.js";
import ContractVariablePanel from "./components/ContractVariablePanel.vue";
import AppBlockEditor from "@/shared/components/editor/AppBlockEditor.vue";
import AppButton from "@/shared/components/action/AppButton.vue";
import AppPageActions from "@/shared/components/action/AppPageActions.vue";
import AppPageBar from "@/shared/components/nav/AppPageBar.vue";
import AppInput from "@/shared/components/form/input/AppInput.vue";
import AppSelect from "@/shared/components/form/select/AppSelect.vue";
import AppMessage from "@/shared/components/feedback/AppMessage.vue";
import AppBadge from "@/shared/components/feedback/AppBadge.vue";
import AppModal from "@/shared/components/overlay/AppModal.vue";
import AppModalFooter from "@/shared/components/overlay/AppModalFooter.vue";
import { Archive, Check, Eye, FilePlus2, Lock, Save, ScrollText, Trash2, X } from "lucide-vue-next";

/**
 * What a preview value can be: a made-up example, real data (your settings),
 * or a field to fill in for each contract. The pills reuse the backgrounds
 * the document gives the same values.
 */
const VALUE_KINDS = ["example", "real", "slot"];
const VALUE_KIND_SWATCH = {
    example: "bg-amber-500/40",
    real: "bg-accent-500/40",
    slot: "bg-surface-2 outline-dashed outline-1 outline-line",
};

/**
 * What a contract can print, and so all the editor offers: anything else was
 * published, then refused at every freeze (ContractDocumentRenderer).
 */
const CONTRACT_BLOCKS = ["header", "paragraph", "list", "quote", "table"];

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
    openDraftPath: { type: String, default: "" },
    inForceVersionId: { type: Number, default: null },
});

const { can } = usePrivileges();
/** Everything that writes needs this: the buttons used to show for readers. */
const canEdit = computed(() => can("studio.contract_templates.edit") && !props.template.isArchived);

const {
    untitledWithText,
    isDirty,
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

/**
 * Three states, named the same everywhere: the draft being written, the
 * version in force, and a version that was in force and has been replaced.
 * Every published version used to say « Publiée », the first as much as the
 * one contracts are built on today.
 */
const state = computed(() => {
    if (!isPublished.value) return "draft";

    return version.value.id === props.inForceVersionId ? "in_force" : "replaced";
});

const inForceHref = computed(() => (props.inForceVersionId ? versionPath(props.inForceVersionId) : null));
const inForceNumber = computed(() => props.versions.find((each) => each.id === props.inForceVersionId)?.number ?? null);

/**
 * « Modifier le texte » from a published version: back into the draft if one
 * is open, otherwise the next version, opened and entered.
 */
const opening = ref(false);

const editTextLabel = computed(() =>
    props.template.draftId
        ? t("suite.studio.contract_templates.continue_draft", { number: props.template.draftVersion })
        : t("suite.studio.contract_templates.edit_text"),
);

async function editText() {
    if (props.template.draftId) {
        window.location.assign(versionPath(props.template.draftId));

        return;
    }

    opening.value = true;

    try {
        const data = await request(props.openDraftPath, {}, { noGuard: true });

        if (!data?.success) {
            if (data?.errors) toast.error(Object.values(data.errors)[0]);

            return;
        }

        if (data.draftId) window.location.assign(versionPath(data.draftId));
    } finally {
        opening.value = false;
    }
}

/* Unsaved text is not lost to a click on « Retour » or a closed tab. */
function warnBeforeLeaving(event) {
    if (!isDirty.value) return;

    event.preventDefault();
    event.returnValue = "";
}

onMounted(() => window.addEventListener("beforeunload", warnBeforeLeaving));
onBeforeUnmount(() => window.removeEventListener("beforeunload", warnBeforeLeaving));

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
const preview = ref({ open: false, loading: false, html: "", error: "", locale: "", unknownTokens: [] });

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
            title: t("suite.studio.contract_templates.preview"),
            loading: preview.value.loading && !preview.value.open,
            onSelect: openPreview,
        },
    ];

    // « Publier » is a button of its own now, beside « Enregistrer »: hidden
    // in this menu, it was the step people could not find.
    if (!isPublished.value && canEdit.value) {
        actions.push({
            key: "discard",
            color: "rose",
            icon: Trash2,
            title: t("suite.studio.contract_templates.discard"),
            onSelect: () => (showDiscard.value = true),
        });
    }

    return actions;
});

/** Cleaned on the way into the DOM, exactly like the sealed document is. */
const previewHtml = computed(() => safeContractHtml(preview.value.html));

/** Written as the author typed them, braces included, so they can be found. */
const previewUnknownTokens = computed(() =>
    preview.value.unknownTokens.map((token) => `{{${token}}}`).join(", "),
);

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

    // The body second, the options third: `{ method: "GET" }` in second
    // position went out as the body of a POST, which the route refuses, and
    // the preview failed every time.
    const data = await request(`${props.previewPath}?locale=${encodeURIComponent(locale)}`, null, {
        method: HttpMethod.Get,
    });

    if (!data?.success) {
        preview.value = {
            ...preview.value,
            loading: false,
            html: "",
            error:
                data?.errors?.preview ??
                t("suite.studio.contract_templates.preview_failed"),
        };

        return;
    }

    preview.value = {
        ...preview.value,
        loading: false,
        html: data.html ?? "",
        locale: data.locale ?? locale,
        unknownTokens: data.unknownTokens ?? [],
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
    <div class="aurora-stack">
        <!-- The page's bar first, like every editor: back on the left, the
             commands on the right. The title comes under it, on its own line,
             where a long name pushes nothing (02/10/2026). -->
        <AppPageBar :back-href="indexPath" :back-label="t('shared.common.back')">
            <AppPageActions
                :actions="templateActions"
                :label="template.name"
                :busy="preview.loading && !preview.open"
                icon-only-on-phone
            />
            <!-- The draft's two gestures, both in sight: save, then publish.
                 Publishing sat in the « … » menu, and people looked for it. -->
            <template v-if="!isPublished && canEdit">
                <AppButton
                    variant="secondary"
                    :loading="saving"
                    :label="t('shared.common.save')"
                    icon-only-on-phone
                    v-on:click="save"
                >
                    <Save class="w-4 h-4" :stroke-width="2" />
                </AppButton>
                <AppButton
                    :disabled="!canPublish"
                    :loading="publishing"
                    :label="t('suite.studio.contract_templates.publish')"
                    icon-only-on-phone
                    v-on:click="showPublish = true"
                >
                    <Check class="w-4 h-4" :stroke-width="2" />
                </AppButton>
            </template>
            <!-- From the version in force: the next draft, one click. -->
            <AppButton
                v-else-if="'in_force' === state && canEdit"
                :loading="opening"
                :label="editTextLabel"
                icon-only-on-phone
                v-on:click="editText"
            >
                <FilePlus2 class="w-4 h-4" :stroke-width="2" />
            </AppButton>
        </AppPageBar>

        <!-- The screen's how-to, next to what it explains; collapsed or
             expanded, the choice applies to every guide box. -->
        <AppGuide :title="t('suite.studio.contract_templates.editor_guide.title')" storage-key="contract-template-editor">
            <ol class="m-0 flex list-decimal flex-col gap-1.5 pl-5">
                <li v-for="step in 5" :key="step">{{ t(`suite.studio.contract_templates.editor_guide.step_${step}`) }}</li>
            </ol>
        </AppGuide>

        <!-- A published version is readable but not writable, and the page says
             so before the reader tries. Hiding the fields instead would leave
             them wondering where the text went. -->
        <!-- What this version is, and what to do from here. -->
        <AppMessage v-if="template.isArchived" variant="warning">
            <span class="flex items-center gap-2">
                <Archive class="w-4 h-4 shrink-0" :stroke-width="2" />
                {{ t("suite.studio.contract_templates.archived_notice") }}
            </span>
        </AppMessage>
        <AppMessage v-else-if="'in_force' === state" variant="info">
            <span class="flex items-center gap-2">
                <Lock class="w-4 h-4 shrink-0" :stroke-width="2" />
                {{ t("suite.studio.contract_templates.in_force_notice", { number: version.number }) }}
            </span>
        </AppMessage>
        <AppMessage v-else-if="'replaced' === state" variant="warning">
            <span class="flex flex-wrap items-center gap-2">
                <Lock class="w-4 h-4 shrink-0" :stroke-width="2" />
                {{ t("suite.studio.contract_templates.replaced_notice", { number: version.number, latest: inForceNumber ?? "-" }) }}
                <a v-if="inForceHref" :href="inForceHref" class="underline">{{ t("suite.studio.contract_templates.open_in_force") }}</a>
            </span>
        </AppMessage>

        <AppMessage v-if="untitledWithText.length && !isPublished" variant="warning">
            {{ t("suite.studio.contract_templates.untitled_text", { locales: untitledWithText.map((code) => code.toUpperCase()).join(", ") }) }}
        </AppMessage>

        <AppMessage v-if="errors.version" variant="danger">
            {{ errors.version }}
        </AppMessage>
        <AppMessage v-if="errors.translations" variant="danger">
            {{ errors.translations }}
        </AppMessage>

        <div class="flex flex-wrap items-baseline gap-2">
            <h1 class="text-xl font-semibold tracking-tight text-primary sm:text-2xl">
                {{ template.name }}
            </h1>
            <span class="text-sm text-muted">
                {{
                    t("suite.studio.contract_templates.version_label", {
                        number: version.number,
                    })
                }}
            </span>
            <AppBadge :color="{ draft: 'amber', in_force: 'emerald', replaced: 'slate' }[state]">
                {{ t(`suite.studio.contract_templates.state_${state}`) }}
            </AppBadge>
            <AppBadge v-if="template.isArchived" color="slate">
                {{ t("suite.studio.contract_templates.state_archived") }}
            </AppBadge>
        </div>

        <div class="grid gap-4 lg:grid-cols-[minmax(0,1fr)_18rem]">
            <div class="min-w-0 space-y-3">
                <!-- Language tabs. Each carries a dot when it has wording, so
                     "which languages does this version actually have" is
                     answered without opening all three. -->
                <div
                    class="flex flex-wrap gap-1 border-b border-line"
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
                            :title="t('suite.studio.contract_templates.locale_written')"
                        />
                    </button>
                </div>

                <template v-for="locale in locales" :key="locale.code">
                    <div v-show="locale.code === activeLocale" class="space-y-3">
                        <AppInput
                            v-model="wording[locale.code].title"
                            :label="t('suite.studio.contract_templates.document_title')"
                            :placeholder="
                                t('suite.studio.contract_templates.document_title_placeholder')
                            "
                            :hint="t('suite.studio.contract_templates.document_title_hint')"
                            :readonly="isPublished"
                        />
                        <!-- `overflow-wrap: anywhere`: a token like
                             {{contract.custom.plateformes_retenues}} has no
                             space to wrap at, and spilled out of the card on
                             a phone (02/10/2026). -->
                        <div
                            class="aurora-card p-3 [overflow-wrap:anywhere]"
                            :class="{ 'opacity-70 pointer-events-none': isPublished }"
                        >
                            <AppBlockEditor
                                v-model="wording[locale.code].blocks"
                                :block-tools="CONTRACT_BLOCKS"
                                :read-only="isPublished || !canEdit"
                                :placeholder="
                                    t('suite.studio.contract_templates.content_placeholder')
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
                <div class="aurora-card p-3 space-y-2">
                    <p class="text-xs font-medium uppercase tracking-wider text-muted">
                        {{ t("suite.studio.contract_templates.governing_locale") }}
                    </p>
                    <AppSelect
                        v-if="!isPublished"
                        :model-value="governingLocale ?? ''"
                        :options="governingSelectOptions"
                        :placeholder="t('suite.studio.contract_templates.governing_locale_none')"
                        :hint="t('suite.studio.contract_templates.governing_locale_hint')"
                        :error="errors.governingLocale"
                        v-on:update:model-value="governingLocale = $event === '' ? null : $event"
                    />
                    <p v-else class="text-sm text-secondary">
                        {{
                            governingLabel
                                ?? t("suite.studio.contract_templates.governing_locale_none")
                        }}
                    </p>
                    <!-- Said here, next to the field, rather than only by a
                         disabled publish button on the other side of the page. -->
                    <p v-if="needsGoverningLocale" class="text-xs text-amber-500">
                        {{ t("suite.studio.contract_templates.governing_locale_needed") }}
                    </p>
                </div>

                <ContractVariablePanel :groups="variableGroups" />

                <div
                    v-if="otherVersions.length"
                    class="aurora-card p-3 space-y-2"
                >
                    <p class="text-xs font-medium uppercase tracking-wider text-muted">
                        {{ t("suite.studio.contract_templates.other_versions") }}
                    </p>
                    <ul class="space-y-1">
                        <li v-for="each in otherVersions" :key="each.id">
                            <a
                                :href="versionPath(each.id)"
                                class="text-sm text-secondary hover:text-primary flex items-center gap-2"
                            >
                                <ScrollText class="w-3.5 h-3.5 shrink-0" :stroke-width="2" />
                                {{
                                    t("suite.studio.contract_templates.version_label", {
                                        number: each.number,
                                    })
                                }}
                                <span class="text-xs text-muted">
                                    {{
                                        !each.isPublished
                                            ? t("suite.studio.contract_templates.state_draft")
                                            : each.id === inForceVersionId
                                                ? t("suite.studio.contract_templates.state_in_force")
                                                : t("suite.studio.contract_templates.state_replaced")
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
            :title="t('suite.studio.contract_templates.preview')"
            :icon="Eye"
            v-on:close="preview.open = false"
        >
            <div class="space-y-3">
                <!-- Said before the document rather than after it: a reader who
                     takes these example values for real ones would be reading a
                     contract that does not exist. -->
                <AppMessage variant="info">
                    {{ t("suite.studio.contract_templates.preview_notice") }}
                </AppMessage>

                <!-- The legend of the three colours, with the same classes as
                     the document: it cannot say anything different from it. -->
                <ul class="m-0 flex list-none flex-wrap gap-x-4 gap-y-1 p-0 text-xs text-secondary">
                    <li v-for="kind in VALUE_KINDS" :key="kind" class="flex items-center gap-1.5">
                        <span class="inline-block h-3 w-3 rounded-sm" :class="VALUE_KIND_SWATCH[kind]" aria-hidden="true" />
                        {{ t(`suite.studio.contract_templates.preview_legend.${kind}`) }}
                    </li>
                </ul>

                <div
                    v-if="previewLocales.length > 1"
                    class="flex flex-wrap gap-1 border-b border-line"
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

                <AppMessage v-if="preview.unknownTokens.length && !preview.error" variant="warning">
                    {{
                        t("suite.studio.contract_templates.preview_unknown_tokens", {
                            tokens: previewUnknownTokens,
                        })
                    }}
                </AppMessage>
                <AppMessage v-if="preview.error" variant="danger">
                    {{ preview.error }}
                </AppMessage>

                <p v-else-if="preview.loading" class="text-sm text-muted">
                    {{ t("shared.common.loading") }}
                </p>

                <article
                    v-else
                    class="aurora-card p-4 prose-contract max-h-[65vh] overflow-y-auto [overflow-wrap:anywhere] [&_mark.contract-variable]:rounded [&_mark.contract-variable]:px-0.5 [&_mark.contract-variable]:text-inherit [&_mark.contract-variable--example]:bg-amber-500/25 [&_mark.contract-variable--real]:bg-accent-500/25 [&_mark.contract-variable--slot]:bg-surface-2 [&_mark.contract-variable--slot]:outline-dashed [&_mark.contract-variable--slot]:outline-1 [&_mark.contract-variable--slot]:outline-line"
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
            :title="t('suite.studio.contract_templates.publish')"
            :icon="Check"
            v-on:close="showPublish = false"
        >
            <p class="text-sm text-primary">
                {{
                    t("suite.studio.contract_templates.publish_confirm", {
                        number: version.number,
                    })
                }}
            </p>
            <!-- The consequence, stated before the click rather than discovered
                 after it: this is the one action on the page that cannot be
                 undone. -->
            <p class="text-sm text-secondary">
                {{ t("suite.studio.contract_templates.publish_warning") }}
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
                        {{ t("suite.studio.contract_templates.publish") }}
                    </AppButton>
                </AppModalFooter>
            </template>
        </AppModal>

        <AppModal
            :show="showDiscard"
            max-width="sm"
            :closeable="false"
            :title="t('suite.studio.contract_templates.discard')"
            :icon="Trash2"
            v-on:close="showDiscard = false"
        >
            <p class="text-sm text-primary">
                {{
                    t("suite.studio.contract_templates.discard_confirm", {
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
                        {{ t("suite.studio.contract_templates.discard") }}
                    </AppButton>
                </AppModalFooter>
            </template>
        </AppModal>
    </div>
</template>
