import { ref } from "vue";

/**
 * One form's in-flight state, and the three answers a save can come back with.
 *
 * Events, reminders and calendars each had their own copy of this, identical down
 * to the `finally`: raise `saving`, clear the field errors, send, and then tell
 * apart a transport failure from a rejected form from a success. Only the last of
 * those differs between the three, so only that is passed in.
 *
 * `saving` and `errors` stay one pair per caller rather than one shared pair, so
 * saving a calendar does not spin the event modal's button.
 */
export function useSaveFlow(request) {
    const saving = ref(false);
    const errors = ref({});

    /**
     * @param {string} path
     * @param {object} form
     * @param {(data: object) => (void | Promise<void>)} onSaved
     */
    async function submit(path, form, onSaved) {
        saving.value = true;
        errors.value = {};

        try {
            const data = await request(path, form);

            // `useRequest` has already said something went wrong; what it cannot
            // say is which field, so a rejected form keeps the modal open and
            // shows them against the inputs.
            if (!data) {
                return;
            }

            if (data.errors) {
                errors.value = data.errors;

                return;
            }

            await onSaved(data);
        } finally {
            saving.value = false;
        }
    }

    return { saving, errors, submit };
}
