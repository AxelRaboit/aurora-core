<script setup>
defineProps({
    heading: { type: String, default: "" },
    subtitle: { type: String, default: "" },
});
</script>

<template>
    <div class="flex min-h-[80vh] items-center justify-center px-4 py-12">
        <div class="aurora-card w-full sm:max-w-md shadow-md overflow-hidden">
            <div class="relative h-20 bg-linear-to-br from-accent-400 via-accent-500 to-accent-700">
                <div
                    class="absolute inset-0 opacity-25"
                    style="background-image: radial-gradient(circle at 25% 20%, rgba(255,255,255,0.9) 0%, transparent 45%), radial-gradient(circle at 80% 80%, rgba(255,255,255,0.4) 0%, transparent 45%);"
                />
                <div v-if="$slots.icon" class="absolute inset-x-0 -bottom-6 flex justify-center">
                    <div class="w-12 h-12 rounded-2xl bg-surface border border-line shadow-md flex items-center justify-center text-accent-500">
                        <slot name="icon" />
                    </div>
                </div>
            </div>

            <div class="px-4 pb-6 sm:px-6" :class="$slots.icon ? 'pt-10' : 'pt-6'">
                <div v-if="heading || subtitle" class="text-center mb-5">
                    <h1 v-if="heading" class="text-primary text-xl leading-tight">{{ heading }}</h1>
                    <p v-if="subtitle" class="text-muted text-sm mt-1">{{ subtitle }}</p>
                </div>

                <slot name="banner" />
                <slot />
                <slot name="footer" />
            </div>
        </div>
    </div>
</template>
