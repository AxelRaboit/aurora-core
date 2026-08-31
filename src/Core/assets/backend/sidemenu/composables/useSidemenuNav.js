import { ref, computed, onMounted, nextTick } from "vue";
import { useI18n } from "vue-i18n";
import { usePersistedExpanded } from "@/shared/composables/usePersistedExpanded.js";
import { useSidemenuSectionTheme } from "./useSidemenuSectionTheme.js";
import {
    LayoutDashboard,
    FileText,
    Layers,
    Image,
    Images,
    Menu,
    Tags as TagsIcon,
    Users as UsersIcon,
    Building2,
    TrendingUp,
    Shield,
    Settings,
    Palette,
    MessageSquare,
    ClipboardList,
    Package,
    ShoppingBag,
    Map as MapIcon,
    Receipt,
    ScanLine,
    Briefcase,
    ShieldCheck,
    Camera,
    ShoppingCart,
    Gauge,
    FolderKanban,
    FolderOpen,
    Folder,
    CalendarDays,
    KanbanSquare,
    KeyRound,
    Lock,
    Flame,
    StickyNote,
    Wallet,
    PieChart,
    Target,
    Repeat,
    Sparkles,
    Scale,
    Globe2,
    BarChart3,
    Upload,
    ScrollText,
    ClipboardCheck,
} from "lucide-vue-next";

const ICON_MAP = {
    "layout-dashboard": LayoutDashboard,
    "file-text": FileText,
    layers: Layers,
    image: Image,
    images: Images,
    menu: Menu,
    tags: TagsIcon,
    users: UsersIcon,
    "building-2": Building2,
    "trending-up": TrendingUp,
    shield: Shield,
    settings: Settings,
    palette: Palette,
    "message-square": MessageSquare,
    "clipboard-list": ClipboardList,
    package: Package,
    "shopping-bag": ShoppingBag,
    map: MapIcon,
    receipt: Receipt,
    "scan-line": ScanLine,
    briefcase: Briefcase,
    "shield-check": ShieldCheck,
    camera: Camera,
    "shopping-cart": ShoppingCart,
    gauge: Gauge,
    "folder-kanban": FolderKanban,
    "folder-open": FolderOpen,
    folder: Folder,
    "calendar-days": CalendarDays,
    "kanban-square": KanbanSquare,
    "key-round": KeyRound,
    vault: Lock,
    flame: Flame,
    "scroll-text": ScrollText,
    "clipboard-check": ClipboardCheck,
    "sticky-note": StickyNote,
    wallet: Wallet,
    "pie-chart": PieChart,
    target: Target,
    repeat: Repeat,
    sparkles: Sparkles,
    scale: Scale,
    "globe-2": Globe2,
    "bar-chart-3": BarChart3,
    upload: Upload,
};

/**
 * Six positional parameters is one too many, and `moduleNavView` is the one
 * that pushed it over. It was appended rather than folded into an options bag
 * because the existing five have one production call site and a dozen in
 * `useSidemenuAccount.test.js` calling `useSidemenuNav([], "")` - additive
 * costs nothing, a signature change costs all of them. Fold the lot into an
 * options object the next time this list has to grow.
 *
 * @param {?object} moduleNavView Resolved payload from `ModuleNavResolver`, or
 *   null when the menu stays in its project view - which is every page until a
 *   module implements `ModuleNavViewProviderInterface`.
 */
export function useSidemenuNav(
    navSections,
    activeRoute,
    sectionAliases = {},
    itemAliases = {},
    sectionColorOverrides = {},
    moduleNavView = null,
) {
    const { t } = useI18n();
    const { itemClasses: themeItemClasses, iconClasses: themeIconClasses } =
        useSidemenuSectionTheme(sectionColorOverrides);

    const {
        isExpanded: isGroupExpanded,
        toggle: toggleGroup,
        getRaw: getGroupRaw,
    } = usePersistedExpanded("aurora-sidemenu-groups");
    const { isExpanded: isSectionExpandedById, toggle: toggleSectionById } =
        usePersistedExpanded("aurora-sidemenu-sections");

    function isSectionExpanded(section) {
        return isSectionExpandedById(section.id);
    }
    /**
     * The account block at the foot of the menu folds like a nav section, and
     * shares their store: one localStorage key, one rule - expanded unless
     * someone said otherwise. A second mechanism for the same gesture would be
     * a second thing to keep in agreement.
     *
     * Not one of `navSections`, because it is not navigation: its rows are a
     * theme toggle, a mail catcher and a logout form, and nothing generates
     * them.
     */
    const ACCOUNT_SECTION = "account";

    function isAccountExpanded() {
        return isSectionExpandedById(ACCOUNT_SECTION);
    }

    function toggleAccount() {
        toggleSectionById(ACCOUNT_SECTION);
    }

    function toggleSection(section) {
        toggleSectionById(section.id);
    }

    const dashboardPath = computed(
        () => navSections?.[0]?.items?.[0]?.path ?? "/admin",
    );

    function buildItem(item) {
        return {
            route: item.route,
            path: item.path,
            label: itemAliases[item.route]?.trim() || t(item.labelKey),
            description: item.descriptionKey ? t(item.descriptionKey) : "",
            icon: ICON_MAP[item.icon] ?? FileText,
            activeColor: item.activeColor ?? "accent",
            children: (item.children ?? []).map(buildItem),
        };
    }

    const groupedSections = computed(() =>
        navSections.map((section) => ({
            id: section.id,
            label:
                sectionAliases[section.id]?.trim() ||
                t(`backend.nav.sections.${section.id}`),
            foldable: true,
            items: section.items.map(buildItem),
        })),
    );

    /**
     * The open module's own sections, in the exact shape `AppSidemenuNav` draws
     * - one component renders both views, so there is one place where a row is
     * described and one place where it is drawn.
     *
     * Two fields exist only for this view. `themeId` is the module id, so every
     * group borrows the module's section colour rather than declaring a second
     * palette; `id` stays unique per group so two groups do not share one fold
     * state. And `foldable` is false for a group with no header: there would be
     * no control to unfold it with, so it must not start folded.
     */
    const moduleSections = computed(() => {
        if (!moduleNavView) return [];

        return (moduleNavView.groups ?? []).map((group) => ({
            id: `${moduleNavView.moduleId}:${group.id}`,
            themeId: moduleNavView.moduleId,
            label: group.labelKey ? t(group.labelKey) : "",
            foldable: Boolean(group.labelKey),
            items: (group.items ?? []).map(buildItem),
        }));
    });

    const moduleLabel = computed(() => {
        if (!moduleNavView) return "";

        return (
            sectionAliases[moduleNavView.moduleId]?.trim() ||
            t(`backend.nav.sections.${moduleNavView.moduleId}`)
        );
    });

    /**
     * Which of the two views the column is showing.
     *
     * It opens on whatever the server resolved, so a link straight into a
     * module lands on that module's menu. Going back is page state, not a
     * preference: the question "where am I" has a correct answer on every
     * render, and a remembered answer would contradict it on the next
     * navigation.
     */
    const activeView = ref(moduleNavView ? "module" : "project");
    const hasModuleView = computed(() => Boolean(moduleNavView));
    const inModuleView = computed(
        () => hasModuleView.value && "module" === activeView.value,
    );

    function backToProject() {
        activeView.value = "project";
    }

    function enterModuleView() {
        if (hasModuleView.value) activeView.value = "module";
    }

    const activeSections = computed(() =>
        inModuleView.value ? moduleSections.value : groupedSections.value,
    );

    /**
     * What the search palette offers under its "nav" heading.
     *
     * Project entries plus the open module's, which is more than the palette
     * could reach before - a destination declared only at module level was
     * findable nowhere. It is still not every module's: the payload carries one
     * view, the one for the current route. Indexing them all needs the resolver
     * to expose every module's view, not just the matching one.
     */
    const navItems = computed(() => {
        const flatten = (sections) =>
            sections.flatMap((s) =>
                s.items.flatMap((i) => [i, ...(i.children ?? [])]),
            );

        return [
            ...flatten(groupedSections.value),
            ...flatten(moduleSections.value),
        ];
    });

    const navFilter = ref("");

    /**
     * The filter searches the view on screen and nothing else. A field that
     * returned rows the column is not showing would need a sentence to explain
     * itself; the palette is what searches everywhere, and it says so.
     */
    const displayedSections = computed(() => {
        const q = navFilter.value.trim().toLowerCase();
        if (!q) return activeSections.value;
        const results = [];
        for (const section of activeSections.value) {
            const matchingItems = [];
            for (const item of section.items) {
                if (item.label.toLowerCase().includes(q)) {
                    matchingItems.push(item);
                } else if (item.children?.length) {
                    const matchingChildren = item.children.filter((c) =>
                        c.label.toLowerCase().includes(q),
                    );
                    matchingItems.push(...matchingChildren);
                }
            }
            if (matchingItems.length)
                results.push({ ...section, items: matchingItems });
        }
        return results;
    });

    function isActive(route) {
        return activeRoute?.startsWith(route);
    }

    function isActiveExact(route) {
        return activeRoute === route;
    }

    function itemIsActive(item) {
        return (
            isActive(item.route) ||
            (item.children?.some((child) => isActive(child.route)) ?? false)
        );
    }

    /**
     * Wrapper classes for a nav item. Delegates to the section theme
     * registry so the active/hover hue inherits the section colour
     * (e.g. a GED item lights up lime, a configuration item fuchsia).
     * Falls back to the accent palette when the sectionId isn't known.
     */
    function itemClasses(item, sectionId = null) {
        return themeItemClasses(sectionId, {
            isActive: isActive(item.route),
            inTree: itemIsActive(item) && !isActive(item.route),
        });
    }

    function iconClasses(item, sectionId = null) {
        return themeIconClasses(sectionId, {
            isActive: isActive(item.route) || itemIsActive(item),
        });
    }

    onMounted(() => {
        activeSections.value.forEach((section) => {
            section.items.forEach((item) => {
                if (
                    item.children?.length &&
                    getGroupRaw(item.route) === undefined &&
                    item.children.some((c) => isActive(c.route))
                ) {
                    toggleGroup(item.route);
                }
            });
        });
    });

    onMounted(() =>
        nextTick(() => {
            const active = document.querySelector(
                ".sidemenu-nav [data-sidemenu-active='true']",
            );
            active?.scrollIntoView({ block: "nearest", behavior: "instant" });
        }),
    );

    return {
        dashboardPath,
        groupedSections,
        moduleSections,
        activeSections,
        moduleLabel,
        activeView,
        hasModuleView,
        inModuleView,
        backToProject,
        enterModuleView,
        panelComponent: moduleNavView?.panelComponent ?? null,
        moduleId: moduleNavView?.moduleId ?? null,
        navItems,
        navFilter,
        displayedSections,
        isGroupExpanded,
        toggleGroup,
        isSectionExpanded,
        toggleSection,
        isAccountExpanded,
        toggleAccount,
        isActive,
        isActiveExact,
        itemIsActive,
        itemClasses,
        iconClasses,
    };
}
