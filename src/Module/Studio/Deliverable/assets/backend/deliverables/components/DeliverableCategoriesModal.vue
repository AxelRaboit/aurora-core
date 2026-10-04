<script setup>
/**
 * Les catégories des livrables de Studio : les créer, les renommer, leur
 * donner une couleur, les ranger et les supprimer.
 *
 * Chaque ligne s'enregistre seule, au bouton qui apparaît quand elle a
 * changé : renommer « Audit » ne doit pas attendre qu'on ait fini de ranger
 * les autres. Supprimer demande une confirmation sur la ligne même, et dit
 * que les livrables restent, sans catégorie.
 */
import { ref, watch } from "vue";
import { useI18n } from "vue-i18n";
import { toast } from "vue-sonner";
import { ArrowDown, ArrowUp, Check, Plus, Tags, Trash2, X } from "lucide-vue-next";
import { useRequest } from "@/shared/composables/http/backend/useRequest.js";
import { buildPath } from "@/shared/utils/http/buildPath.js";
import AppButton from "@/shared/components/action/AppButton.vue";
import AppColorSwatch from "@/shared/components/form/picker/AppColorSwatch.vue";
import AppIconButton from "@/shared/components/action/AppIconButton.vue";
import AppInput from "@/shared/components/form/input/AppInput.vue";
import AppModal from "@/shared/components/overlay/AppModal.vue";
import AppModalFooter from "@/shared/components/overlay/AppModalFooter.vue";
import AppNoData from "@/shared/components/feedback/AppNoData.vue";

const props = defineProps({
    show: { type: Boolean, default: false },
    categories: { type: Array, default: () => [] },
    createPath: { type: String, required: true },
    updatePathTemplate: { type: String, required: true },
    deletePathTemplate: { type: String, required: true },
    reorderPath: { type: String, required: true },
});

/** La réponse du serveur : les catégories et les deux rayons, que la liste reprend. */
const emit = defineEmits(["close", "changed"]);

const { t } = useI18n();
const { request } = useRequest();

const DEFAULT_COLOR = "#8b6cff";

/** Les lignes en cours d'édition, une copie de ce que dit le serveur. */
const rows = ref([]);
const busy = ref(false);
const confirmDelete = ref(null);
const errors = ref({});
const draft = ref({ name: "", color: DEFAULT_COLOR });

function reset(categories) {
    rows.value = categories.map((category) => ({
        id: category.id,
        name: category.name,
        color: category.color ?? DEFAULT_COLOR,
        saved: { name: category.name, color: category.color ?? DEFAULT_COLOR },
    }));
}

watch(
    () => props.show,
    (open) => {
        if (!open) return;
        reset(props.categories);
        draft.value = { name: "", color: DEFAULT_COLOR };
        confirmDelete.value = null;
        errors.value = {};
    },
    { immediate: true },
);

function isDirty(row) {
    return row.name.trim() !== row.saved.name || row.color !== row.saved.color;
}

/** Une réponse réussie remplace tout : noms, ordre, et les cartes derrière la fenêtre. */
function apply(data) {
    reset(data.categories ?? []);
    emit("changed", data);
}

async function send(path, payload, key) {
    if (busy.value) return null;

    busy.value = true;
    errors.value = {};
    try {
        const data = await request(path, payload);
        if (!data?.success) {
            errors.value = { [key]: data?.errors ?? {} };

            return null;
        }

        apply(data);

        return data;
    } finally {
        busy.value = false;
    }
}

async function create() {
    const data = await send(props.createPath, { name: draft.value.name, color: draft.value.color }, "new");
    if (data) {
        draft.value = { name: "", color: DEFAULT_COLOR };
        toast.success(t("backend.studio.deliverables.categories.created"));
    }
}

async function save(row) {
    const data = await send(buildPath(props.updatePathTemplate, { id: row.id }), { name: row.name, color: row.color }, row.id);
    if (data) toast.success(t("backend.studio.deliverables.categories.saved"));
}

async function remove(row) {
    const data = await send(buildPath(props.deletePathTemplate, { id: row.id }), {}, row.id);
    if (data) {
        confirmDelete.value = null;
        toast.success(t("backend.studio.deliverables.categories.deleted"));
    }
}

/** Monter ou descendre d'un cran, et l'ordre entier part au serveur. */
async function move(index, offset) {
    const target = index + offset;
    if (target < 0 || target >= rows.value.length) return;

    const ids = rows.value.map((row) => row.id);
    [ids[index], ids[target]] = [ids[target], ids[index]];
    await send(props.reorderPath, { ids }, "order");
}

function errorOf(key, field) {
    return errors.value[key]?.[field] ?? "";
}
</script>

<template>
    <AppModal
        :show="show"
        max-width="lg"
        :title="t('backend.studio.deliverables.categories.manage_title')"
        :icon="Tags"
        v-on:close="emit('close')"
    >
        <div class="space-y-4">
            <p class="text-sm text-secondary">{{ t("backend.studio.deliverables.categories.manage_intro") }}</p>

            <AppNoData v-if="!rows.length" :message="t('backend.studio.deliverables.categories.empty')" />

            <ul v-else class="m-0 list-none space-y-2 p-0">
                <li
                    v-for="(row, index) in rows"
                    :key="row.id"
                    class="rounded-lg border border-line p-2"
                >
                    <div class="flex items-start gap-2">
                        <AppColorSwatch v-model="row.color" size="md" class="mt-1 shrink-0" />
                        <AppInput
                            v-model="row.name"
                            class="min-w-0 flex-1"
                            :placeholder="t('backend.studio.deliverables.categories.new_placeholder')"
                            :aria-label="t('backend.studio.deliverables.categories.name')"
                            :error="errorOf(row.id, 'name')"
                            v-on:keydown.enter.prevent="isDirty(row) && save(row)"
                        />
                        <div class="flex shrink-0 items-center gap-0.5 pt-0.5">
                            <AppIconButton
                                v-if="isDirty(row)"
                                :title="t('shared.common.save')"
                                color="accent"
                                active
                                :disabled="busy"
                                v-on:click="save(row)"
                            >
                                <Check class="h-4 w-4" :stroke-width="2" />
                            </AppIconButton>
                            <AppIconButton
                                :title="t('backend.studio.deliverables.categories.move_up')"
                                :disabled="busy || 0 === index"
                                v-on:click="move(index, -1)"
                            >
                                <ArrowUp class="h-4 w-4" :stroke-width="2" />
                            </AppIconButton>
                            <AppIconButton
                                :title="t('backend.studio.deliverables.categories.move_down')"
                                :disabled="busy || index === rows.length - 1"
                                v-on:click="move(index, 1)"
                            >
                                <ArrowDown class="h-4 w-4" :stroke-width="2" />
                            </AppIconButton>
                            <AppIconButton
                                :title="t('shared.common.delete')"
                                color="rose"
                                :disabled="busy"
                                v-on:click="confirmDelete = row.id"
                            >
                                <Trash2 class="h-4 w-4" :stroke-width="2" />
                            </AppIconButton>
                        </div>
                    </div>
                    <!-- La confirmation sur la ligne même : on voit ce qu'on supprime. -->
                    <div
                        v-if="confirmDelete === row.id"
                        class="mt-2 flex flex-col gap-2 rounded-md bg-surface-2 p-2 text-xs text-secondary sm:flex-row sm:items-center sm:justify-between"
                    >
                        <span>{{ t("backend.studio.deliverables.categories.delete_confirm", { name: row.saved.name }) }}</span>
                        <span class="flex shrink-0 gap-2">
                            <AppButton variant="ghost" size="sm" v-on:click="confirmDelete = null">
                                <X class="h-3.5 w-3.5" :stroke-width="2" /> {{ t("shared.common.cancel") }}
                            </AppButton>
                            <AppButton variant="danger" size="sm" :loading="busy" v-on:click="remove(row)">
                                <Trash2 class="h-3.5 w-3.5" :stroke-width="2" /> {{ t("shared.common.delete") }}
                            </AppButton>
                        </span>
                    </div>
                </li>
            </ul>

            <!-- La nouvelle catégorie, en bas : elle se range à la fin. -->
            <form class="flex items-start gap-2 rounded-lg border border-dashed border-line p-2" v-on:submit.prevent="create">
                <AppColorSwatch v-model="draft.color" size="md" class="mt-1 shrink-0" />
                <AppInput
                    v-model="draft.name"
                    class="min-w-0 flex-1"
                    :placeholder="t('backend.studio.deliverables.categories.new_placeholder')"
                    :aria-label="t('backend.studio.deliverables.categories.name')"
                    :error="errorOf('new', 'name')"
                />
                <AppButton
                    variant="ghost"
                    size="md"
                    type="submit"
                    :loading="busy"
                    :disabled="!draft.name.trim()"
                >
                    <Plus class="h-3.5 w-3.5" :stroke-width="2" /> {{ t("backend.studio.deliverables.categories.add") }}
                </AppButton>
            </form>
        </div>

        <template #footer>
            <AppModalFooter>
                <AppButton variant="ghost" size="md" v-on:click="emit('close')">
                    <X class="h-3.5 w-3.5" :stroke-width="2" /> {{ t("shared.common.close") }}
                </AppButton>
            </AppModalFooter>
        </template>
    </AppModal>
</template>
