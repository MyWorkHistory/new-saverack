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
    <div class="d-flex flex-wrap align-items-end justify-content-between gap-3 mb-4">
      <div>
        <h1 class="h4 mb-1 fw-semibold text-body">Returned Items</h1>
        <p class="small admin-returns-list__subtitle mb-0">
          Processed return line items will appear here after warehouse processing.
        </p>
      </div>
    </div>

    <div class="admin-returns-list staff-table-card staff-datatable-card staff-datatable-card--white w-100">
      <div class="p-5 text-center text-secondary">
        <p class="mb-0 fw-semibold text-body">Coming soon</p>
      </div>
    </div>
  </div>
</template>

<style scoped>
.admin-returns-list__subtitle {
  font-size: 0.8125rem;
  font-weight: 500;
  color: var(--bs-secondary-color, #6c757d);
}

[data-bs-theme="dark"] .admin-returns-list__subtitle {
  color: #fff !important;
}
</style>
