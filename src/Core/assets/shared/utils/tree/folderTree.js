/**
 * Generic tree utilities for folder-like structures.
 *
 * Works with any flat list of objects that have { id, parentId, name } fields.
 * Used by the Media and Vault modules; add new consumers as needed.
 *
 * Keep this file free of Vue reactivity and module-specific logic.
 */

/**
 * Builds a nested tree from a flat list.
 * Orphaned nodes (parentId points to a non-existent id) are promoted to root.
 *
 * @param {Array}    list    - flat array of folder objects
 * @param {Function} sortFn  - optional comparator; defaults to alphabetical by name
 * @returns {Array} root nodes, each with a `children` array
 */
export function buildFolderTree(
    list,
    sortFn = (a, b) => a.name.localeCompare(b.name),
) {
    const byId = new Map(list.map((f) => [f.id, { ...f, children: [] }]));
    const roots = [];

    for (const node of byId.values()) {
        if (node.parentId && byId.has(node.parentId)) {
            byId.get(node.parentId).children.push(node);
        } else {
            roots.push(node);
        }
    }

    // A node can be attached to a parent and still be unreachable: two folders
    // filed inside each other point at one another, so neither lands in
    // `roots` and both disappear - along with everything beneath them - from
    // every screen built on this function. The rows are still in the database,
    // which is what makes it so quiet.
    //
    // The server refuses to create that state, but data written before it did
    // still exists, and a tree that hides rows is a tree nobody can use to
    // repair them. So anything the walk cannot reach is promoted to a root,
    // where it can be seen and moved.
    const reachable = new Set();
    const walk = (nodes) => {
        for (const node of nodes) {
            if (reachable.has(node.id)) continue;
            reachable.add(node.id);
            walk(node.children);
        }
    };
    walk(roots);

    for (const node of byId.values()) {
        if (reachable.has(node.id)) continue;

        const parent = byId.get(node.parentId);
        if (parent) {
            parent.children = parent.children.filter((c) => c.id !== node.id);
        }
        roots.push(node);
        walk([node]);
    }

    const sort = (nodes) => {
        nodes.sort(sortFn);
        nodes.forEach((n) => sort(n.children));
    };
    sort(roots);

    return roots;
}

/**
 * Flattens a tree (output of buildFolderTree) into a depth-annotated list,
 * respecting collapsed state.
 *
 * @param {Array}   nodes        - root nodes from buildFolderTree
 * @param {number}  depth        - current depth (start at 0)
 * @param {Set|null} collapsedIds - set of folder ids whose children are hidden
 * @returns {Array} flat list with `depth` and `childCount` added to each node
 */
export function flattenFolders(nodes, depth = 0, collapsedIds = null) {
    const result = [];
    for (const node of nodes) {
        result.push({ ...node, depth, childCount: node.children.length });
        const isCollapsed = collapsedIds?.has(node.id) ?? false;
        if (node.children.length && !isCollapsed) {
            result.push(
                ...flattenFolders(node.children, depth + 1, collapsedIds),
            );
        }
    }
    return result;
}

/**
 * Returns the ancestor chain from root to the given folder (inclusive).
 *
 * @param {Array}  folders  - flat folder list
 * @param {number} folderId
 * @returns {Array} ordered from root to the folder
 */
export function getFolderAncestors(folders, folderId) {
    const ancestors = [];
    let current = folders.find((f) => f.id === folderId);
    while (current) {
        ancestors.unshift(current);
        current = current.parentId
            ? folders.find((f) => f.id === current.parentId)
            : null;
    }
    return ancestors;
}

/**
 * Returns the ids of a folder and all its descendants (recursive).
 *
 * @param {Array}  folders  - flat folder list
 * @param {number} folderId
 * @returns {number[]}
 */
export function getFolderDescendantIds(folders, folderId) {
    const result = [folderId];
    const children = folders.filter((f) => (f.parentId ?? null) === folderId);
    for (const child of children) {
        result.push(...getFolderDescendantIds(folders, child.id));
    }
    return result;
}
