<script setup>
/**
 * Ajouter ou reprendre une ressource.
 *
 * **Le genre se choisit d'abord, et il commande le reste.** Un lien veut une
 * adresse, un texte veut un corps, un contact veut une adresse et un numéro :
 * un formulaire qui montrerait les cinq champs à chaque fois demanderait de
 * deviner lesquels comptent. Le choix est en haut, en trois pavés plutôt qu'en
 * liste déroulante, parce qu'ils sont trois et qu'on les compare.
 *
 * **La visibilité est dans la modale, pas après coup.** C'est une décision qui
 * appartient au moment où l'on range : refermer la modale puis chercher un
 * interrupteur dans la liste est ce qui fait qu'on oublie. Fermée par défaut.
 *
 * Le genre ne peut plus changer à la reprise : ce qui a été enregistré comme
 * un contact et deviendrait un texte laisserait derrière une adresse et un
 * numéro que plus rien n'affiche.
 */
import { computed, ref, watch } from "vue";
import { useI18n } from "vue-i18n";
import { FileText, Link2, Save, User } from "lucide-vue-next";
import AppButton from "@/shared/components/action/AppButton.vue";
import AppInput from "@/shared/components/form/input/AppInput.vue";
import AppModal from "@/shared/components/overlay/AppModal.vue";
import AppModalFooter from "@/shared/components/overlay/AppModalFooter.vue";
import AppTextarea from "@/shared/components/form/input/AppTextarea.vue";
import AppToggle from "@/shared/components/form/toggle/AppToggle.vue";

const props = defineProps({
    show: { type: Boolean, default: false },
    /** La ressource reprise, ou `null` pour en poser une neuve. */
    resource: { type: Object, default: null },
    saving: { type: Boolean, default: false },
    errors: { type: Object, default: () => ({}) },
});

const emit = defineEmits(["close", "submit"]);

const { t } = useI18n();

const KINDS = [
    { key: "link", icon: Link2 },
    { key: "text", icon: FileText },
    { key: "contact", icon: User },
];

const form = ref(blank());

function blank() {
    return { kind: "link", label: "", url: "", body: "", email: "", phone: "", visibleToClient: false };
}

watch(
    () => [props.show, props.resource],
    () => {
        if (!props.show) return;

        form.value = props.resource
            ? {
                kind: props.resource.kind,
                label: props.resource.label ?? "",
                url: props.resource.url ?? "",
                body: props.resource.body ?? "",
                email: props.resource.email ?? "",
                phone: props.resource.phone ?? "",
                visibleToClient: true === props.resource.visibleToClient,
            }
            : blank();
    },
    { immediate: true },
);

const editing = computed(() => null !== props.resource);

const labelPlaceholder = computed(() => t(`backend.studio.space_resources.label_placeholder_${form.value.kind}`));

function error(field) {
    const message = props.errors?.[field];

    return message ? t(message) : "";
}
</script>

<template>
    <AppModal
        :show="show"
        :title="t(editing ? 'backend.studio.space_resources.edit' : 'backend.studio.space_resources.add')"
        max-width="lg"
        mobile-fullscreen
        v-on:close="emit('close')"
    >
        <form class="space-y-5" v-on:submit.prevent="emit('submit', { ...form })">
            <fieldset v-if="!editing" class="space-y-2">
                <legend class="text-sm font-medium text-primary">{{ t("backend.studio.space_resources.kind") }}</legend>

                <!-- Une colonne sur téléphone : trois pavés sur 375 px donnent
                     des libellés coupés, et le choix est ce qui commande tout
                     le reste du formulaire. -->
                <div class="grid grid-cols-1 gap-2 sm:grid-cols-3">
                    <button
                        v-for="entry in KINDS"
                        :key="entry.key"
                        type="button"
                        class="flex items-center gap-2 rounded-lg border p-3 text-left text-sm transition-colors"
                        :class="
                            form.kind === entry.key
                                ? 'border-accent bg-accent/5 text-primary'
                                : 'border-line/60 text-muted hover:border-line hover:text-primary'
                        "
                        :aria-pressed="form.kind === entry.key"
                        v-on:click="form.kind = entry.key"
                    >
                        <component :is="entry.icon" class="h-4 w-4 shrink-0" :stroke-width="2" />
                        <span>{{ t(`shared.space_resources.kinds.${entry.key}`) }}</span>
                    </button>
                </div>
            </fieldset>

            <AppInput
                v-model="form.label"
                :label="t('backend.studio.space_resources.label')"
                :placeholder="labelPlaceholder"
                :error="error('label')"
                required
            />

            <AppInput
                v-if="'link' === form.kind"
                v-model="form.url"
                :label="t('backend.studio.space_resources.url')"
                placeholder="https://"
                :error="error('url')"
                required
            />

            <template v-if="'contact' === form.kind">
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <AppInput
                        v-model="form.email"
                        type="email"
                        :label="t('backend.studio.space_resources.email')"
                        :placeholder="t('backend.studio.space_resources.email_placeholder')"
                        :error="error('email')"
                    />
                    <AppInput
                        v-model="form.phone"
                        :label="t('backend.studio.space_resources.phone')"
                        :placeholder="t('backend.studio.space_resources.phone_placeholder')"
                        :error="error('phone')"
                    />
                </div>
            </template>

            <AppTextarea
                v-model="form.body"
                :label="t(`backend.studio.space_resources.body_${form.kind}`)"
                :placeholder="t(`backend.studio.space_resources.body_placeholder_${form.kind}`)"
                :hint="t(`backend.studio.space_resources.body_hint_${form.kind}`)"
                :error="error('body')"
                :rows="'text' === form.kind ? 8 : 3"
                :required="'text' === form.kind"
            />

            <AppToggle
                v-model="form.visibleToClient"
                :label="t('backend.studio.space_resources.visible_to_client')"
                :hint="t(form.visibleToClient ? 'backend.studio.space_resources.visible_hint_on' : 'backend.studio.space_resources.visible_hint_off')"
            />
        </form>

        <template #footer>
            <!-- `AppModalFooter` empile déjà pleine largeur sur téléphone. -->
            <AppModalFooter>
                <AppButton variant="ghost" size="md" v-on:click="emit('close')">
                    {{ t("shared.common.cancel") }}
                </AppButton>
                <AppButton variant="primary" size="md" :loading="saving" v-on:click="emit('submit', { ...form })">
                    <Save class="h-3.5 w-3.5" :stroke-width="2" />
                    {{ t(editing ? "shared.common.save" : "backend.studio.space_resources.add_confirm") }}
                </AppButton>
            </AppModalFooter>
        </template>
    </AppModal>
</template>
