<script setup>
import { computed, onMounted, ref } from "vue";
import { useI18n } from "vue-i18n";
import { toast } from "vue-sonner";
import { Copy, ExternalLink, Save } from "lucide-vue-next";
import AppButton from "@/shared/components/action/AppButton.vue";
import AppCheckbox from "@/shared/components/form/toggle/AppCheckbox.vue";
import AppInput from "@/shared/components/form/input/AppInput.vue";
import IntegrationLayout from "@configuration/suite/settings/components/IntegrationLayout.vue";
import { useRequest } from "@/shared/composables/http/suite/useRequest.js";
import { useClipboard } from "@/shared/composables/useClipboard.js";
import { HttpMethod } from "@/shared/utils/http/httpMethod.js";

/**
 * The Google Drive tab of the settings screen.
 *
 * **The account's address is the useful half of this screen.** The key is
 * pasted once and put away; the address, on the other hand, is copied for
 * every new client, into their folder's sharing. It is therefore shown large
 * with a button to copy it, rather than buried in a confirmation message.
 *
 * A long text field for the key, and not a file field: a JSON is pasted from
 * the editor it was just opened in, and a file field would force finding the
 * download again.
 */
defineProps({
    groups: { type: Object, default: () => ({}) },
    updatePath: { type: String, default: "" },
});

const SETTINGS_PATH = "/suite/studio/drive/settings";

const { t } = useI18n();
const { request } = useRequest();
const { copy } = useClipboard();

/**
 * The four screens of the Google console, in the order they are crossed.
 *
 * **One link per step, and not a single one at the bottom.** They follow
 * each other poorly: the one you are looking for is never the one in front
 * of you, and the project selector at the top of the console silently
 * decides what the page shows. A list of steps without their address leaves
 * people searching.
 */
const STEPS = [
    { key: "how_step_project", href: "https://console.cloud.google.com/projectcreate" },
    { key: "how_step_api", href: "https://console.cloud.google.com/apis/library/drive.googleapis.com" },
    { key: "how_step_account", href: "https://console.cloud.google.com/iam-admin/serviceaccounts" },
    { key: "how_step_key", href: null },
];

const loading = ref(true);
const saving = ref(false);
const enabled = ref(false);
const hasAccount = ref(false);
const email = ref(null);

// Never prefilled: from here on the key is no longer read, it is only
// written. Left empty, the save keeps the stored one.
const serviceAccount = ref("");

// The agency's folder, the same for every space. This one is prefilled: it
// is not a secret, and an empty field would not say whether there is one.
const agencyFolder = ref("");

const canEnable = computed(() => hasAccount.value || "" !== serviceAccount.value.trim());

function apply(state) {
    if (!state) return;
    enabled.value = true === state.enabled;
    hasAccount.value = true === state.hasAccount;
    email.value = state.email ?? null;
    agencyFolder.value = state.agencyFolderId ?? "";
    serviceAccount.value = "";
}

onMounted(async () => {
    try {
        apply(await request(SETTINGS_PATH, null, { method: HttpMethod.Get, noGuard: true }));
    } finally {
        loading.value = false;
    }
});

async function save() {
    saving.value = true;
    try {
        // Always sent, empty included: clearing the field removes the folder.
        const payload = { enabled: enabled.value && canEnable.value, agencyFolderId: agencyFolder.value.trim() };
        if ("" !== serviceAccount.value.trim()) payload.serviceAccount = serviceAccount.value.trim();

        const state = await request(SETTINGS_PATH, payload, { noGuard: true });

        if (state) {
            apply(state);
            toast.success(t("suite.settings.saved"));
        }
    } finally {
        saving.value = false;
    }
}

/** On, ready but off (a key without activation), or still to configure. */
const status = computed(() => {
    if (enabled.value && hasAccount.value) return "active";

    return hasAccount.value ? "off" : "todo";
});

defineExpose({ save, apply, canEnable });
</script>

<template>
    <IntegrationLayout :summary="t('suite.studio.drive.settings.what_body')" :status="status" :loading="loading">
        <!-- What the key allows, said before asking for it: it looks like it
             opens a whole Drive, it only opens what is shared with it. -->
        <p class="m-0 rounded-lg border border-line bg-surface-2 p-3 text-xs text-secondary">
            <span class="font-medium text-primary">{{ t("suite.studio.drive.settings.scope_title") }}.</span>
            {{ t("suite.studio.drive.settings.scope_body") }}
        </p>

        <label class="flex flex-col gap-1">
            <span class="text-xs text-secondary">{{ t("suite.studio.drive.settings.key_label") }}</span>
            <textarea
                v-model="serviceAccount"
                rows="5"
                spellcheck="false"
                :placeholder="hasAccount ? t('suite.studio.drive.settings.key_stored') : '{ &quot;type&quot;: &quot;service_account&quot;, … }'"
                class="aurora-card w-full px-3 py-2 font-mono text-xs text-primary"
            />
            <span class="text-xs text-muted">{{ t("suite.studio.drive.settings.key_hint") }}</span>
        </label>

        <!-- Optional, and says what for: without it, a space's Drive tab
             stays the client's alone, as before. -->
        <AppInput
            v-model="agencyFolder"
            :label="t('suite.studio.drive.settings.agency_label')"
            :hint="t('suite.studio.drive.settings.agency_hint')"
            placeholder="https://drive.google.com/drive/folders/…"
        />

        <AppCheckbox
            v-model="enabled"
            :label="t('suite.studio.drive.settings.enabled_label')"
            :hint="canEnable ? t('suite.studio.drive.settings.enabled_hint') : t('suite.studio.drive.settings.enabled_blocked')"
            :disabled="!canEnable"
        />

        <template #actions>
            <AppButton
                variant="primary"
                size="md"
                class="w-full sm:w-auto"
                :loading="saving"
                v-on:click="save"
            >
                <Save class="w-3.5 h-3.5" :stroke-width="2" /> {{ t("shared.common.save") }}
            </AppButton>
        </template>

        <!-- The address to copy at each client's. It is not a secret, and it
             is the only part of the key that is shown. -->
        <template v-if="email" #after>
            <article class="aurora-card flex flex-col gap-3 p-3 sm:p-4">
                <div class="flex flex-col gap-1">
                    <h3 class="m-0 text-sm font-medium text-primary">{{ t("suite.studio.drive.settings.share_title") }}</h3>
                    <p class="m-0 text-xs text-muted">{{ t("suite.studio.drive.settings.share_body") }}</p>
                </div>
                <div class="flex flex-col gap-2 sm:flex-row sm:items-center">
                    <code class="min-w-0 flex-1 truncate rounded-md border border-line bg-surface px-3 py-2 font-mono text-xs text-primary">{{ email }}</code>
                    <AppButton class="w-full sm:w-auto" variant="secondary" size="sm" v-on:click="copy(email)">
                        <Copy class="h-3.5 w-3.5" :stroke-width="2" />
                        {{ t("shared.common.copy") }}
                    </AppButton>
                </div>
            </article>
        </template>

        <!-- Each step carries its own link, and not a single one at the
             bottom. Four screens of the Google console follow each other, and
             the one you are looking for is never the one in front of you: a
             single link forced finding the other three by hand. -->
        <template #guide>
            <ol class="m-0 flex list-decimal flex-col gap-2 pl-5">
                <li v-for="step in STEPS" :key="step.key">
                    {{ t(`suite.studio.drive.settings.${step.key}`) }}
                    <a
                        v-if="step.href"
                        :href="step.href"
                        target="_blank"
                        rel="noopener"
                        class="mt-0.5 inline-flex items-center gap-1 text-accent hover:underline"
                    >
                        {{ t(`suite.studio.drive.settings.${step.key}_link`) }}
                        <ExternalLink class="h-3.5 w-3.5 shrink-0" :stroke-width="2" />
                    </a>
                </li>
            </ol>
            <!-- The step people forget: the key connects nothing on its own, it
                 only opens the possibility. The folder is set space by space,
                 and that is where to look when nothing shows up. -->
            <div class="flex flex-col gap-1 border-t border-line/60 pt-3">
                <p class="m-0 font-medium text-primary">{{ t("suite.studio.drive.settings.then_title") }}</p>
                <p class="m-0">{{ t("suite.studio.drive.settings.then_body") }}</p>
            </div>
            <div class="flex flex-col gap-1">
                <p class="m-0 font-medium text-primary">{{ t("suite.studio.drive.settings.trouble_title") }}</p>
                <p class="m-0">{{ t("suite.studio.drive.settings.trouble_body") }}</p>
            </div>
        </template>
    </IntegrationLayout>
</template>
