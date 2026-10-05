<script setup>
import { computed, inject } from "vue";
import { useI18n } from "vue-i18n";
import { Eye } from "lucide-vue-next";
import AppTab from "@/shared/components/nav/AppTab.vue";
import FormRender from "../../../frontend/FormRender.vue";

/**
 * Le formulaire tel que le visiteur le verra, pendant qu'on le construit.
 *
 * **Le vrai composant du site, pas une imitation.** Une maquette dessinée à
 * part finirait par mentir sur un détail - un ordre, une condition, une étape
 * - et c'est précisément ce qu'on vient vérifier ici. Il est seulement monté
 * en mode aperçu, qui n'envoie rien.
 *
 * La question en cours d'édition y figure déjà telle qu'on la tape : on voit
 * le libellé changer, les choix apparaître, la condition jouer, avant
 * d'enregistrer.
 */
const props = defineProps({
    /** Le formulaire au format du site : une langue, des libellés à plat. */
    form: { type: Object, required: true },
});

const { t } = useI18n();
const { locales, editLocale } = inject("formEditor");

/**
 * Remonté à chaque changement de question. Le composant du site prépare ses
 * réponses à l'ouverture, une case par question : une question ajoutée après
 * coup n'aurait pas la sienne, et des cases à cocher sans liste où ranger
 * leurs choix ne se cochent pas.
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
