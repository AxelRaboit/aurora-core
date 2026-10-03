<script setup>
/**
 * Une ligne de l'arborescence : un dossier, ou une note rangée dedans.
 *
 * Les deux se ressemblent et ne font pas la même chose. Un dossier se
 * déplie, se remplit, se supprime avec son contenu ; une note s'ouvre, et
 * c'est tout ce qu'elle fait ici - la lire, la renommer, la jeter, cela se
 * passe dans l'éditeur ou dans la bibliothèque.
 *
 * **Le dépliage est tenu par le panneau, pas par la ligne.** Un état local
 * repartirait fermé à chaque rendu de l'arbre, c'est-à-dire à chaque note
 * créée ailleurs, et une recherche ne pourrait pas ouvrir les branches où
 * elle a trouvé quelque chose.
 *
 * **Le dépôt se lit sur la ligne.** Un trait au-dessus ou au-dessous dit
 * « avant » ou « après », un cadre dit « dedans » : c'est ce que montrent
 * Craft, Notion et Obsidian, et c'est ce qui manquait pour ranger à un rang
 * précis plutôt qu'en vrac au fond d'un dossier.
 */
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { ChevronRight, ChevronDown, FileText, Folder, FolderOpen, LayoutTemplate, Pencil, Plus, Star, StarOff, Trash2 } from 'lucide-vue-next';
import AppIconButton from '@shared/components/action/AppIconButton.vue';
import AppRowActions from '@shared/components/action/AppRowActions.vue';

const props = defineProps({
    node: { type: Object, required: true },
    /** Le dossier ouvert dans la bibliothèque, ou la note ouverte. */
    selectedKey: { type: String, default: null },
    /** Les identifiants des dossiers dépliés, tenus par le panneau. */
    expanded: { type: Set, default: () => new Set() },
    draggable: { type: Boolean, default: false },
    /** Le mode lecture : la ligne mène, elle ne propose rien d'autre. */
    readonly: { type: Boolean, default: false },
    /**
     * Faux dans un espace qu'on lit sans y écrire : la ligne ne propose que
     * ce qui est à soi - les favoris -, ni renommer, ni ranger, ni jeter.
     */
    editable: { type: Boolean, default: true },
    draggingKey: { type: String, default: null },
    /** Où tomberait ce qu'on tient : `{ key, zone }`, zone avant, dedans ou après. */
    dropHint: { type: Object, default: null },
    /** La clé de la ligne qui porte celle-ci, pour remonter au clavier. */
    parentKey: { type: String, default: null },
    depth: { type: Number, default: 0 },
    /**
     * Turns the row into a real link.
     *
     * A folder and a note are both pages, so a row in the side menu has to
     * be middle-clickable and sendable - the whole reason their addresses
     * exist. The click handler still runs and still wins: selecting swaps
     * the listing or the note in place, and the navigation is cancelled.
     */
    hrefFor: { type: Function, default: null },
});

const emit = defineEmits([
    'select',
    'toggle',
    'add',
    'rename',
    'favorite',
    'delete',
    'drag-start',
    'drag-end',
    'drag-over',
    'drag-leave',
    'drop',
]);

const { t } = useI18n();

const isFolder = computed(() => 'folder' === props.node.kind);

/**
 * Ce qu'une ligne propose, dossier comme note.
 *
 * Dans une feuille et non en boutons alignés : la règle de la maison veut
 * qu'au-delà de deux gestes on empile, et une ligne d'arbre est trop étroite
 * pour en aligner trois. Le plus d'un dossier reste dehors, c'est celui qu'on
 * répète ; il est aussi dans la feuille, parce que le clic droit n'ouvre
 * qu'elle.
 */
const favoriteAction = computed(() => ({
    // Les favoris sont à soi : on épingle ce qu'on lit, là où on le voit.
    key: 'favorite',
    title: props.node.favoritedAt ? t('notes.markdown.library.unpin') : t('notes.markdown.library.pin'),
    icon: props.node.favoritedAt ? StarOff : Star,
    onSelect: () => emit('favorite', props.node),
}));

const editActions = computed(() => [
    ...(isFolder.value
        ? [{
            key: 'add',
            title: t('notes.markdown.add.here'),
            icon: Plus,
            onSelect: () => emit('add', props.node),
        }]
        : []),
    {
        key: 'rename',
        title: isFolder.value ? t('notes.markdown.folders.rename') : t('notes.markdown.rename'),
        icon: Pencil,
        onSelect: () => emit('rename', props.node),
    },
    favoriteAction.value,
    {
        key: 'delete',
        title: isFolder.value ? t('notes.markdown.folders.delete') : t('notes.markdown.delete'),
        icon: Trash2,
        color: 'rose',
        onSelect: () => emit('delete', props.node),
    },
]);

const rowActions = computed(() => (props.editable ? editActions.value : [favoriteAction.value]));
const children = computed(() => props.node.children ?? []);
const hasChildren = computed(() => children.value.length > 0);
const isOpen = computed(() => props.expanded.has(Number(props.node.id)));
const isSelected = computed(() => props.selectedKey === props.node.key);
const zone = computed(() => (props.dropHint?.key === props.node.key ? props.dropHint.zone : null));
const isDropInside = computed(() => 'inside' === zone.value);
const isBeingDragged = computed(() => props.draggingKey === props.node.key);

/**
 * La couleur du dossier, quand il en porte une.
 *
 * En style et non en classe : la valeur vient du lecteur, et Tailwind
 * n'écrit que les classes qu'il voit dans le source. Elle passe devant la
 * classe de couleur sauf quand la ligne est choisie ou visée par un
 * glisser : là, c'est l'état qui doit se voir, pas la décoration.
 */
const tint = computed(() =>
    isFolder.value && props.node.color && !isSelected.value && !isDropInside.value
        ? { color: props.node.color }
        : null,
);

const label = computed(() =>
    isFolder.value
        ? props.node.name || undefined
        : props.node.title || undefined,
);

const displayLabel = computed(() =>
    label.value || (isFolder.value ? t('notes.markdown.folders.untitled') : t('notes.markdown.untitled')),
);

/**
 * Selecting is what a click means; the address is for the other gestures.
 * Cancelling the navigation is what keeps the page from reloading under a
 * reader who only meant to switch.
 */
function onRowClick(event) {
    if (props.hrefFor) event.preventDefault();
    emit('select', props.node);
}

// Le clic droit ouvre la même feuille que les trois points : un seul menu,
// deux portes, comme dans n'importe quel explorateur.
const actionsRef = ref(null);

function onContextMenu(event) {
    if (props.readonly) return;

    event.preventDefault();
    actionsRef.value?.open();
}

/**
 * Double-cliquer le nom renomme ; double-cliquer un bouton de la ligne - la
 * flèche qu'on referme et rouvre, le plus, les trois points - ne fait que ce
 * que fait le bouton. Sans cette garde, replier puis déplier vite ouvrait la
 * modale de renommage.
 */
function onDoubleClick(event) {
    if (props.readonly || !props.editable || event.target?.closest?.('button')) return;

    emit('rename', props.node);
}

// Indent applied to the row itself so its right edge stays flush with
// the sidebar (same convention as TermNode / media folder rows).
const indentStyle = computed(() => ({ marginLeft: `${props.depth * 0.875}rem` }));
</script>

<template>
    <div>
        <!-- The row is a div, and only the title inside it is a link.
             Putting the whole row in an `<a>` seemed tidier and was wrong twice
             over: interactive content inside a link is invalid HTML, and the
             action buttons stop the click before the row can cancel the
             navigation - so pressing "new note" followed the href and
             reloaded the page instead. -->
        <div
            :data-folder-row="isFolder ? node.id : undefined"
            :data-note-row="isFolder ? undefined : node.id"
            data-tree-row
            :data-tree-key="node.key"
            :data-parent-key="parentKey ?? undefined"
            :data-drop-zone="zone ?? undefined"
            :aria-expanded="isFolder && hasChildren ? isOpen : undefined"
            :aria-selected="isSelected"
            role="treeitem"
            tabindex="-1"
            class="group relative flex items-center gap-1.5 px-2 py-1 rounded-md border transition-colors min-w-0 text-sm outline-none focus-visible:ring-2 focus-visible:ring-accent-500/60"
            :class="[
                isDropInside
                    ? 'bg-accent-600/15 text-accent-400 border-accent-600/40 ring-2 ring-accent-500'
                    : isSelected
                        ? 'bg-accent-600/15 text-accent-400 border-accent-600/30'
                        : 'hover:bg-surface-2 text-primary border-transparent',
                isBeingDragged ? 'opacity-40' : '',
                draggable ? 'cursor-grab active:cursor-grabbing' : 'cursor-pointer',
            ]"
            :style="indentStyle"
            :draggable="draggable"
            v-on:dragstart="emit('drag-start', node, $event)"
            v-on:dragend="emit('drag-end', $event)"
            v-on:dragover="emit('drag-over', node, $event)"
            v-on:dragleave="emit('drag-leave', node, $event)"
            v-on:drop="emit('drop', node, $event)"
            v-on:contextmenu="onContextMenu"
            v-on:dblclick="onDoubleClick"
        >
            <!-- Le trait d'insertion : on range à ce rang-là, pas au fond. -->
            <span
                v-if="'before' === zone || 'after' === zone"
                aria-hidden="true"
                class="pointer-events-none absolute left-1 right-1 h-0.5 rounded-full bg-accent-500"
                :class="'before' === zone ? '-top-px' : '-bottom-px'"
            />

            <AppIconButton
                v-if="isFolder && hasChildren"
                class="-ml-1 -my-0.5 shrink-0"
                tabindex="-1"
                :title="isOpen ? $t('shared.common.collapse') : $t('shared.common.expand')"
                v-on:click.stop="emit('toggle', node)"
            >
                <ChevronDown v-if="isOpen" class="w-3 h-3" :stroke-width="2" />
                <ChevronRight v-else class="w-3 h-3" :stroke-width="2" />
            </AppIconButton>
            <span v-else class="w-5 shrink-0" />

            <component
                :is="hrefFor ? 'a' : 'span'"
                :href="hrefFor ? hrefFor(node) : undefined"
                tabindex="-1"
                draggable="false"
                class="flex min-w-0 flex-1 items-center gap-2 no-underline"
                v-on:click="onRowClick"
            >
                <component
                    :is="isFolder ? (isOpen && hasChildren ? FolderOpen : Folder) : (node.template ? LayoutTemplate : FileText)"
                    :aria-label="!isFolder && node.template ? t('notes.markdown.template.badge') : undefined"
                    class="w-4 h-4 shrink-0"
                    :class="isSelected || isDropInside ? 'text-accent-400' : 'text-muted'"
                    :style="tint"
                    :stroke-width="2"
                />

                <span class="flex-1 truncate min-w-0">{{ displayLabel }}</span>

                <!-- Ce qu'un dossier contient, dit une fois, et seulement
                     quand il est replié : déplié, la réponse est sous les
                     yeux, et le nombre ne fait plus que du bruit. -->
                <span
                    v-if="isFolder && !isOpen && node.noteCount"
                    class="shrink-0 text-xs text-muted tabular-nums"
                >
                    {{ node.noteCount }}
                </span>
            </component>

            <!-- Per-row extension point. Wrapped so a client decorator
                 sits between the title and the hover action buttons. -->
            <slot name="extra-cells" :node="node" />

            <!-- En marges négatives : les boutons gardent leur zone de clic sans
                 étirer la ligne. Ils la faisaient monter à 42 pixels, une
                 hauteur de formulaire, là où un explorateur tient en 30. -->
            <div v-if="!readonly" class="-my-1.5 sm:opacity-0 sm:group-hover:opacity-100 sm:group-focus-visible:opacity-100 flex items-center gap-0.5 transition-opacity shrink-0">
                <AppIconButton
                    v-if="isFolder && editable"
                    color="accent"
                    tabindex="-1"
                    :title="$t('notes.markdown.create_in_folder')"
                    v-on:click.stop="emit('add', node)"
                >
                    <Plus class="w-3.5 h-3.5" :stroke-width="2" />
                </AppIconButton>
                <AppRowActions
                    ref="actionsRef"
                    :actions="rowActions"
                    :label="displayLabel"
                    size="sm"
                />
            </div>
        </div>

        <!-- Un filet le long des enfants, comme Obsidian : on voit d'un coup
             d'œil ce qui appartient à quel dossier, même trois niveaux plus
             bas. -->
        <div
            v-if="isFolder && hasChildren && isOpen"
            role="group"
            class="relative mt-0.5 space-y-0.5 before:pointer-events-none before:absolute before:top-0 before:bottom-0 before:w-px before:bg-line/70"
            :style="{ '--guide': `${depth * 0.875 + 0.95}rem` }"
            :class="'before:left-[var(--guide)]'"
        >
            <NoteTreeItem
                v-for="child in children"
                :key="child.key"
                :node="child"
                :selected-key="selectedKey"
                :expanded="expanded"
                :draggable="draggable"
                :readonly="readonly"
                :editable="editable"
                :dragging-key="draggingKey"
                :drop-hint="dropHint"
                :parent-key="node.key"
                :depth="depth + 1"
                :href-for="hrefFor"
                v-on:select="(n) => emit('select', n)"
                v-on:toggle="(n) => emit('toggle', n)"
                v-on:add="(n) => emit('add', n)"
                v-on:rename="(n) => emit('rename', n)"
                v-on:favorite="(n) => emit('favorite', n)"
                v-on:delete="(n) => emit('delete', n)"
                v-on:drag-start="(n, e) => emit('drag-start', n, e)"
                v-on:drag-end="(e) => emit('drag-end', e)"
                v-on:drag-over="(n, e) => emit('drag-over', n, e)"
                v-on:drag-leave="(n, e) => emit('drag-leave', n, e)"
                v-on:drop="(n, e) => emit('drop', n, e)"
            >
                <!-- Forward the slot recursively so descendants render the
                     same decoration. Without this template the slot would
                     stop at depth 0. -->
                <template #extra-cells="data">
                    <slot name="extra-cells" v-bind="data" />
                </template>
            </NoteTreeItem>
        </div>
    </div>
</template>
