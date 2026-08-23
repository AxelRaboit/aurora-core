import { ref } from "vue";
import { useRequest } from "@/shared/composables/http/backend/useRequest.js";
import { useSaveFlow } from "./useSaveFlow.js";

/**
 * The open reminder, and the four writes the screen makes to one.
 *
 * Separate from the events composable although both draw on the same grid: a
 * reminder has no span, no attendees and no recurrence, so it shares none of the
 * scope machinery - only the save flow, which is `useSaveFlow`.
 */
export function usePlanningReminders(props, { load, canCreate, onOpen }) {
    const { request } = useRequest();
    const { saving, errors, submit } = useSaveFlow(request);

    const openReminder = ref(null);

    function createReminder(draft = {}) {
        if (!canCreate.value) {
            return;
        }

        openReminder.value = draft;
        errors.value = {};
        onOpen();
    }

    function editReminder(reminder) {
        openReminder.value = reminder;
        errors.value = {};
    }

    function closeReminder() {
        openReminder.value = null;
    }

    async function saveReminder(form) {
        const id = openReminder.value?.id;
        const path = id
            ? props.updateReminderPathTemplate.replace("__id__", String(id))
            : props.createReminderPath;

        await submit(path, form, async () => {
            closeReminder();
            await load();
        });
    }

    async function removeReminder(reminder) {
        const data = await request(
            props.deleteReminderPathTemplate.replace(
                "__id__",
                String(reminder.id),
            ),
        );
        if (!data) return;

        closeReminder();
        await load();
    }

    /**
     * Ticking one off from the grid.
     *
     * Reloads rather than patching the row in place, because `overdue` is computed
     * on the server - a client flipping `completed` itself would leave a reminder
     * struck through and still red.
     */
    async function toggleReminder(reminder) {
        const data = await request(
            props.toggleReminderPathTemplate.replace(
                "__id__",
                String(reminder.id),
            ),
        );
        if (!data) return;

        await load();
    }

    return {
        openReminder,
        saving,
        errors,
        createReminder,
        editReminder,
        closeReminder,
        saveReminder,
        removeReminder,
        toggleReminder,
    };
}
