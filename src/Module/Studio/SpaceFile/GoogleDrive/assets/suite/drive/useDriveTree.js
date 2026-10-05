import { computed, ref } from "vue";

/**
 * L'arborescence d'un dossier Drive, reconstruite dans le navigateur.
 *
 * **La descente a déjà tout ramené.** Chaque fichier arrive avec son chemin,
 * donc les dossiers se déduisent de la liste : entrer dans un dossier et en
 * ressortir ne coûte aucun appel. Redemander le contenu à chaque ouverture
 * paierait deux fois ce qu'on a en main.
 *
 * Posé ici plutôt que dans l'écran parce que deux surfaces le lisent : la vue
 * Drive d'un espace, et le sélecteur qui accroche un fichier à une fiche. Une
 * seconde copie aurait fini par diverger sur un détail, et le détail qui
 * diverge dans un arbre est celui qui perd un fichier.
 *
 * @param {import("vue").Ref<Array>} files la liste plate, chaque entrée portant `path`
 */
export function useDriveTree(files) {
    /** Le dossier ouvert, « » à la racine. */
    const cwd = ref("");

    /** « Contrats/2026 » devient les deux marches qui y mènent. */
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

    /** Les sous-dossiers directs du dossier ouvert, déduits des chemins. */
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
            .sort((a, b) => a.name.localeCompare(b.name));
    });

    /** Les fichiers posés directement dans le dossier ouvert. */
    const visible = computed(() =>
        files.value.filter((file) => file.path === cwd.value),
    );

    return { cwd, breadcrumb, folders, visible, goTo, open, reset };
}
