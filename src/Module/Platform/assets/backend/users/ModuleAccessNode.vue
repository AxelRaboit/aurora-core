<script setup>
import AppCheckbox from "@/shared/components/form/toggle/AppCheckbox.vue";

const props = defineProps({
    node: { type: Object, required: true },
    disabledKeys: { type: Array, required: true },
    parentMasked: { type: Boolean, default: false },
    depth: { type: Number, default: 0 },
});

defineEmits(["toggle"]);

// A node is effectively masked if it's in the user's disabled list OR any
// ancestor is - cascade is enforced server-side, but we reflect it visually
// so admins immediately see why a child is greyed out.
function isMasked(key) {
    return props.parentMasked || props.disabledKeys.includes(key);
}
</script>

<template>
    <div :class="depth > 0 ? 'ml-4 pl-4 border-l border-line space-y-3' : 'space-y-3'">
        <div class="flex items-center justify-between gap-4" :class="{ 'opacity-60': parentMasked }">
            <div class="min-w-0">
                <p class="text-sm text-primary" :class="{ 'font-semibold': depth === 0 }">{{ node.label }}</p>
                <p v-if="node.description" class="text-xs text-muted mt-0.5">{{ node.description }}</p>
            </div>
            <AppCheckbox
                :model-value="!isMasked(node.key)"
                :disabled="parentMasked"
                v-on:update:model-value="$emit('toggle', node.key)"
            />
        </div>
        <template v-if="node.children?.length">
            <ModuleAccessNode
                v-for="child in node.children"
                :key="child.key"
                :node="child"
                :disabled-keys="disabledKeys"
                :parent-masked="isMasked(node.key)"
                :depth="depth + 1"
                v-on:toggle="$emit('toggle', $event)"
            />
        </template>
    </div>
</template>
