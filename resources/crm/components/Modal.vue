<script setup>
defineProps({
    open: { type: Boolean, default: false },
    title: { type: String, default: '' },
});
defineEmits(['close']);
</script>

<template>
    <Teleport to="body">
        <Transition name="crm-modal">
            <div
                v-if="open"
                class="fixed inset-0 z-[100000] flex items-center justify-center overflow-y-auto px-4 py-8"
                role="dialog"
                aria-modal="true"
            >
                <div class="absolute inset-0 bg-gray-900/50 backdrop-blur-[2px]" aria-hidden="true" @click="$emit('close')" />
                <div class="relative z-[100001] w-full max-w-lg rounded-xl border border-gray-200 bg-white p-6 shadow-xl dark:border-gray-700 dark:bg-gray-900">
                    <h2 v-if="title" class="mb-4 text-lg font-semibold text-gray-900 dark:text-white">{{ title }}</h2>
                    <slot />
                </div>
            </div>
        </Transition>
    </Teleport>
</template>

<style scoped>
.crm-modal-enter-active,
.crm-modal-leave-active {
    transition: opacity 0.15s ease;
}
.crm-modal-enter-from,
.crm-modal-leave-to {
    opacity: 0;
}
</style>
