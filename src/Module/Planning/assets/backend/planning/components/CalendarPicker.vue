<script setup>
/**
 * The calendars, behind one button.
 *
 * The first attempt put them in the bar as a row of pills, and it was wrong the
 * moment there were more than four: the row scrolled, names were clipped
 * mid-word, and the reader had to drag sideways to find out what they were even
 * filtering. A control that hides what it contains is worse than the column it
 * replaced.
 *
 * A panel fixes all three at once - nothing is clipped, nothing scrolls
 * horizontally, and the bar stays one line whether there are two calendars or
 * fifty. The trigger still says which are showing, as a row of dots, so the
 * common question ("is Perso on?") is answerable without opening anything.
 *
 * The rows inside are `CalendarToggle` in its column shape - the same component
 * the phone's sheet uses, so there is one place where a calendar row is drawn.
 */
import { computed, onBeforeUnmount, ref, watch } from "vue";
import { useI18n } from "vue-i18n";
import { CalendarPlus, ChevronDown, Plus } from "lucide-vue-next";
import AppButton from "@/shared/components/action/AppButton.vue";
import AppIconButton from "@/shared/components/action/AppIconButton.vue";
import CalendarToggle from "./CalendarToggle.vue";

const props = defineProps({
    calendars: { type: Array, required: true },
    /** Ids the reader has folded away, as a Set. */
    hidden: { type: Set, required: true },
    /** Items per calendar in the range on screen, keyed by id. */
    countsByCalendar: { type: Object, required: true },
    canManageCalendars: { type: Boolean, default: false },
});

const emit = defineEmits(["create-calendar", "edit-calendar", "share-calendar", "toggle-calendar"]);

const { t } = useI18n();

const open = ref(false);
const root = ref(null);

const showing = computed(() =>
    props.calendars.filter((calendar) => !props.hidden.has(calendar.id)),
);

/**
 * Up to four dots on the trigger, then a count.
 *
 * Four because the button has to stay narrower than the label beside it; past
 * that the dots stop being individually readable anyway and a number says more.
 */
const dots = computed(() => showing.value.slice(0, 4));

function close() {
    open.value = false;
}

/**
 * Closed by a click anywhere else, and by Escape.
 *
 * Listeners are bound only while the panel is open, so a screen with a closed
 * picker is not paying for two document handlers. `pointerdown` rather than
 * `click`, or a click that opens a modal from inside the panel would close it
 * after the modal had already opened.
 */
function onPointerDown(event) {
    if (null !== root.value && !root.value.contains(event.target)) {
        close();
    }
}

function onKeydown(event) {
    if ("Escape" === event.key) {
        close();
    }
}

watch(open, (isOpen) => {
    if (isOpen) {
        document.addEventListener("pointerdown", onPointerDown);
        document.addEventListener("keydown", onKeydown);

        return;
    }

    document.removeEventListener("pointerdown", onPointerDown);
    document.removeEventListener("keydown", onKeydown);
});

onBeforeUnmount(() => {
    document.removeEventListener("pointerdown", onPointerDown);
    document.removeEventListener("keydown", onKeydown);
});

/** Editing opens a modal over this, so the panel has to get out of the way. */
function edit(calendar) {
    close();
    emit("edit-calendar", calendar);
}

/** Same for sharing, which opens its own. */
function share(calendar) {
    close();
    emit("share-calendar", calendar);
}

function create() {
    close();
    emit("create-calendar");
}
</script>

<template>
    <div ref="root" class="relative">
        <button
            type="button"
            class="flex cursor-pointer items-center gap-2 rounded-lg border border-line bg-surface px-2.5 py-1.5 text-sm text-secondary transition-colors hover:bg-surface-2"
            :aria-expanded="open"
            v-on:click="open = !open"
        >
            <!-- The dots are the state, so they come before the word. A reader
                 checking whether a calendar is showing looks at colour first. -->
            <span v-if="dots.length" class="flex items-center -space-x-1">
                <span
                    v-for="calendar in dots"
                    :key="calendar.id"
                    class="h-2.5 w-2.5 rounded-full ring-2 ring-surface"
                    :style="{ backgroundColor: `var(--chart-cat-${calendar.colourSlot})` }"
                />
            </span>
            <span>{{ t("backend.plannings.calendars") }}</span>
            <!-- How many are showing out of how many there are. Both numbers,
                 because "3" alone cannot say whether anything is folded away. -->
            <span class="text-2xs tabular-nums text-muted">
                {{ showing.length }}/{{ calendars.length }}
            </span>
            <ChevronDown
                class="h-3.5 w-3.5 shrink-0 transition-transform"
                :class="open ? 'rotate-180' : ''"
                :stroke-width="2"
            />
        </button>

        <!-- Right-aligned and absolute: the trigger sits at the end of the bar, so
             a panel growing leftwards stays on screen where one growing rightwards
             would run off it. Its own scroll only past about twelve calendars,
             which is a list rather than a filter by then. -->
        <div
            v-if="open"
            class="absolute end-0 z-30 mt-1 max-h-80 w-64 overflow-y-auto rounded-xl border border-line bg-surface p-3 shadow-xl"
        >
            <div class="mb-2 flex items-center gap-2">
                <p class="text-2xs font-semibold uppercase tracking-wider text-muted">
                    {{ t("backend.plannings.calendars") }}
                </p>
                <AppIconButton
                    v-if="canManageCalendars"
                    class="-my-1 ms-auto"
                    :title="t('backend.plannings.new_calendar')"
                    v-on:click="create"
                >
                    <Plus class="h-4 w-4" :stroke-width="2" />
                </AppIconButton>
            </div>

            <!-- Full width, full names, one per line. This is the shape the sheet
                 uses on a phone, and the reason nothing here needs truncating. -->
            <div class="space-y-1.5">
                <CalendarToggle
                    v-for="calendar in calendars"
                    :key="calendar.id"
                    :calendar="calendar"
                    :hidden="hidden.has(calendar.id)"
                    :count="countsByCalendar[calendar.id] ?? 0"
                    :can-manage="canManageCalendars"
                    v-on:toggle="emit('toggle-calendar', $event)"
                    v-on:edit="edit"
                    v-on:share="share"
                />
            </div>

            <AppButton
                v-if="!calendars.length && canManageCalendars"
                variant="primary"
                size="sm"
                class="w-full"
                v-on:click="create"
            >
                <CalendarPlus class="h-4 w-4" :stroke-width="2" />
                {{ t("backend.plannings.new_calendar") }}
            </AppButton>
        </div>
    </div>
</template>
