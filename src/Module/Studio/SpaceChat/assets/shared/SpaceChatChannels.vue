<script setup>
/**
 * The rail: which rooms this reader has, and which one is open.
 *
 * **Down the left, like every application people already use for this.** A
 * horizontal strip was the first shape and it was wrong twice over: it grows
 * sideways until it wraps into the conversation, and it puts the rooms on the
 * same line as the room's own name, so nothing says which of the two is the
 * list and which is the place you are. A column says it by position alone.
 *
 * It folds to a row above the conversation under `md`, because a phone has no
 * left to give. Same component, two arrangements: two components would be two
 * lists to keep in step.
 *
 * **Drawn on both sides, controllable on one.** The client sees the rooms that
 * were opened to them and switches between them; the button that opens a room
 * is bound to a path the client's page is never handed.
 */
import { computed, ref } from "vue";
import { useI18n } from "vue-i18n";
import { Check, EyeOff, Hash, Plus, X } from "lucide-vue-next";
import AppButton from "@/shared/components/action/AppButton.vue";

const props = defineProps({
    channels: { type: Array, default: () => [] },
    current: { type: [Number, null], default: null },
    /** Null on the client's page: only the studio opens rooms. */
    createPath: { type: String, default: null },
});

const emit = defineEmits(["select", "create"]);

const { t } = useI18n();

const naming = ref(false);
const draft = ref("");

const canArrange = computed(() => !!props.createPath);

function confirmName() {
    const name = draft.value.trim();
    if ("" === name) return;

    emit("create", name);
    draft.value = "";
    naming.value = false;
}
</script>

<template>
    <aside
        class="flex shrink-0 flex-col gap-2 border-b border-line/60 px-3 py-2 md:w-52 md:border-b-0 md:border-r md:p-3"
    >
        <!-- L'intitulé disparaît sur téléphone : une colonne de gauche a besoin
             qu'on dise ce qu'elle est, une ligne de pastilles au-dessus de la
             conversation se lit sans. -->
        <span
            class="hidden px-1 text-[0.65rem] font-medium uppercase tracking-wider text-muted md:block"
        >
            {{ t("shared.space_chat.channels.label") }}
        </span>

        <!-- Une seule ligne qui défile plutôt qu'un pavé qui passe à la ligne :
             sur un écran de 812 pixels de haut, trois canaux repliés prenaient
             107 pixels à la conversation, qui est ce qu'on est venu lire. -->
        <div
            class="flex flex-row flex-nowrap gap-0.5 overflow-x-auto md:flex-col md:overflow-x-visible"
        >
            <button
                v-for="channel in channels"
                :key="channel.id"
                type="button"
                class="flex shrink-0 items-center gap-1.5 rounded-md px-2 py-1.5 text-left text-xs transition-colors md:w-full md:shrink"
                :class="
                    channel.id === current
                        ? 'bg-accent/15 text-accent'
                        : 'text-secondary hover:bg-surface-2/60 hover:text-primary'
                "
                v-on:click="emit('select', channel.id)"
            >
                <Hash class="h-3 w-3 shrink-0" :stroke-width="2" />
                <span class="truncate">{{ channel.name }}</span>

                <!-- Dit sur la ligne du canal, pas seulement dans ses réglages :
                     ce qui se tape là ne sort pas de l'agence, et c'est à savoir
                     avant d'écrire plutôt qu'après. -->
                <EyeOff
                    v-if="canArrange && !channel.openToClient"
                    class="ml-auto h-3 w-3 shrink-0 opacity-60"
                    :stroke-width="2"
                    :aria-label="t('shared.space_chat.channels.internal')"
                />
            </button>

            <button
                v-if="canArrange && !naming"
                type="button"
                class="flex shrink-0 items-center gap-1.5 rounded-md px-2 py-1.5 text-left text-xs text-muted transition-colors hover:bg-surface-2/60 hover:text-primary md:w-full md:shrink"
                v-on:click="naming = true"
            >
                <Plus class="h-3 w-3 shrink-0" :stroke-width="2" />
                {{ t("shared.space_chat.channels.new") }}
            </button>
        </div>

        <div v-if="naming" class="flex flex-col gap-1.5">
            <input
                id="space-chat-channel-name"
                v-model="draft"
                type="text"
                class="w-full rounded-md border border-line/60 bg-surface px-2 py-1 text-xs text-primary"
                :placeholder="t('shared.space_chat.channels.name_placeholder')"
                v-on:keydown.enter.prevent="confirmName"
                v-on:keydown.esc.prevent="naming = false"
            >
            <div class="flex items-center gap-1.5">
                <AppButton size="xs" variant="primary" :icon="Check" v-on:click="confirmName">
                    {{ t("shared.space_chat.channels.create") }}
                </AppButton>
                <AppButton size="xs" variant="ghost" :icon="X" v-on:click="naming = false">
                    {{ t("shared.space_chat.channels.cancel") }}
                </AppButton>
            </div>
        </div>
    </aside>
</template>
