<script setup>
/**
 * The resources of a space: what lives elsewhere and gets looked for every
 * time.
 *
 * **Two lists on a single screen, and that is what makes the feature.** The
 * mockup shown to the client and the dashboard that is not shown are filed
 * in the same place; what separates them is a checkbox, row by row. They are
 * therefore drawn separately rather than mixed with a badge to spot: "what
 * the client sees" reads at a glance or does not read at all.
 *
 * **Visibility is one click away and its effect is announced.** Publishing
 * something by accident and publishing something are the same gesture on
 * screen; what tells them apart is knowing what just happened.
 */
import AppGuide from "@/shared/components/feedback/AppGuide.vue";
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
// Showing or hiding from the client: the right to share the space, on top
// of the right to edit it. The rule of the whole space.
const canShowToClient = computed(() => editable.value && can("studio.spaces.share"));

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
 * Where the row stands in its own list.
 *
 * The arrows read on the whole list, which is what the stored order
 * describes; disabling them based on the position in "what the client sees"
 * would block a row that does have a neighbour.
 */
function isFirst(resource) {
    return 0 === resources.value.findIndex((row) => row.id === resource.id);
}

function isLast(resource) {
    return resources.value.length - 1 === resources.value.findIndex((row) => row.id === resource.id);
}
</script>

<template>
    <div class="aurora-stack">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <p class="text-xs text-muted sm:max-w-lg">{{ t("suite.studio.space_resources.intro") }}</p>

            <AppButton
                v-if="editable"
                variant="ghost"
                size="sm"
                class="w-full justify-center sm:w-auto"
                v-on:click="openCreate"
            >
                <Plus class="h-3.5 w-3.5" :stroke-width="2" />
                {{ t("suite.studio.space_resources.add") }}
            </AppButton>
        </div>

        <!-- The guide to the screen, next to what it explains;
     collapsed or expanded, the choice applies to all panels. -->
        <AppGuide :title="t('suite.studio.space_resources.guide.title')" storage-key="space-resources">
            <ol class="m-0 flex list-decimal flex-col gap-1.5 pl-5">
                <li v-for="step in 4" :key="step">{{ t(`suite.studio.space_resources.guide.step_${step}`) }}</li>
            </ol>
        </AppGuide>
        <AppNoData
            v-if="0 === resources.length"
            :message="t('suite.studio.space_resources.empty')"
            :hint="t('suite.studio.space_resources.empty_hint')"
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
                    {{ t(`suite.studio.space_resources.group_${group.key}`) }}
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

                        <!-- **The actions are spelled out in full on a
                             phone.** A row of icons on a card does not say
                             what it does, and the convention of the
                             application is to name them as soon as there is
                             no hover left to explain them. -->
                        <div v-if="editable" class="flex flex-wrap items-center gap-1 sm:shrink-0">
                            <AppButton v-if="canShowToClient" size="sm" variant="ghost" v-on:click="toggleVisibility(resource)">
                                <component
                                    :is="resource.visibleToClient ? EyeOff : Eye"
                                    class="h-3.5 w-3.5"
                                    :stroke-width="2"
                                />
                                <span class="sm:sr-only">
                                    {{ t(resource.visibleToClient ? "suite.studio.space_resources.hide" : "suite.studio.space_resources.show") }}
                                </span>
                            </AppButton>

                            <AppIconButton
                                :title="t('shared.common.edit')"
                                v-on:click="openEdit(resource)"
                            >
                                <Pencil class="h-3.5 w-3.5" :stroke-width="2" />
                            </AppIconButton>

                            <AppIconButton
                                :title="t('suite.studio.space_resources.move_up')"
                                :disabled="isFirst(resource)"
                                v-on:click="move(resource.id, -1)"
                            >
                                <ArrowUp class="h-3.5 w-3.5" :stroke-width="2" />
                            </AppIconButton>

                            <AppIconButton
                                :title="t('suite.studio.space_resources.move_down')"
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
            :can-show-to-client="canShowToClient"
            v-on:close="formOpen = false"
            v-on:submit="submit"
        />

        <AppModal
            :show="null !== confirming"
            :title="t('suite.studio.space_resources.delete')"
            max-width="sm"
            v-on:close="confirming = null"
        >
            <p class="text-sm text-muted">
                {{ t("suite.studio.space_resources.delete_confirm", { label: confirming?.label ?? "" }) }}
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
