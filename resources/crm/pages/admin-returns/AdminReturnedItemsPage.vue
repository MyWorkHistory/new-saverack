<script setup>
import { onMounted } from "vue";
import { setCrmPageMeta } from "../../composables/useCrmPageMeta.js";

onMounted(() => {
  setCrmPageMeta({
    title: "Save Rack | Returned Items",
    description: "Processed return line items.",
  });
});
</script>

<template>
  <div class="staff-page staff-page--wide admin-returns-page">
    <div class="mb-4">
      <h1 class="h4 mb-1 fw-semibold text-body">Returned Items</h1>
      <p class="text-secondary small mb-0">Processed return line items will appear here.</p>
    </div>
    <div class="staff-table-card staff-datatable-card staff-datatable-card--white w-100">
      <div class="p-5 text-center text-secondary">
        <p class="mb-0 fw-semibold text-body">Coming soon</p>
      </div>
    </div>
  </div>
</template>
