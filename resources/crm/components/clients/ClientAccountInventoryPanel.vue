<script setup>
import InventoryBetaListPage from "../../pages/inventory/InventoryBetaListPage.vue";

defineProps({
  accountId: { type: [String, Number], required: true },
});
</script>

<template>
  <InventoryBetaListPage :fixed-client-account-id="accountId" embedded />
</template>
