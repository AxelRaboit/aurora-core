import { computed, ref } from "vue";

/**
 * A Drive folder's tree, rebuilt in the browser.
 *
 * **The descent already brought everything back.** Each file arrives with its
 * path, so the folders are deduced from the list: entering a folder and
 * coming back out costs no call. Asking for the content again on every open
 * would pay twice for what is already in hand.
 *
 * Placed here rather than in the screen because two surfaces read it: a
 * space's Drive view, and the picker that attaches a file to a record. A
 * second copy would have ended up diverging on a detail, and the detail that
 * diverges in a tree is the one that loses a file.
 *
 * @param {import("vue").Ref<Array>} files the flat list, each entry carrying `path`
 */
export function useDriveTree(files) {
    /** The open folder, "" at the root. */
    const cwd = ref("");

    /** "Contrats/2026" becomes the two steps that lead to it. */
    const breadcrumb = computed(() =>
        "" === cwd.value ? [] : cwd.value.split("/"),
    );

    function goTo(depth) {
        cwd.value = breadcrumb.value.slice(0, depth).join("/");
    }

    function open(name) {
        cwd.value = cwd.value ? `${cwd.value}/${name}` : name;
    }

    function reset() {
        cwd.value = "";
    }

    /** The direct subfolders of the open folder, deduced from the paths. */
    const folders = computed(() => {
        const prefix = "" === cwd.value ? "" : cwd.value + "/";
        const names = new Map();

        for (const file of files.value) {
            if (file.path === cwd.value || !file.path.startsWith(prefix))
                continue;

            const name = file.path.slice(prefix.length).split("/")[0];
            names.set(name, (names.get(name) ?? 0) + 1);
        }

        return [...names]
            .map(([name, count]) => ({ name, count }))
            .sort((left, right) => left.name.localeCompare(right.name));
    });

    /** The files placed directly in the open folder. */
    const visible = computed(() =>
        files.value.filter((file) => file.path === cwd.value),
    );

    return { cwd, breadcrumb, folders, visible, goTo, open, reset };
}
