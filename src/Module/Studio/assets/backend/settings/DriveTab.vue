<script setup>
import { computed, onMounted, ref } from "vue";
import { useI18n } from "vue-i18n";
import { toast } from "vue-sonner";
import { Copy, ExternalLink, Save } from "lucide-vue-next";
import AppButton from "@/shared/components/action/AppButton.vue";
import AppCheckbox from "@/shared/components/form/toggle/AppCheckbox.vue";
import AppInput from "@/shared/components/form/input/AppInput.vue";
import IntegrationLayout from "@configuration/backend/settings/components/IntegrationLayout.vue";
import { useRequest } from "@/shared/composables/http/backend/useRequest.js";
import { useClipboard } from "@/shared/composables/useClipboard.js";
import { HttpMethod } from "@/shared/utils/http/httpMethod.js";

/**
 * L'onglet Google Drive de l'écran des réglages.
 *
 * **L'adresse du compte est la moitié utile de cet écran.** La clé se colle
 * une fois et se range ; l'adresse, elle, se recopie à chaque nouveau client,
 * dans le partage de son dossier. Elle est donc affichée en grand avec un
 * bouton pour la copier, plutôt qu'enterrée dans un message de confirmation.
 *
 * Un champ de texte long pour la clé, et non un champ de fichier : un JSON se
 * colle depuis l'éditeur où on vient de l'ouvrir, et un champ de fichier
 * obligerait à retrouver le téléchargement.
 */
defineProps({
    groups: { type: Object, default: () => ({}) },
    updatePath: { type: String, default: "" },
});

const SETTINGS_PATH = "/backend/studio/drive/settings";

const { t } = useI18n();
const { request } = useRequest();
const { copy } = useClipboard();

/**
 * Les quatre écrans de la console Google, dans l'ordre où on les traverse.
 *
 * **Un lien par étape, et non un seul en bas.** Ils se suivent mal : celui
 * qu'on cherche n'est jamais celui qu'on a sous les yeux, et le sélecteur de
 * projet en haut de la console décide silencieusement de ce que la page
 * affiche. Une liste d'étapes sans leur adresse laisse chercher.
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

// Jamais préremplie : à partir d'ici la clé ne se lit plus, elle ne s'écrit
// que. Laissée vide, l'enregistrement garde celle qui est stockée.
const serviceAccount = ref("");

// Le dossier de l'agence, le même pour tous les espaces. Préremplie, elle :
// ce n'est pas un secret, et un champ vide ne dirait pas s'il y en a un.
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
        // Toujours envoyé, vide compris : vider le champ retire le dossier.
        const payload = { enabled: enabled.value && canEnable.value, agencyFolderId: agencyFolder.value.trim() };
        if ("" !== serviceAccount.value.trim()) payload.serviceAccount = serviceAccount.value.trim();

        const state = await request(SETTINGS_PATH, payload, { noGuard: true });

        if (state) {
            apply(state);
            toast.success(t("backend.settings.saved"));
        }
    } finally {
        saving.value = false;
    }
}

/** Allumé, prêt mais éteint (une clé sans activation), ou encore à configurer. */
const status = computed(() => {
    if (enabled.value && hasAccount.value) return "active";

    return hasAccount.value ? "off" : "todo";
});

defineExpose({ save, apply, canEnable });
</script>

<template>
    <IntegrationLayout :summary="t('backend.studio.drive.settings.what_body')" :status="status" :loading="loading">
        <!-- Ce que la clé autorise, dit avant de la demander : elle a l'air
             d'ouvrir un Drive entier, elle n'ouvre que ce qu'on lui partage. -->
        <p class="m-0 rounded-lg border border-line bg-surface-2 p-3 text-xs text-secondary">
            <span class="font-medium text-primary">{{ t("backend.studio.drive.settings.scope_title") }}.</span>
            {{ t("backend.studio.drive.settings.scope_body") }}
        </p>

        <label class="flex flex-col gap-1">
            <span class="text-xs text-secondary">{{ t("backend.studio.drive.settings.key_label") }}</span>
            <textarea
                v-model="serviceAccount"
                rows="5"
                spellcheck="false"
                :placeholder="hasAccount ? t('backend.studio.drive.settings.key_stored') : '{ &quot;type&quot;: &quot;service_account&quot;, … }'"
                class="aurora-card w-full px-3 py-2 font-mono text-xs text-primary"
            />
            <span class="text-xs text-muted">{{ t("backend.studio.drive.settings.key_hint") }}</span>
        </label>

        <!-- Facultatif, et dit pour quoi faire : sans lui, l'onglet Drive
             d'un espace reste celui du seul client, comme avant. -->
        <AppInput
            v-model="agencyFolder"
            :label="t('backend.studio.drive.settings.agency_label')"
            :hint="t('backend.studio.drive.settings.agency_hint')"
            placeholder="https://drive.google.com/drive/folders/…"
        />

        <AppCheckbox
            v-model="enabled"
            :label="t('backend.studio.drive.settings.enabled_label')"
            :hint="canEnable ? t('backend.studio.drive.settings.enabled_hint') : t('backend.studio.drive.settings.enabled_blocked')"
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

        <!-- L'adresse à recopier chez chaque client. Ce n'est pas un secret,
             et c'est la seule partie de la clé qu'on montre. -->
        <template v-if="email" #after>
            <article class="aurora-card flex flex-col gap-3 p-3 sm:p-4">
                <div class="flex flex-col gap-1">
                    <h3 class="m-0 text-sm font-medium text-primary">{{ t("backend.studio.drive.settings.share_title") }}</h3>
                    <p class="m-0 text-xs text-muted">{{ t("backend.studio.drive.settings.share_body") }}</p>
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

        <!-- Chaque étape porte son propre lien, et non un seul en bas.
             Quatre écrans de la console Google se suivent, et celui qu'on
             cherche n'est jamais celui qu'on a sous les yeux : un lien unique
             obligeait à retrouver les trois autres à la main. -->
        <template #guide>
            <ol class="m-0 flex list-decimal flex-col gap-2 pl-5">
                <li v-for="step in STEPS" :key="step.key">
                    {{ t(`backend.studio.drive.settings.${step.key}`) }}
                    <a
                        v-if="step.href"
                        :href="step.href"
                        target="_blank"
                        rel="noopener"
                        class="mt-0.5 inline-flex items-center gap-1 text-accent hover:underline"
                    >
                        {{ t(`backend.studio.drive.settings.${step.key}_link`) }}
                        <ExternalLink class="h-3.5 w-3.5 shrink-0" :stroke-width="2" />
                    </a>
                </li>
            </ol>
            <!-- L'étape qu'on oublie : la clé ne branche rien toute seule, elle
                 ouvre seulement la possibilité. Le dossier se désigne espace par
                 espace, et c'est là qu'on cherche quand rien ne s'affiche. -->
            <div class="flex flex-col gap-1 border-t border-line/60 pt-3">
                <p class="m-0 font-medium text-primary">{{ t("backend.studio.drive.settings.then_title") }}</p>
                <p class="m-0">{{ t("backend.studio.drive.settings.then_body") }}</p>
            </div>
            <div class="flex flex-col gap-1">
                <p class="m-0 font-medium text-primary">{{ t("backend.studio.drive.settings.trouble_title") }}</p>
                <p class="m-0">{{ t("backend.studio.drive.settings.trouble_body") }}</p>
            </div>
        </template>
    </IntegrationLayout>
</template>
