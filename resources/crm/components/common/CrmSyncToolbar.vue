<script setup>
defineProps({
  lastSyncedLabel: { type: String, default: "" },
  prefix: { type: String, default: "Last synced:" },
});
</script>

<template>
  <div class="d-flex align-items-center gap-2 flex-wrap justify-content-end">
    <p v-if="lastSyncedLabel" class="small text-secondary mb-0">
      {{ prefix }} {{ lastSyncedLabel }}
    </p>
    <slot />
  </div>
</template>
