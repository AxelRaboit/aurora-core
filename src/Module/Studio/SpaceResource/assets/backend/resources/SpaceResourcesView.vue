<script setup>
/**
 * Les ressources d'un espace : ce qui vit ailleurs et qu'on cherche à chaque
 * fois.
 *
 * **Deux listes sur un seul écran, et c'est ce qui fait la fonctionnalité.**
 * On range au même endroit la maquette qu'on montre au client et le tableau de
 * bord qu'on ne montre pas ; ce qui les sépare est une case, ligne par ligne.
 * Elles sont donc dessinées séparément plutôt que mélangées avec une pastille
 * à repérer : « ce que le client voit » se lit d'un coup d'œil ou ne se lit
 * pas du tout.
 *
 * **La visibilité est à un clic et son effet est annoncé.** Publier quelque
 * chose par inadvertance et publier quelque chose sont le même geste à
 * l'écran ; ce qui les distingue est de savoir ce qui vient de se passer.
 */
import { computed, ref } from "vue";
import { useI18n } from "vue-i18n";
import { ArrowDown, ArrowUp, Eye, EyeOff, Pencil, Plus, Trash2 } from "lucide-vue-next";
import AppButton from "@/shared/components/action/AppButton.vue";
import AppIconButton from "@/shared/components/action/AppIconButton.vue";
import AppModal from "@/shared/components/overlay/AppModal.vue";
import AppModalFooter from "@/shared/components/overlay/AppModalFooter.vue";
import AppNoData from "@/shared/components/feedback/AppNoData.vue";
import { usePrivileges } from "@/shared/composables/usePrivileges.js";
import SpaceResourceItem from "../../shared/SpaceResourceItem.vue";
import SpaceResourceFormModal from "./SpaceResourceFormModal.vue";
import { useSpaceResources } from "./composables/useSpaceResources.js";

const props = defineProps({
    resources: { type: Array, default: () => [] },
    resourceCreatePath: { type: String, required: true },
    resourceUpdatePath: { type: String, required: true },
    resourceVisibilityPath: { type: String, required: true },
    resourceDeletePath: { type: String, required: true },
    resourceReorderPath: { type: String, required: true },
});

const { t } = useI18n();
const { can } = usePrivileges();

const editable = computed(() => can("studio.spaces.edit"));

const { resources, saving, create, update, toggleVisibility, remove, move } = useSpaceResources(props);

const shown = computed(() => resources.value.filter((row) => row.visibleToClient));
const hidden = computed(() => resources.value.filter((row) => !row.visibleToClient));

const formOpen = ref(false);
const editingResource = ref(null);
const errors = ref({});

const confirming = ref(null);

function openCreate() {
    editingResource.value = null;
    errors.value = {};
    formOpen.value = true;
}

function openEdit(resource) {
    editingResource.value = resource;
    errors.value = {};
    formOpen.value = true;
}

async function submit(payload) {
    const result = editingResource.value
        ? await update(editingResource.value.id, payload)
        : await create(payload);

    errors.value = result.errors;

    if (result.ok) {
        formOpen.value = false;
        editingResource.value = null;
    }
}

async function confirmDelete() {
    const resource = confirming.value;
    confirming.value = null;

    if (resource) await remove(resource.id);
}

/**
 * Où en est la ligne dans sa propre liste.
 *
 * Les flèches se lisent sur la liste entière, qui est ce que l'ordre stocké
 * décrit ; les désactiver d'après la position dans « ce que le client voit »
 * bloquerait une ligne qui a pourtant une voisine.
 */
function isFirst(resource) {
    return 0 === resources.value.findIndex((row) => row.id === resource.id);
}

function isLast(resource) {
    return resources.value.length - 1 === resources.value.findIndex((row) => row.id === resource.id);
}
</script>

<template>
    <div class="space-y-6">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <p class="text-xs text-muted sm:max-w-lg">{{ t("backend.studio.space_resources.intro") }}</p>

            <AppButton
                v-if="editable"
                size="sm"
                class="w-full justify-center sm:w-auto"
                v-on:click="openCreate"
            >
                <Plus class="h-3.5 w-3.5" :stroke-width="2" />
                {{ t("backend.studio.space_resources.add") }}
            </AppButton>
        </div>

        <AppNoData
            v-if="0 === resources.length"
            :message="t('backend.studio.space_resources.empty')"
            :hint="t('backend.studio.space_resources.empty_hint')"
        />

        <template
            v-for="group in [
                { key: 'shown', rows: shown, icon: Eye },
                { key: 'hidden', rows: hidden, icon: EyeOff },
            ]"
            :key="group.key"
        >
            <section v-if="group.rows.length" class="space-y-2">
                <h3 class="flex items-center gap-1.5 text-xs uppercase tracking-wide text-muted">
                    <component :is="group.icon" class="h-3 w-3 shrink-0" :stroke-width="2" />
                    {{ t(`backend.studio.space_resources.group_${group.key}`) }}
                </h3>

                <ul class="space-y-2">
                    <li
                        v-for="resource in group.rows"
                        :key="resource.id"
                        class="aurora-card flex flex-col gap-3 p-3 sm:flex-row sm:items-start sm:gap-4"
                    >
                        <div class="min-w-0 flex-1">
                            <SpaceResourceItem :resource="resource" />
                        </div>

                        <!-- **Les gestes sont écrits en toutes lettres sur
                             téléphone.** Une rangée d'icônes sur une carte ne
                             dit pas ce qu'elle fait, et la convention de
                             l'application est de les nommer dès qu'il n'y a
                             plus de survol pour les expliquer. -->
                        <div v-if="editable" class="flex flex-wrap items-center gap-1 sm:shrink-0">
                            <AppButton size="sm" variant="ghost" v-on:click="toggleVisibility(resource)">
                                <component
                                    :is="resource.visibleToClient ? EyeOff : Eye"
                                    class="h-3.5 w-3.5"
                                    :stroke-width="2"
                                />
                                <span class="sm:sr-only">
                                    {{ t(resource.visibleToClient ? "backend.studio.space_resources.hide" : "backend.studio.space_resources.show") }}
                                </span>
                            </AppButton>

                            <AppIconButton
                                :title="t('shared.common.edit')"
                                v-on:click="openEdit(resource)"
                            >
                                <Pencil class="h-3.5 w-3.5" :stroke-width="2" />
                            </AppIconButton>

                            <AppIconButton
                                :title="t('backend.studio.space_resources.move_up')"
                                :disabled="isFirst(resource)"
                                v-on:click="move(resource.id, -1)"
                            >
                                <ArrowUp class="h-3.5 w-3.5" :stroke-width="2" />
                            </AppIconButton>

                            <AppIconButton
                                :title="t('backend.studio.space_resources.move_down')"
                                :disabled="isLast(resource)"
                                v-on:click="move(resource.id, 1)"
                            >
                                <ArrowDown class="h-3.5 w-3.5" :stroke-width="2" />
                            </AppIconButton>

                            <AppIconButton
                                color="rose"
                                :title="t('shared.common.delete')"
                                v-on:click="confirming = resource"
                            >
                                <Trash2 class="h-3.5 w-3.5" :stroke-width="2" />
                            </AppIconButton>
                        </div>
                    </li>
                </ul>
            </section>
        </template>

        <SpaceResourceFormModal
            :show="formOpen"
            :resource="editingResource"
            :saving="saving"
            :errors="errors"
            v-on:close="formOpen = false"
            v-on:submit="submit"
        />

        <AppModal
            :show="null !== confirming"
            :title="t('backend.studio.space_resources.delete')"
            max-width="sm"
            v-on:close="confirming = null"
        >
            <p class="text-sm text-muted">
                {{ t("backend.studio.space_resources.delete_confirm", { label: confirming?.label ?? "" }) }}
            </p>

            <template #footer>
                <AppModalFooter>
                    <AppButton variant="ghost" size="md" v-on:click="confirming = null">
                        {{ t("shared.common.cancel") }}
                    </AppButton>
                    <AppButton variant="danger" size="md" v-on:click="confirmDelete">
                        <Trash2 class="h-3.5 w-3.5" :stroke-width="2" />
                        {{ t("shared.common.delete") }}
                    </AppButton>
                </AppModalFooter>
            </template>
        </AppModal>
    </div>
</template>
