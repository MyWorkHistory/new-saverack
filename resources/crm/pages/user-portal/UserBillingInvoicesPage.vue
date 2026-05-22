<script setup>
import BillingInvoicesListPage from "../billing/BillingInvoicesListPage.vue";
import { setCrmPageMeta } from "../../composables/useCrmPageMeta.js";
import { onMounted } from "vue";

onMounted(() => {
  setCrmPageMeta({
    title: "Save Rack | Invoices",
    description: "Your fulfillment invoices.",
  });
});
</script>

<template>
  <BillingInvoicesListPage portal-mode />
</template>
