<script setup>
/**
 * Le carnet, dans le menu latéral.
 *
 * Il n'a porté que des dossiers pendant une version, et c'était une
 * demi-réponse : on voyait le rangement sans voir ce qui est rangé. **Un
 * dossier se déplie ici** et montre ses notes, comme dans n'importe quel
 * explorateur ; la bibliothèque reste l'écran où l'on regarde, trie et
 * range, le panneau celui d'où l'on atteint.
 *
 * **Le dépliage se retient.** Il vit dans le navigateur, pas dans le
 * carnet : c'est une habitude de lecture, elle ne regarde que la personne
 * assise là, et elle doit survivre au changement de page - le panneau est
 * remonté à chaque navigation.
 *
 * **Les lignes sont de vraies adresses.** Un dossier est une page
 * (`/backend/notes/markdown/folder/42`), une note aussi, donc les deux
 * s'envoient et le clic du milieu se comporte. Au clic simple le panneau
 * demande d'abord à la page, par `modulePanelBridge` : elle est montée, elle
 * prend le clic et change de dossier ou de note sur place. Personne à
 * l'écoute veut dire que le lecteur est ailleurs dans le module, et le lien
 * navigue.
 *
 * **La recherche reste globale**, et c'est le seul endroit où elle l'est :
 * elle traverse les titres, les étiquettes et le texte des notes - ce
 * dernier côté serveur, les corps étant chiffrés - et l'arbre s'ouvre sur ce
 * qu'elle a trouvé.
 */
import { computed, onMounted, onUnmounted, ref, watch } from "vue";
import { useI18n } from "vue-i18n";
import { BookOpen, ChevronDown, ChevronRight, ChevronsDownUp, Download, FileText, Folder, Pin, PinOff, Plus, Tag, Upload, Users } from "lucide-vue-next";
import AppIconButton from "@/shared/components/action/AppIconButton.vue";
import AppSearchInput from "@/shared/components/form/input/AppSearchInput.vue";
import AppModulePanel from "@/shared/nav/AppModulePanel.vue";
import { useDebounce } from "@/shared/composables/useDebounce.js";
import { useRequest } from "@/shared/composables/http/backend/useRequest.js";
import { HttpMethod } from "@/shared/utils/http/httpMethod.js";
import { askPage, onPageNotice } from "@/shared/nav/modulePanelBridge.js";
import { useModulePanelData } from "@/shared/nav/useModulePanelData.js";
import { folderIdsIn, useNoteTree } from "./composables/useNoteTree.js";
import { peekNoteDrag, readNoteDrag, startNoteDrag } from "./composables/noteDrag.js";
import { dropZone, planDrop } from "./composables/noteDropPlan.js";
import NoteTreeItem from "./components/NoteTreeItem.vue";

const FOLDERS_ENDPOINT = "/backend/notes/markdown/folders";
const NOTES_ENDPOINT = "/backend/notes/markdown/list";
const SEARCH_ENDPOINT = "/backend/notes/markdown/search";
const SHARED_ENDPOINT = "/backend/notes/markdown/shared";
const LIBRARY_URL = "/backend/notes/markdown";
const EXPANDED_KEY = "aurora.notes.panel.expanded";
const PINNED_TAGS_KEY = "aurora.notes.panel.pinnedTags";
const TAGS_OPEN_KEY = "aurora.notes.panel.tagsOpen";

/** Combien d'étiquettes non épinglées on montre avant de replier. */
const TAGS_SHOWN = 8;

const { t } = useI18n();

const {
    data: fetchedFolders,
    loading,
    failed,
} = useModulePanelData(FOLDERS_ENDPOINT, { key: "folders" });

const { data: fetchedNotes } = useModulePanelData(NOTES_ENDPOINT, {
    key: "notes",
});

/**
 * Ce que la page annonce l'emporte sur ce que le panneau a cherché.
 *
 * On charge à l'arrivée parce que le panneau peut être rendu avant que la
 * page soit montée ; ensuite la page annonce chaque changement, ce qui fait
 * apparaître ici un dossier créé là-bas sans recharger.
 */
const announcedFolders = ref(null);
const announcedNotes = ref(null);

const folders = computed(() => announcedFolders.value ?? fetchedFolders.value);
const notes = computed(() => announcedNotes.value ?? fetchedNotes.value);

const selectedKey = ref(null);
const treeQuery = ref("");

const searching = computed(() => "" !== treeQuery.value.trim());

/**
 * Le texte des notes, cherché côté serveur.
 *
 * Les corps ne sont pas dans le navigateur, et ils sont chiffrés en base :
 * c'est l'endpoint `/search` qui déchiffre les notes de la personne et rend
 * les identifiants qui correspondent. Sans lui, chercher « facture » ne
 * trouverait que les notes qui ont ce mot dans leur titre, ce qui est
 * rarement là où on l'a écrit.
 */
const { request } = useRequest();
const contentMatchIds = ref(new Set());

const runContentSearch = useDebounce(async (query) => {
    const payload = await request(
        `${SEARCH_ENDPOINT}?q=${encodeURIComponent(query)}`,
        null,
        { method: HttpMethod.Get, noGuard: true },
    );

    contentMatchIds.value = new Set((payload?.ids ?? []).map((id) => Number(id)));
}, 300);

watch(treeQuery, (value) => {
    const trimmed = value.trim();

    if ("" === trimmed) {
        contentMatchIds.value = new Set();

        return;
    }

    runContentSearch(trimmed);
});

const { tree } = useNoteTree(folders, treeQuery, notes, contentMatchIds);

const isEmpty = computed(() => 0 === folders.value.length && 0 === notes.value.length);

// ── Plier, déplier ─────────────────────────────────────────────────

const openedIds = ref(readStoredExpanded());

/**
 * Ce qui est ouvert à l'écran : ce que la personne a déplié, et pendant une
 * recherche, tout ce que l'arbre filtré contient. Une recherche qui laisse
 * les branches fermées ne montre rien, puisque ce qu'elle a trouvé est
 * justement replié.
 */
const expanded = computed(() =>
    searching.value ? folderIdsIn(tree.value) : openedIds.value,
);

/**
 * Déplier ce qu'il faut pour qu'une note soit visible.
 *
 * **Le commentaire d'à côté promettait déjà que « son dossier s'ouvre », et
 * le code ne faisait que l'allumer.** On le voyait en créant une note dans un
 * dossier replié : la note était bien créée et bien sélectionnée, mais elle
 * restait cachée derrière une flèche fermée, et rien ne disait qu'il s'était
 * passé quelque chose.
 *
 * Toute la chaîne, pas seulement le dossier direct : une note rangée à trois
 * niveaux reste invisible si l'on n'ouvre que le dernier. La boucle se garde
 * des cycles en comptant ses tours, parce qu'un parent qui se pointerait
 * lui-même ferait tourner la page sans rien afficher.
 */
function revealNote(noteId) {
    const note = notes.value.find((n) => Number(n.id) === Number(noteId));

    if (!note?.folderId) return;

    const parents = new Map(
        folders.value.map((f) => [Number(f.id), Number(f.parentId) || null]),
    );

    const next = new Set(openedIds.value);
    let id = Number(note.folderId);

    for (let garde = 0; null !== id && garde <= parents.size; garde += 1) {
        if (next.has(id)) break;

        next.add(id);
        id = parents.get(id) ?? null;
    }

    openedIds.value = next;
    storeExpanded(next);
}

function toggle(node) {
    const id = Number(node.id);
    const next = new Set(openedIds.value);

    if (next.has(id)) {
        next.delete(id);
    } else {
        next.add(id);
    }

    openedIds.value = next;
    storeExpanded(next);
}

/** Déplier sans jamais replier : ce que fait un clic sur un dossier. */
function open(id) {
    if (null === id || openedIds.value.has(Number(id))) return;

    const next = new Set(openedIds.value);
    next.add(Number(id));
    openedIds.value = next;
    storeExpanded(next);
}

/**
 * Tout replier d'un geste, comme Obsidian.
 *
 * Un carnet qu'on a parcouru finit déplié partout, et replier dossier par
 * dossier est exactement le genre de ménage qu'on ne fait jamais.
 */
function collapseAll() {
    openedIds.value = new Set();
    storeExpanded(openedIds.value);
}

const anyOpen = computed(() => !searching.value && openedIds.value.size > 0);

function readStoredExpanded() {
    try {
        const raw = window.localStorage.getItem(EXPANDED_KEY);
        const ids = JSON.parse(raw ?? "[]");

        return new Set(Array.isArray(ids) ? ids.map(Number) : []);
    } catch {
        // Stockage indisponible ou contenu abîmé : l'arbre s'ouvre fermé,
        // ce qui est un défaut d'agrément, pas une panne.
        return new Set();
    }
}

function storeExpanded(ids) {
    try {
        window.localStorage.setItem(EXPANDED_KEY, JSON.stringify([...ids]));
    } catch {
        // Idem : une préférence de lecture, pas un état du carnet.
    }
}

// ── Les favoris ────────────────────────────────────────────────────

/** Un dossier est une page, une note aussi : chacun offre son adresse. */
const hrefFor = (node) =>
    "folder" === node.kind
        ? `${LIBRARY_URL}/folder/${node.id}`
        : `${LIBRARY_URL}/${node.id}`;

/**
 * Passer en lecture, d'un clic, depuis n'importe où dans le module.
 *
 * Il fallait ouvrir une note puis chercher « Lire » dans ses trois points :
 * deux gestes et un menu pour changer de façon d'être dans son carnet. On
 * lit la note ouverte, ou la première du carnet quand rien ne l'est.
 */
function firstNoteIn(nodes) {
    for (const node of nodes) {
        if ("note" === node.kind) return node;

        const found = firstNoteIn(node.children ?? []);
        if (found) return found;
    }

    return null;
}

const readTargetId = computed(() => {
    if (selectedKey.value?.startsWith("note:")) return Number(selectedKey.value.slice(5));

    return firstNoteIn(tree.value)?.id ?? null;
});

function openReader() {
    if (null !== readTargetId.value) window.location.assign(`${LIBRARY_URL}/${readTargetId.value}/read`);
}

/**
 * Ce qui est épinglé, dossiers puis notes, le plus récent d'abord.
 *
 * Craft ouvre son menu là-dessus, et c'est le seul endroit du module d'où
 * l'on atteint une note en un clic sans savoir où elle est rangée. Caché
 * pendant une recherche : la liste des résultats répond déjà à la question
 * posée.
 */
const favorites = computed(() => {
    if (searching.value) return [];

    const pinned = (items, kind) =>
        items
            .filter((one) => Boolean(one.favoritedAt))
            .map((item) => ({ ...item, kind, key: `${kind}:${item.id}` }));

    return [
        ...pinned(folders.value, "folder"),
        ...pinned(notes.value, "note"),
    ].sort((a, b) => Date.parse(b.favoritedAt) - Date.parse(a.favoritedAt));
});

// ── Ce que les autres ont partagé ──────────────────────────────────

/**
 * Le carnet des autres, en lecture.
 *
 * **Jamais mêlé au sien.** Ce qui n'appartient pas à la personne ne se
 * range pas dans son arborescence : les mélanger ferait croire qu'on peut
 * déplacer le dossier d'un collègue, et un glisser qui échoue au bout de
 * trois secondes vaut moins qu'une section qui dit ce qu'elle est.
 *
 * Une note partagée mène à la vue de lecture, pas à l'éditeur : elle n'est
 * pas à écrire, et lui ouvrir l'éditeur promettrait le contraire.
 */
const shared = ref({ folders: [], notes: [] });

onMounted(async () => {
    const payload = await request(SHARED_ENDPOINT, null, {
        method: HttpMethod.Get,
        noGuard: true,
    });

    if (payload) {
        shared.value = {
            folders: payload.folders ?? [],
            notes: payload.notes ?? [],
        };
    }
});

/**
 * Les racines du partage, avec ce qu'elles portent.
 *
 * Le serveur rend les dossiers partagés **et** leurs descendants, parce
 * qu'il faut pouvoir descendre ; ici on ne montre que les racines, chacune
 * suivie de ses notes, pour que la section tienne dans un panneau.
 */
const sharedGroups = computed(() => {
    if (searching.value) return [];

    const byId = new Map(shared.value.folders.map((one) => [Number(one.id), one]));
    const racines = shared.value.folders.filter(
        (one) => !byId.has(Number(one.parentId)),
    );

    /**
     * La racine partagée d'un dossier, en remontant ses parents.
     *
     * Les notes d'un sous-dossier n'apparaissaient nulle part : le serveur
     * les rend lisibles - partager un dossier ouvre tout ce qu'il contient,
     * à n'importe quelle profondeur - mais le panneau ne montrait que celles
     * posées à la racine. On les rattache à leur racine, avec le nom de leur
     * sous-dossier pour qu'on sache d'où elles viennent.
     */
    const rootOf = (folderId) => {
        let current = byId.get(Number(folderId));

        for (let guard = 0; current && guard <= byId.size; guard += 1) {
            if (!byId.has(Number(current.parentId))) return Number(current.id);

            current = byId.get(Number(current.parentId));
        }

        return null;
    };

    const parRacine = new Map();
    const seules = [];

    for (const note of shared.value.notes) {
        const racine = null == note.folderId ? null : rootOf(note.folderId);

        if (null === racine) {
            seules.push(note);

            continue;
        }

        const sub = Number(note.folderId) === racine ? null : byId.get(Number(note.folderId))?.name ?? null;

        if (!parRacine.has(racine)) parRacine.set(racine, []);

        parRacine.get(racine).push({ ...note, subfolder: sub });
    }

    const groupes = racines.map((dossier) => ({
        key: `folder:${dossier.id}`,
        name: dossier.name,
        owner: dossier.ownerName,
        notes: parRacine.get(Number(dossier.id)) ?? [],
    }));

    // Les notes partagées seules : celles dont le dossier n'est pas
    // lui-même partagé.
    return seules.length
        ? [...groupes, { key: "loose", name: null, owner: null, notes: seules }]
        : groupes;
});

const hasShared = computed(() => sharedGroups.value.length > 0);

// ── Les étiquettes ─────────────────────────────────────────────────

/**
 * Les étiquettes du carnet, les épinglées d'abord.
 *
 * **L'épinglage vit dans le navigateur, pas en base.** Une étiquette n'est
 * pas une ligne dans Aurora : c'est une chaîne dans le tableau `tags` d'une
 * note, sans identité propre. Lui donner une table serait la première dont
 * les lignes ne désignent rien, et l'écran d'administration des étiquettes
 * - qui renomme, fusionne et supprime - devrait la tenir à jour en trois
 * endroits de plus. Ici, une étiquette disparue disparaît d'elle-même de la
 * liste, puisqu'on n'affiche que celles qui existent encore.
 *
 * Les favoris, eux, sont en base : ils s'accrochent à une note ou à un
 * dossier, c'est-à-dire à quelque chose qui a une ligne.
 */
const pinnedTags = ref(readStoredTags());
const tagsOpen = ref("1" === readStored(TAGS_OPEN_KEY, "1"));
const showAllTags = ref(false);

function readStored(key, fallback) {
    try {
        return window.localStorage.getItem(key) ?? fallback;
    } catch {
        // Navigation privée, cadre restreint : la préférence est un
        // confort, pas un état du carnet.
        return fallback;
    }
}

function readStoredTags() {
    try {
        const raw = JSON.parse(window.localStorage.getItem(PINNED_TAGS_KEY) ?? "[]");

        return Array.isArray(raw) ? raw.filter((one) => "string" === typeof one) : [];
    } catch {
        return [];
    }
}

function store(key, value) {
    try {
        window.localStorage.setItem(key, value);
    } catch {
        // Idem : rien à rattraper, la session continue sans mémoire.
    }
}

/** Toutes les étiquettes portées par au moins une note, par fréquence. */
const allTags = computed(() => {
    const counts = new Map();

    for (const note of notes.value) {
        for (const one of note.tags ?? []) {
            if ("string" !== typeof one || "" === one.trim()) continue;

            counts.set(one, (counts.get(one) ?? 0) + 1);
        }
    }

    return [...counts.entries()]
        .map(([name, count]) => ({ name, count }))
        .sort(
            (a, b) =>
                b.count - a.count ||
                a.name.localeCompare(b.name, undefined, { sensitivity: "base" }),
        );
});

/**
 * Ce qu'on affiche : les épinglées, puis les plus portées.
 *
 * Une étiquette épinglée qui n'existe plus - renommée, fusionnée, effacée
 * depuis l'écran des étiquettes - tombe d'elle-même, puisque la liste part
 * de ce que les notes portent réellement.
 */
const visibleTags = computed(() => {
    if (searching.value) return [];

    const pinned = pinnedTags.value;
    const marked = allTags.value.map((one) => ({
        ...one,
        pinned: pinned.includes(one.name),
    }));

    const first = marked.filter((one) => one.pinned);
    const rest = marked.filter((one) => !one.pinned);

    return [...first, ...(showAllTags.value ? rest : rest.slice(0, TAGS_SHOWN))];
});

const hiddenTagCount = computed(() =>
    showAllTags.value
        ? 0
        : Math.max(0, allTags.value.length - pinnedTags.value.length - TAGS_SHOWN),
);

function togglePinned(name) {
    pinnedTags.value = pinnedTags.value.includes(name)
        ? pinnedTags.value.filter((one) => one !== name)
        : [...pinnedTags.value, name];

    store(PINNED_TAGS_KEY, JSON.stringify(pinnedTags.value));
}

function toggleTagsSection() {
    tagsOpen.value = !tagsOpen.value;
    store(TAGS_OPEN_KEY, tagsOpen.value ? "1" : "0");
}

function labelOf(node) {
    if ("folder" === node.kind) {
        return node.name || t("notes.markdown.folders.untitled");
    }

    return node.title || t("notes.markdown.untitled");
}

// ── Ce que le panneau demande à la page ────────────────────────────

/**
 * Ce qu'on tient, et où ça tomberait.
 *
 * **Le panneau range lui-même.** Il transmettait le dépôt à la
 * bibliothèque, qui n'existe pas quand une note est ouverte : glisser une
 * note sur un dossier depuis l'éditeur ne faisait rien, sans un mot. Il
 * calcule maintenant le résultat - quel dossier, quel rang - et le confie à
 * la page sous forme de données simples, qu'elle écrit quel que soit l'écran
 * affiché.
 */
const draggingKey = ref(null);
const dropHint = ref(null);

/** Le dossier survolé qui s'ouvrira si l'on attend dessus. */
let hoverTimer = null;
let hoverKey = null;

/** Le temps de survol qui déplie un dossier fermé, comme dans le Finder. */
const HOVER_OPEN_MS = 600;

function clearHover() {
    if (hoverTimer) clearTimeout(hoverTimer);
    hoverTimer = null;
    hoverKey = null;
}

function forward(name, ...args) {
    return askPage(`notes:${name}`, { args });
}

/**
 * Un clic ouvre : un dossier dans la bibliothèque, une note dans l'éditeur.
 *
 * La page répond aux deux ; si personne n'écoute, le lien de la ligne a déjà
 * l'adresse et le navigateur y va.
 */
function onSelect(node) {
    selectedKey.value = node.key;

    if ("folder" === node.kind) {
        // La racine n'a pas d'identifiant, et `Number(null)` vaut zéro :
        // le panneau demandait donc le dossier 0, que la bibliothèque
        // affichait vide et dont l'adresse rendait un 404.
        // Personne à l'écoute : le lecteur est ailleurs dans le module, et la
        // ligne, qui a annulé son lien pour laisser la page faire, navigue.
        if (!forward("open-folder", null === node.id ? null : Number(node.id))) {
            window.location.assign(null === node.id ? LIBRARY_URL : hrefFor(node));
        }

        // Un dossier qu'on ouvre se déplie aussi : on vient voir ce qu'il
        // contient, et la flèche n'était qu'un détour de plus.
        open(node.id);

        return;
    }

    if (!forward("select", Number(node.id))) window.location.assign(hrefFor(node));
}

function onFavoriteClick(entry, event) {
    event.preventDefault();
    onSelect(entry);
}

/** Ce qu'un dépôt sur cette ligne, à cette hauteur, écrirait. */
function planFor(node, event, dragged) {
    const zone = null === node.id
        ? "inside"
        : dropZone(event.currentTarget.getBoundingClientRect(), event.clientY, node.kind);

    const plan = planDrop({
        dragged,
        target: { kind: node.kind, id: node.id },
        zone,
        folders: folders.value,
        notes: notes.value,
    });

    return { zone, plan };
}

/**
 * Le glisser part d'ici, donc le presse-papier se remplit ici.
 *
 * La page ne peut pas le faire à notre place : elle reçoit l'événement une
 * fois le glisser commencé, et `setData` n'a plus d'effet à ce moment.
 */
function onDragStart(node, event) {
    draggingKey.value = node.key;
    startNoteDrag(event, node.kind, node.id);
}

function onDragEnd() {
    draggingKey.value = null;
    dropHint.value = null;
    clearHover();
}

function onDragOver(node, event) {
    const dragged = peekNoteDrag(event);
    if (!dragged) return;

    event.stopPropagation();

    const { zone, plan } = planFor(node, event, dragged);

    // Un dépôt impossible - un dossier dans son propre enfant, une ligne sur
    // elle-même - n'allume rien et montre le curseur d'interdiction : mieux
    // vaut le savoir avant de lâcher qu'après.
    if (!plan) {
        if (event.dataTransfer) event.dataTransfer.dropEffect = "none";
        dropHint.value = null;
        clearHover();

        return;
    }

    event.preventDefault();
    if (event.dataTransfer) event.dataTransfer.dropEffect = "move";

    dropHint.value = { key: node.key, zone };

    // Attendre sur un dossier fermé l'ouvre : on descend dans l'arbre sans
    // lâcher ce qu'on tient.
    const closed = "folder" === node.kind && null !== node.id && !expanded.value.has(Number(node.id));

    if ("inside" === zone && closed && (node.children ?? []).length) {
        if (hoverKey !== node.key) {
            clearHover();
            hoverKey = node.key;
            hoverTimer = setTimeout(() => {
                open(node.id);
                clearHover();
            }, HOVER_OPEN_MS);
        }
    } else {
        clearHover();
    }
}

function onDragLeave(node, event) {
    const related = event.relatedTarget;
    if (related && event.currentTarget.contains(related)) return;
    if (dropHint.value?.key === node.key) dropHint.value = null;
    if (hoverKey === node.key) clearHover();
}

function onDrop(node, event) {
    const dragged = readNoteDrag(event) ?? peekNoteDrag(event);

    event.preventDefault();
    event.stopPropagation();

    const { plan } = dragged ? planFor(node, event, dragged) : { plan: null };

    draggingKey.value = null;
    dropHint.value = null;
    clearHover();

    if (plan) forward("move", plan);
}

/** La racine est une cible comme une autre : on y remonte ce qu'on lâche. */
const rootNode = { kind: "folder", id: null, key: "root" };

// ── Le clavier ─────────────────────────────────────────────────────

/**
 * Parcourir l'arbre sans la souris, comme dans un explorateur.
 *
 * Haut et bas passent d'une ligne visible à l'autre, droite déplie puis
 * descend, gauche replie puis remonte au dossier parent, Entrée ouvre, F2
 * renomme. Une seule ligne à la fois porte le focus : l'arbre compte pour
 * une tabulation, pas pour cent.
 */
const treeRef = ref(null);

function rows() {
    return [...(treeRef.value?.querySelectorAll("[data-tree-row]") ?? [])];
}

function nodeByKey(key, list = tree.value) {
    for (const node of list) {
        if (node.key === key) return node;

        const found = nodeByKey(key, node.children ?? []);
        if (found) return found;
    }

    return null;
}

function focusRow(element) {
    element?.focus();
    element?.scrollIntoView?.({ block: "nearest" });
}

function onTreeFocus(event) {
    if (event.target !== treeRef.value) return;

    const all = rows();
    const selected = all.find((row) => row.dataset.treeKey === selectedKey.value);

    focusRow(selected ?? all[0]);
}

function onTreeKeydown(event) {
    const current = event.target.closest?.("[data-tree-row]");
    if (!current) return;

    const all = rows();
    const index = all.indexOf(current);
    const node = nodeByKey(current.dataset.treeKey);

    if (!node) return;

    const isFolder = "folder" === node.kind;
    const isOpen = isFolder && expanded.value.has(Number(node.id));
    const hasChildren = (node.children ?? []).length > 0;

    const handled = {
        ArrowDown: () => focusRow(all[index + 1]),
        ArrowUp: () => focusRow(all[index - 1]),
        Home: () => focusRow(all[0]),
        End: () => focusRow(all[all.length - 1]),
        ArrowRight: () => {
            if (isFolder && hasChildren && !isOpen) toggle(node);
            else if (isOpen) focusRow(all[index + 1]);
        },
        ArrowLeft: () => {
            if (isOpen && !searching.value) {
                toggle(node);

                return;
            }

            const parent = all.find((row) => row.dataset.treeKey === current.dataset.parentKey);
            focusRow(parent);
        },
        Enter: () => onSelect(node),
        F2: () => forward(isFolder ? "rename-folder" : "rename-note", node),
    }[event.key];

    if (!handled) return;

    event.preventDefault();
    handled();
}

const stopListening = [];

onMounted(() => {
    stopListening.push(
        onPageNotice("notes:changed", (detail) => {
            if (Array.isArray(detail?.notes)) announcedNotes.value = detail.notes;
            if (Array.isArray(detail?.folders)) {
                announcedFolders.value = detail.folders;
            }

            // La page dit ce qu'elle montre : un dossier, une note, ou la
            // racine. La ligne correspondante s'allume, et son dossier
            // s'ouvre pour qu'elle soit visible.
            if (detail?.noteId) {
                selectedKey.value = `note:${detail.noteId}`;
                revealNote(detail.noteId);

                return;
            }

            if ("folderId" in (detail ?? {})) {
                selectedKey.value = detail.folderId
                    ? `folder:${detail.folderId}`
                    : null;
            }
        }),
    );
});

onUnmounted(() => {
    while (stopListening.length) stopListening.pop()();
});
</script>

<template>
    <AppModulePanel
        :title="t('notes.markdown.title')"
        :loading="loading"
        :failed="failed"
    >
        <template #action>
            <!-- Lire le carnet, d'un clic : l'espace de lecture, épuré. -->
            <AppIconButton
                size="sm"
                data-read-mode-toggle
                :title="t('notes.markdown.read.mode')"
                :disabled="null === readTargetId"
                v-on:click="openReader"
            >
                <BookOpen class="h-3.5 w-3.5" :stroke-width="2" />
            </AppIconButton>
            <AppIconButton
                v-if="anyOpen"
                size="sm"
                :title="t('notes.markdown.collapse_all')"
                v-on:click="collapseAll"
            >
                <ChevronsDownUp class="h-3.5 w-3.5" :stroke-width="2" />
            </AppIconButton>
            <AppIconButton
                size="sm"
                :title="t('notes.markdown.import.button')"
                v-on:click="forward('import')"
            >
                <Upload class="h-3.5 w-3.5" :stroke-width="2" />
            </AppIconButton>
            <AppIconButton
                size="sm"
                :title="t('notes.markdown.export.all')"
                v-on:click="forward('export')"
            >
                <Download class="h-3.5 w-3.5" :stroke-width="2" />
            </AppIconButton>
            <!-- Un seul plus, qui demande quoi : une note ou un dossier.
                     Deux boutons côte à côte obligeaient à deviner lequel était
                     lequel à la seule forme de leur icône. -->
            <AppIconButton
                size="sm"
                :title="t('notes.markdown.add.title')"
                v-on:click="forward('add', null)"
            >
                <Plus class="h-3.5 w-3.5" :stroke-width="2" />
            </AppIconButton>
        </template>

        <!-- Pas de retrait horizontal : les lignes de l'arborescence portent
             le leur à l'intérieur et occupent toute la largeur du panneau. -->
        <div class="pb-1">
            <AppSearchInput
                v-model="treeQuery"
                :placeholder="t('notes.markdown.search_placeholder')"
            />
        </div>

        <!-- Les favoris, avant l'arborescence : ce qu'on vient chercher tous
             les jours n'a pas à se retrouver dans un arbre. -->
        <div v-if="favorites.length" class="mb-2 border-b border-line pb-2">
            <p class="px-3 py-1 text-xs font-semibold uppercase tracking-wide text-muted">
                {{ t('notes.markdown.library.favorites') }}
            </p>

            <a
                v-for="entry in favorites"
                :key="entry.key"
                :data-favorite-row="entry.key"
                :href="hrefFor(entry)"
                class="flex min-w-0 items-center gap-2 rounded-lg px-3 py-2 text-sm text-primary no-underline transition-colors hover:bg-surface-2"
                v-on:click="onFavoriteClick(entry, $event)"
            >
                <component
                    :is="'folder' === entry.kind ? Folder : FileText"
                    class="h-4 w-4 shrink-0 text-muted"
                    :stroke-width="2"
                />
                <span class="min-w-0 flex-1 truncate">{{ labelOf(entry) }}</span>
            </a>
        </div>

        <!-- La racine est une ligne comme les autres : c'est là qu'on
             retombe, et une arborescence sans son sommet oblige à deviner
             comment y revenir. -->
        <a
            :href="LIBRARY_URL"
            data-root-row
            class="group mb-0.5 flex min-w-0 items-center gap-2 rounded-md border px-2 py-1.5 text-sm no-underline transition-colors"
            :class="'root' === dropHint?.key
                ? 'border-accent-600/40 bg-accent-600/15 text-accent-400 ring-2 ring-accent-500'
                : null === selectedKey ? 'border-accent-600/30 bg-accent-600/15 text-accent-400' : 'border-transparent text-primary hover:bg-surface-2'"
            v-on:click.prevent="onSelect({ kind: 'folder', id: null, key: null })"
            v-on:dragover="onDragOver(rootNode, $event)"
            v-on:dragleave="onDragLeave(rootNode, $event)"
            v-on:drop="onDrop(rootNode, $event)"
        >
            <FileText class="h-4 w-4 shrink-0" :stroke-width="2" />
            <span class="flex-1 truncate">{{ t('notes.markdown.library.title') }}</span>
        </a>

        <p v-if="isEmpty && !searching" class="px-3 py-1 text-xs text-muted">
            {{ t("notes.markdown.folders.tree_empty") }}
        </p>

        <p v-else-if="searching && !tree.length" class="px-3 py-1 text-xs text-muted">
            {{ t("notes.markdown.search_no_results") }}
        </p>

        <!-- L'arbre compte pour une seule tabulation : on y entre, puis les
             flèches font le reste. -->
        <div
            ref="treeRef"
            role="tree"
            tabindex="0"
            class="space-y-0.5 outline-none"
            :aria-label="t('notes.markdown.title')"
            v-on:focus="onTreeFocus"
            v-on:keydown="onTreeKeydown"
        >
            <NoteTreeItem
                v-for="node in tree"
                :key="node.key"
                :node="node"
                :selected-key="selectedKey"
                :expanded="expanded"
                :draggable="true"
                :dragging-key="draggingKey"
                :drop-hint="dropHint"
                :href-for="hrefFor"
                v-on:select="onSelect"
                v-on:toggle="toggle"
                v-on:add="(node) => { open(node.id); forward('add', Number(node.id)); }"
                v-on:rename="(node) => forward('folder' === node.kind ? 'rename-folder' : 'rename-note', node)"
                v-on:delete="(node) => forward('folder' === node.kind ? 'delete-folder' : 'delete', node)"
                v-on:drag-start="onDragStart"
                v-on:drag-end="onDragEnd"
                v-on:drag-over="onDragOver"
                v-on:drag-leave="onDragLeave"
                v-on:drop="onDrop"
            />
        </div>
        <!-- Le carnet des autres, en lecture, et à part. Ce qui n'est
             pas à soi ne se range pas dans son arborescence. -->
        <div v-if="hasShared" class="mt-2 border-t border-line pt-2">
            <p class="px-3 py-1 text-xs font-semibold uppercase tracking-wide text-muted">
                {{ t('notes.markdown.library.shared.section') }}
            </p>

            <div v-for="groupe in sharedGroups" :key="groupe.key" class="mb-1">
                <p
                    v-if="groupe.name"
                    class="flex min-w-0 items-center gap-2 px-3 py-1 text-sm text-secondary"
                    :title="groupe.owner ? t('notes.markdown.library.shared.by', { name: groupe.owner }) : undefined"
                >
                    <Users class="h-3.5 w-3.5 shrink-0 text-muted" :stroke-width="2" />
                    <span class="min-w-0 flex-1 truncate">{{ groupe.name }}</span>
                </p>

                <a
                    v-for="note in groupe.notes"
                    :key="`shared-${note.id}`"
                    :href="`${LIBRARY_URL}/${note.id}/read`"
                    class="flex min-w-0 items-center gap-2 rounded-lg py-1.5 pl-6 pr-3 text-sm text-primary no-underline transition-colors hover:bg-surface-2"
                    :title="note.ownerName ? t('notes.markdown.library.shared.by', { name: note.ownerName }) : undefined"
                >
                    <FileText class="h-4 w-4 shrink-0 text-muted" :stroke-width="2" />
                    <span class="min-w-0 flex-1 truncate">
                        <span v-if="note.subfolder" class="text-muted">{{ note.subfolder }} › </span>{{ note.title || t('notes.markdown.untitled') }}
                    </span>
                </a>
            </div>
        </div>

        <!-- Les étiquettes, sous l'arborescence : elles traversent le
             rangement, donc elles ne peuvent pas y tenir une place. Cliquer
             l'une d'elles montre ses notes, où qu'elles soient. -->
        <div v-if="allTags.length && !searching" class="mt-2 border-t border-line pt-2">
            <button
                type="button"
                class="flex w-full items-center gap-1 px-3 py-1 text-xs font-semibold uppercase tracking-wide text-muted transition-colors hover:text-primary"
                v-on:click="toggleTagsSection"
            >
                <ChevronDown v-if="tagsOpen" class="h-3 w-3 shrink-0" :stroke-width="2" />
                <ChevronRight v-else class="h-3 w-3 shrink-0" :stroke-width="2" />
                {{ t('notes.markdown.library.tag.section') }}
            </button>

            <template v-if="tagsOpen">
                <div
                    v-for="one in visibleTags"
                    :key="one.name"
                    class="group flex min-w-0 items-center gap-2 rounded-lg px-3 py-1.5 text-sm text-primary transition-colors hover:bg-surface-2"
                >
                    <button
                        type="button"
                        class="flex min-w-0 flex-1 items-center gap-2 text-left"
                        :title="t('notes.markdown.library.tag.filter', { tag: one.name })"
                        v-on:click="forward('filter-tag', one.name)"
                    >
                        <Tag class="h-3.5 w-3.5 shrink-0 text-muted" :stroke-width="2" />
                        <span class="min-w-0 flex-1 truncate">{{ one.name }}</span>
                        <span class="shrink-0 text-xs text-muted tabular-nums">{{ one.count }}</span>
                    </button>

                    <AppIconButton
                        size="sm"
                        class="shrink-0 sm:opacity-0 sm:group-hover:opacity-100"
                        :class="one.pinned ? 'sm:opacity-100' : ''"
                        :title="one.pinned ? t('notes.markdown.library.tag.unpin') : t('notes.markdown.library.tag.pin')"
                        v-on:click.stop="togglePinned(one.name)"
                    >
                        <PinOff v-if="one.pinned" class="h-3 w-3" :stroke-width="2" />
                        <Pin v-else class="h-3 w-3" :stroke-width="2" />
                    </AppIconButton>
                </div>

                <button
                    v-if="hiddenTagCount"
                    type="button"
                    class="px-3 py-1 text-xs text-muted transition-colors hover:text-primary"
                    v-on:click="showAllTags = true"
                >
                    {{ t('notes.markdown.library.tag.show_all', { count: hiddenTagCount }) }}
                </button>
            </template>
        </div>
    </AppModulePanel>
</template>
