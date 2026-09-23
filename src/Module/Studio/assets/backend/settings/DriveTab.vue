<script setup>
import { computed, onMounted, ref } from "vue";
import { useI18n } from "vue-i18n";
import { toast } from "vue-sonner";
import { Copy, ExternalLink, Save } from "lucide-vue-next";
import AppButton from "@/shared/components/action/AppButton.vue";
import AppCheckbox from "@/shared/components/form/toggle/AppCheckbox.vue";
import AppLoader from "@/shared/components/feedback/AppLoader.vue";
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

const canEnable = computed(() => hasAccount.value || "" !== serviceAccount.value.trim());

function apply(state) {
    if (!state) return;
    enabled.value = true === state.enabled;
    hasAccount.value = true === state.hasAccount;
    email.value = state.email ?? null;
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
        const payload = { enabled: enabled.value && canEnable.value };
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

defineExpose({ save, apply, canEnable });
</script>

<template>
    <div class="relative space-y-6">
        <AppLoader :active="loading" />

        <section class="space-y-2">
            <h3 class="text-sm font-medium text-primary">{{ t("backend.studio.drive.settings.what_title") }}</h3>
            <p class="text-sm text-secondary">{{ t("backend.studio.drive.settings.what_body") }}</p>
        </section>

        <!-- Chaque étape porte son propre lien, et non un seul en bas.
             Quatre écrans de la console Google se suivent, et celui qu'on
             cherche n'est jamais celui qu'on a sous les yeux : un lien unique
             obligeait à retrouver les trois autres à la main. -->
        <section class="space-y-2">
            <h3 class="text-sm font-medium text-primary">{{ t("backend.studio.drive.settings.how_title") }}</h3>
            <ol class="list-decimal space-y-2 pl-5 text-sm text-secondary">
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
        </section>

        <!-- Ce que la clé autorise, dit avant de la demander : elle a l'air
             d'ouvrir un Drive entier, elle n'ouvre que ce qu'on lui partage. -->
        <section class="space-y-2">
            <h3 class="text-sm font-medium text-primary">{{ t("backend.studio.drive.settings.scope_title") }}</h3>
            <p class="text-sm text-secondary">{{ t("backend.studio.drive.settings.scope_body") }}</p>
        </section>

        <!-- L'adresse à recopier chez chaque client. Ce n'est pas un secret,
             et c'est la seule partie de la clé qu'on montre. -->
        <section v-if="email" class="space-y-2 rounded-lg border border-line bg-surface-2 p-4">
            <h3 class="text-sm font-medium text-primary">{{ t("backend.studio.drive.settings.share_title") }}</h3>
            <p class="text-sm text-secondary">{{ t("backend.studio.drive.settings.share_body") }}</p>
            <div class="flex flex-col gap-2 sm:flex-row sm:items-center">
                <code class="min-w-0 flex-1 truncate rounded-md border border-line bg-surface px-3 py-2 font-mono text-xs text-primary">
                    {{ email }}
                </code>
                <AppButton class="w-full sm:w-auto" variant="ghost" size="sm" v-on:click="copy(email)">
                    <Copy class="h-3.5 w-3.5" :stroke-width="2" />
                    {{ t("shared.common.copy") }}
                </AppButton>
            </div>
        </section>

        <!-- L'étape qu'on oublie : la clé ne branche rien toute seule, elle
             ouvre seulement la possibilité. Le dossier se désigne espace par
             espace, et c'est là qu'on cherche quand rien ne s'affiche. -->
        <section class="space-y-2">
            <h3 class="text-sm font-medium text-primary">{{ t("backend.studio.drive.settings.then_title") }}</h3>
            <p class="text-sm text-secondary">{{ t("backend.studio.drive.settings.then_body") }}</p>
        </section>

        <section class="space-y-2">
            <h3 class="text-sm font-medium text-primary">{{ t("backend.studio.drive.settings.trouble_title") }}</h3>
            <p class="text-sm text-secondary">{{ t("backend.studio.drive.settings.trouble_body") }}</p>
        </section>

        <section class="space-y-3">
            <label class="block space-y-1">
                <span class="text-xs text-secondary">{{ t("backend.studio.drive.settings.key_label") }}</span>
                <textarea
                    v-model="serviceAccount"
                    rows="5"
                    spellcheck="false"
                    :placeholder="hasAccount ? t('backend.studio.drive.settings.key_stored') : '{ &quot;type&quot;: &quot;service_account&quot;, … }'"
                    class="aurora-card w-full px-3 py-2 font-mono text-xs text-primary"
                />
                <span class="block text-xs text-muted">{{ t("backend.studio.drive.settings.key_hint") }}</span>
            </label>

            <AppCheckbox
                v-model="enabled"
                :label="t('backend.studio.drive.settings.enabled_label')"
                :hint="canEnable ? t('backend.studio.drive.settings.enabled_hint') : t('backend.studio.drive.settings.enabled_blocked')"
                :disabled="!canEnable"
            />
        </section>

        <div class="flex justify-end">
            <AppButton variant="primary" size="md" :loading="saving" v-on:click="save">
                <Save class="w-3.5 h-3.5" :stroke-width="2" /> {{ t("shared.common.save") }}
            </AppButton>
        </div>
    </div>
</template>
