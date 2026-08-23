import { ref, computed } from "vue";
import { useI18n } from "vue-i18n";

export function usePermissionsFilter(data) {
    const { t } = useI18n();
    const searchInput = ref("");

    function matches(haystack, needle) {
        return haystack.toLowerCase().includes(needle);
    }

    // Client-side filter - the registry is small and fully loaded, no need to
    // round-trip the server.
    const filteredModules = computed(() => {
        const query = searchInput.value.trim().toLowerCase();
        const modules = data.value?.modules ?? [];
        if (!query) return modules;
        return modules
            .map((moduleEntry) => {
                const moduleLabel = t(`backend.modules.${moduleEntry.id}`);
                const moduleHit =
                    matches(moduleEntry.id, query) ||
                    matches(moduleLabel, query);
                const matchingPerms = moduleEntry.permissions.filter(
                    (permission) => {
                        if (moduleHit) return true;
                        const label = t(
                            `backend.permissions.names.${permission.name}`,
                        );
                        return (
                            matches(permission.name, query) ||
                            matches(label, query)
                        );
                    },
                );
                return matchingPerms.length
                    ? { ...moduleEntry, permissions: matchingPerms }
                    : null;
            })
            .filter(Boolean);
    });

    return { searchInput, filteredModules };
}
