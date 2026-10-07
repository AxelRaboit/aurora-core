<script setup>
import { computed, onMounted, ref } from "vue";
import { useI18n } from "vue-i18n";
import { toast } from "vue-sonner";
import { ExternalLink, Save } from "lucide-vue-next";
import AppButton from "@/shared/components/action/AppButton.vue";
import AppInput from "@/shared/components/form/input/AppInput.vue";
import AppCheckbox from "@/shared/components/form/toggle/AppCheckbox.vue";
import IntegrationLayout from "@configuration/suite/settings/components/IntegrationLayout.vue";
import { useRequest } from "@/shared/composables/http/suite/useRequest.js";
import { HttpMethod } from "@/shared/utils/http/httpMethod.js";

/**
 * The Craft tab of the settings screen.
 *
 * It draws itself rather than declaring fields: the token has no business in
 * a page's source, and the generic rendering would put it there like an
 * ordinary value.
 *
 * **The steps to follow are on the page.** A Craft connection is created in
 * Craft, in a tab nobody finds the first time, and the scope given to it
 * there decides what Aurora will see. So the three steps matter more than
 * the two fields.
 */
defineProps({
    groups: { type: Object, default: () => ({}) },
    updatePath: { type: String, default: "" },
});

const SETTINGS_PATH = "/suite/notes/craft/settings";

const { t } = useI18n();
const { request } = useRequest();

const loading = ref(true);
const saving = ref(false);
const enabled = ref(false);
const endpoint = ref("");
const hasToken = ref(false);

// Never prefilled from the server: from here on the token can no longer be
// read, only written. Left empty, saving keeps the stored one.
const token = ref("");

/**
 * The switch needs an address and a token, and the server refuses without
 * them. Disabling it here puts the reason before the click rather than after.
 */
const canEnable = computed(
    () => "" !== endpoint.value.trim() && (hasToken.value || "" !== token.value.trim()),
);

function apply(state) {
    if (!state) return;
    enabled.value = true === state.enabled;
    endpoint.value = state.endpoint ?? "";
    hasToken.value = true === state.hasToken;
    token.value = "";
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
        const payload = {
            enabled: enabled.value && canEnable.value,
            endpoint: endpoint.value.trim(),
        };
        // Sent only if someone typed, so that saving the tab without touching
        // the field keeps the token already in place.
        if ("" !== token.value.trim()) payload.token = token.value.trim();

        const state = await request(SETTINGS_PATH, payload, { noGuard: true });

        // Nothing visibly changes when the save works - the token comes back
        // as "un jeton est enregistré", not as itself - so without a word,
        // pressing looks like pressing nothing.
        if (state) {
            apply(state);
            toast.success(t("suite.settings.saved"));
        }
    } finally {
        saving.value = false;
    }
}

/** On, ready but off, or still to configure. */
const status = computed(() => {
    if (enabled.value) return "active";

    return "" !== endpoint.value.trim() && hasToken.value ? "off" : "todo";
});

defineExpose({ save, apply, canEnable });
</script>

<template>
    <IntegrationLayout :summary="t('notes.craft.settings.what_body')" :status="status" :loading="loading">
        <!-- What the key can do, said before asking for it: a Craft
             connection with write access would allow editing and deleting
             documents from here, which is never what one wants for an
             import. -->
        <p class="m-0 rounded-lg border border-line bg-surface-2 p-3 text-xs text-secondary">
            <span class="font-medium text-primary">{{ t("notes.craft.settings.scope_title") }}.</span>
            {{ t("notes.craft.settings.scope_body") }}
        </p>

        <AppInput
            v-model="endpoint"
            :label="t('notes.craft.settings.endpoint_label')"
            :hint="t('notes.craft.settings.endpoint_hint')"
            placeholder="https://connect.craft.do/..."
        />

        <AppInput
            v-model="token"
            type="password"
            :toggleable="true"
            :label="t('notes.craft.settings.token_label')"
            :hint="hasToken ? t('notes.craft.settings.token_stored') : t('notes.craft.settings.token_hint')"
            :placeholder="hasToken ? '••••••••••••••••' : ''"
        />

        <AppCheckbox
            v-model="enabled"
            :label="t('notes.craft.settings.enabled_label')"
            :hint="canEnable ? t('notes.craft.settings.enabled_hint') : t('notes.craft.settings.enabled_blocked')"
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

        <template #guide>
            <ol class="m-0 flex list-decimal flex-col gap-1.5 pl-5">
                <li>{{ t("notes.craft.settings.how_step_connection") }}</li>
                <li>{{ t("notes.craft.settings.how_step_select") }}</li>
                <li>{{ t("notes.craft.settings.how_step_mode") }}</li>
                <li>{{ t("notes.craft.settings.how_step_paste") }}</li>
            </ol>
            <a href="https://support.craft.do/en/integrate/api" target="_blank" rel="noopener" class="inline-flex items-center gap-1 text-sm text-accent hover:underline">
                {{ t("notes.craft.settings.how_link") }}
                <ExternalLink class="w-3.5 h-3.5" :stroke-width="2" />
            </a>
            <div class="flex flex-col gap-1 border-t border-line/60 pt-3">
                <p class="m-0 font-medium text-primary">{{ t("notes.craft.settings.then_title") }}</p>
                <p class="m-0">{{ t("notes.craft.settings.then_body") }}</p>
            </div>
            <div class="flex flex-col gap-1">
                <p class="m-0 font-medium text-primary">{{ t("notes.craft.settings.trouble_title") }}</p>
                <p class="m-0">{{ t("notes.craft.settings.trouble_body") }}</p>
            </div>
        </template>
    </IntegrationLayout>
</template>
