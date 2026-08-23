/**
 * Shared hierarchical-tree helpers for backend admin trees
 * (taxonomies, listing categories, …).
 *
 * Pure functions - no Vue reactivity, no persistence. Each consumer
 * keeps its own debounce / persist / locale logic.
 */

export function buildTree(flatItems) {
    const byId = new Map(
        flatItems.map((item) => [item.id, { ...item, children: [] }]),
    );
    const roots = [];
    for (const node of byId.values()) {
        if (node.parentId && byId.has(node.parentId)) {
            byId.get(node.parentId).children.push(node);
        } else {
            roots.push(node);
        }
    }
    sortRecursive(roots);
    return roots;
}

export function sortRecursive(nodes) {
    nodes.sort((a, b) => a.position - b.position || a.id - b.id);
    nodes.forEach((node) => sortRecursive(node.children ?? []));
}

export function flattenTreeForReorder(nodes, parentId = null) {
    const entries = [];
    nodes.forEach((node, index) => {
        entries.push({ id: node.id, parentId, position: index });
        if (node.children?.length) {
            entries.push(...flattenTreeForReorder(node.children, node.id));
        }
    });
    return entries;
}

export function collectDescendantIds(node) {
    const ids = new Set();
    if (!node) return ids;
    ids.add(node.id);
    for (const child of node.children ?? []) {
        collectDescendantIds(child).forEach((id) => ids.add(id));
    }
    return ids;
}

export function findNodeInTree(nodes, id) {
    for (const node of nodes) {
        if (node.id === id) return node;
        const found = findNodeInTree(node.children ?? [], id);
        if (found) return found;
    }
    return null;
}
