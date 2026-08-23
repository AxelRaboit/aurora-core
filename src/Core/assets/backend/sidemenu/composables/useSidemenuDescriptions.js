import { ref } from "vue";

/**
 * Whether each menu item shows its description under its label.
 *
 * The descriptions are not new - they are the tooltips every row already has.
 * This turns them into standing text, which is what someone still learning the
 * backend wants and what someone who knows it does not, so it is a preference
 * rather than a decision taken for everybody.
 *
 * Kept on the user beside the sections they hid and the colours they picked
 * (see `useSidemenuCollapse` for the argument), and rendered from the server on
 * first paint so the menu does not draw one shape and change to the other once
 * a script has run.
 *
 * No cross-mount event here, unlike collapsing: the switch and the rows it
 * affects are in the same component tree, so a ref reaches both. Adding an
 * event for a value nothing outside this mount reads would be machinery with
 * no second party - see pattern_cross_mount_state_sync, which is about the
 * case where there *is* one.
 *
 * The request is not awaited. A switch that waits for a round-trip before
 * moving feels broken, and the worst case of a failed save is the menu coming
 * back in its old shape on the next page.
 */
export function useSidemenuDescriptions(path = "", initial = true) {
    const showDescriptions = ref(initial);

    function persist(show) {
        if (!path) return;

        void fetch(path, {
            method: "POST",
            headers: {
                Accept: "application/json",
                "Content-Type": "application/json",
                "X-Requested-With": "XMLHttpRequest",
            },
            body: JSON.stringify({ show }),
        }).catch(() => {
            /* ignored - see above: the menu keeps working either way */
        });
    }

    function toggleDescriptions() {
        showDescriptions.value = !showDescriptions.value;
        persist(showDescriptions.value);
    }

    return { showDescriptions, toggleDescriptions };
}
