import { ref, watchEffect } from "vue";
import { buildTree as buildHierarchicalTree } from "@/shared/composables/tree/useHierarchicalTree.js";

/**
 * L'arborescence du menu : des dossiers, et ce qu'ils contiennent.
 *
 * Elle n'a porté que des dossiers pendant une version, ce qui était une
 * demi-réponse : on voyait le rangement sans voir ce qui est rangé, et
 * atteindre une note demandait d'ouvrir le dossier dans la bibliothèque. Un
 * dossier se déplie ici et montre ses notes, comme dans n'importe quel
 * explorateur - la différence avec l'ancien arbre étant qu'une note est une
 * feuille, jamais un rangement.
 *
 * Chaque nœud porte son `kind`, `folder` ou `note`, parce que les deux se
 * ressemblent à l'écran et ne font pas la même chose : l'un s'ouvre, l'autre
 * se lit. Les identifiants se recoupent d'une table à l'autre, donc les clés
 * de rendu valent `kind:id`.
 *
 * **Le filtre garde les porteurs.** Un dossier dont le nom ne correspond pas
 * reste affiché quand il contient un résultat, sinon le résultat n'aurait
 * plus de branche à laquelle se rattacher. `contentMatchIdsRef` apporte les
 * notes trouvées par leur texte, que le navigateur n'a pas : les corps sont
 * chiffrés et restent au serveur.
 */
export function useNoteTree(
    foldersRef,
    queryRef = null,
    notesRef = null,
    contentMatchIdsRef = null,
) {
    const tree = ref([]);

    watchEffect(() => {
        const query = (queryRef?.value ?? "").trim().toLowerCase();
        const notes = notesRef?.value ?? [];
        const contentIds = contentMatchIdsRef?.value ?? null;

        const notesByFolder = new Map();
        for (const note of notes) {
            const key =
                null === note.folderId || undefined === note.folderId
                    ? 0
                    : Number(note.folderId);

            if (!notesByFolder.has(key)) notesByFolder.set(key, []);

            notesByFolder.get(key).push({
                ...note,
                kind: "note",
                key: `note:${note.id}`,
                children: [],
                matched: true,
                team: note.sharedAt ? "direct" : null,
            });
        }

        const folders = buildHierarchicalTree(foldersRef.value).map((node) =>
            decorate(node, notesByFolder, false),
        );

        // Les notes de la racine ferment la liste : elles sont à côté des
        // dossiers, pas dedans, et les mettre en tête repousserait le
        // rangement sous ce qui n'est pas rangé.
        const full = [...folders, ...(notesByFolder.get(0) ?? [])];

        tree.value = "" === query ? full : filterTree(full, query, contentIds);
    });

    /**
     * `team` dit qui voit la ligne : `direct` quand elle est partagée
     * elle-même, `inherited` quand un dossier au-dessus l'est - la règle de
     * lecture du serveur, qui remonte tous les parents.
     */
    function decorate(node, notesByFolder, insideShared) {
        const shared = Boolean(node.sharedAt);
        const visible = shared || insideShared;

        const children = (node.children ?? []).map((child) =>
            decorate(child, notesByFolder, visible),
        );

        const notes = (notesByFolder.get(Number(node.id)) ?? []).map((note) =>
            "direct" === note.team || !visible
                ? note
                : { ...note, team: "inherited" },
        );

        return {
            ...node,
            kind: "folder",
            key: `folder:${node.id}`,
            matched: true,
            team: shared ? "direct" : insideShared ? "inherited" : null,
            children: [...children, ...notes],
        };
    }

    function matches(node, query, contentIds) {
        if ("folder" === node.kind) {
            return String(node.name ?? "")
                .toLowerCase()
                .includes(query);
        }

        if (
            String(node.title ?? "")
                .toLowerCase()
                .includes(query)
        )
            return true;

        if (contentIds?.has(Number(node.id))) return true;

        return (node.tags ?? []).some((tag) =>
            String(tag).toLowerCase().includes(query),
        );
    }

    function filterTree(nodes, query, contentIds) {
        const kept = [];

        for (const node of nodes) {
            const children = filterTree(node.children ?? [], query, contentIds);
            const selfMatch = matches(node, query, contentIds);

            if (selfMatch || children.length > 0) {
                kept.push({ ...node, matched: selfMatch, children });
            }
        }

        return kept;
    }

    return { tree };
}

/**
 * Les dossiers d'un arbre filtré, pour les déplier tous.
 *
 * Une recherche qui laisse les branches fermées ne montre rien : ce qu'elle
 * a trouvé est justement ce qui est replié.
 *
 * @returns {Set<number>} les identifiants des dossiers rencontrés
 */
export function folderIdsIn(nodes) {
    const ids = new Set();

    const walk = (list) => {
        for (const node of list) {
            if ("folder" !== node.kind) continue;

            ids.add(Number(node.id));
            walk(node.children ?? []);
        }
    };

    walk(nodes);

    return ids;
}
