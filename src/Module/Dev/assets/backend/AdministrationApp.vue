<script setup>
import { computed } from "vue";
import DashboardOverview from "@general/backend/dashboard/DashboardOverview.vue";
import UsersTab from "@dev/backend/users/UsersTab.vue";
import AccessRequestsTab from "@dev/backend/access-requests/AccessRequestsTab.vue";
import AuditTab from "@dev/backend/audit/AuditTab.vue";
import PermissionsTab from "@dev/backend/permissions/PermissionsTab.vue";
import ModulesTab from "@dev/backend/modules/ModulesTab.vue";
import MountPointsTab from "@dev/backend/mount-points/MountPointsTab.vue";


const props = defineProps({
    tab: { type: String, default: "overview" },
    stats: { type: Object, default: () => ({}) },
    users: { type: Object, default: () => ({}) },
    accessRequests: { type: Object, default: () => ({}) },
    audit: { type: Object, default: () => ({}) },
    permissions: { type: Object, default: () => ({}) },
    modules: { type: Object, default: () => ({}) },
    mountPoints: { type: Object, default: () => ({}) },
    search: { type: String, default: "" },
    group: { type: String, default: "" },
    overviewPath: { type: String, required: true },
    usersPath: { type: String, required: true },
    userCreatePath: { type: String, required: true },
    userUpdatePath: { type: String, required: true },
    userToggleRolePath: { type: String, required: true },
    userDeletePath: { type: String, required: true },
    impersonatePath: { type: String, required: true },
    accessRequestsPath: { type: String, required: true },
    auditPath: { type: String, required: true },
    permissionsPath: { type: String, required: true },
    modulesPath: { type: String, required: true },
    moduleUpdatePath: { type: String, required: true },
    moduleVerifyPasswordPath: { type: String, required: true },
    mountPointsPath: { type: String, required: true },
    mountPointCreatePath: { type: String, required: true },
    mountPointUpdatePath: { type: String, required: true },
    mountPointDeletePath: { type: String, required: true },
    mountPointTestPath: { type: String, required: true },
    accessRequestApprovePath: { type: String, required: true },
    accessRequestRejectPath: { type: String, required: true },
    accessRequestPurgePath: { type: String, required: true },
    csrfToken: { type: String, default: "" },
});

// Each tab is self-describing: label, icon, URL path and initial SSR data colocated.
/**
 * What the server already sent for the tab being shown.
 *
 * This array used to carry each tab's label, icon and address as well. Those
 * moved to `DevModule::getModuleNavView()`, which is where the menu reads them
 * from - and keeping a second copy here would be a second copy to forget, the
 * way the two nav icon maps drifted until one of them was a version behind.
 */
const INITIAL_DATA = {
    overview: () => props.stats,
    users: () => props.users,
    access_requests: () => props.accessRequests,
    audit: () => props.audit,
    permissions: () => props.permissions,
    modules: () => props.modules,
    mount_points: () => props.mountPoints,
};

/**
 * Which tab this page is, decided by the server.
 *
 * It used to be state this component drove: the tab row set it, and the
 * composable wrote the matching address behind it. The menu owns the switching
 * now and each tab is a real navigation, so there is nothing left to sync -
 * the route the reader asked for is the answer, and it arrives in a prop.
 */
const tab = computed(() => props.tab);

// Each tab subcomponent owns its data via its own composable. The parent only
// passes initial SSR data for the active tab; non-active tabs receive null and
// their composable auto-loads via XHR on first mount. <KeepAlive> preserves
// state across tab switches so we don't refetch every time.
function initialDataFor(key) {
    if (key !== props.tab) return null;
    return INITIAL_DATA[key]?.() ?? null;
}
</script>

<template>
    <div class="flex flex-col md:flex-row gap-6">
        <!-- No tab column and no mobile tab row: the side menu's module view
             lists the seven, and each one is already a route of its own. Two
             surfaces answering "which tab am I on" is one too many - the same
             call the settings page made in 0.9.29. -->
        <div class="flex-1 min-w-0 space-y-6">
            <KeepAlive>
                <DashboardOverview
                    v-if="tab === 'overview'"
                    :stats="initialDataFor('overview') ?? {}"
                />
                <UsersTab
                    v-else-if="tab === 'users'"
                    :users-path="usersPath"
                    :user-create-path="userCreatePath"
                    :user-update-path="userUpdatePath"
                    :user-toggle-role-path="userToggleRolePath"
                    :user-delete-path="userDeletePath"
                    :impersonate-path="impersonatePath"
                    :csrf-token="csrfToken"
                    :initial-data="initialDataFor('users')"
                    :initial-search="search"
                />
                <AccessRequestsTab
                    v-else-if="tab === 'access_requests'"
                    :access-requests-path="accessRequestsPath"
                    :access-request-approve-path="accessRequestApprovePath"
                    :access-request-reject-path="accessRequestRejectPath"
                    :access-request-purge-path="accessRequestPurgePath"
                    :csrf-token="csrfToken"
                    :initial-data="initialDataFor('access_requests')"
                    :initial-search="search"
                />
                <AuditTab
                    v-else-if="tab === 'audit'"
                    :audit-path="auditPath"
                    :initial-data="initialDataFor('audit')"
                />
                <PermissionsTab
                    v-else-if="tab === 'permissions'"
                    :permissions-path="permissionsPath"
                    :initial-data="initialDataFor('permissions')"
                />
                <ModulesTab
                    v-else-if="tab === 'modules'"
                    :modules-path="modulesPath"
                    :module-update-path="moduleUpdatePath"
                    :module-verify-password-path="moduleVerifyPasswordPath"
                    :initial-data="initialDataFor('modules')"
                />
                <MountPointsTab
                    v-else-if="tab === 'mount_points'"
                    :mount-points-path="mountPointsPath"
                    :mount-point-create-path="mountPointCreatePath"
                    :mount-point-update-path="mountPointUpdatePath"
                    :mount-point-delete-path="mountPointDeletePath"
                    :mount-point-test-path="mountPointTestPath"
                    :initial-data="initialDataFor('mount_points')"
                />
            </KeepAlive>
        </div>
    </div>
</template>
