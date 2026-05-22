<script setup>
import { computed, onMounted, ref } from "vue";
import { useRoute } from "vue-router";
import api from "../../services/api";
import { setCrmPageMeta } from "../../composables/useCrmPageMeta.js";
import { formatRmaLabel } from "../../utils/formatReturnDisplay.js";

const route = useRoute();
const ret = ref(null);
const err = ref("");

const id = computed(() => String(route.params.id || ""));
const accountName = computed(() => {
  const from = String(ret.value?.client_account_company_name || "").trim();
  return from || "Save Rack";
});
const lines = computed(() => (ret.value?.lines || []).filter((l) => Number(l.return_qty) > 0));

onMounted(async () => {
  setCrmPageMeta({ title: "Save Rack | Return Packing Slip", description: "Return packing slip." });
  try {
    const { data } = await api.get(`/returns/${id.value}`);
    ret.value = data;
    setTimeout(() => window.print(), 400);
  } catch {
    err.value = "Could not load return.";
  }
});
</script>

<template>
  <div class="slip-page staff-page staff-page--wide p-4">
    <p v-if="err" class="text-danger">{{ err }}</p>
    <template v-else-if="ret">
      <div class="company-name">{{ accountName }}</div>
      <div class="rma-number">{{ formatRmaLabel(ret.rma_number) }}</div>
      <hr class="my-4" />
      <table class="table table-sm slip-items-table">
        <thead>
          <tr>
            <th class="slip-item-col">Item</th>
            <th class="slip-sku-col">SKU</th>
            <th class="text-end">Qty</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="line in lines" :key="line.id">
            <td class="slip-item-name">{{ line.name }}</td>
            <td class="slip-sku">{{ line.sku }}</td>
            <td class="text-end">{{ line.return_qty }}</td>
          </tr>
        </tbody>
      </table>
    </template>
  </div>
</template>

<style scoped>
.company-name {
  font-size: 2.25rem;
  font-weight: 800;
}
.rma-number {
  font-size: 1.65rem;
  font-weight: 700;
  margin-top: 0.35rem;
}
.slip-items-table {
  table-layout: fixed;
}
.slip-item-col {
  width: 48%;
}
.slip-sku-col {
  width: 38%;
}
.slip-item-name {
  font-size: 0.78rem;
}
.slip-sku {
  font-weight: 700;
  word-break: break-all;
}
@media print {
  .slip-page {
    padding: 0.4in !important;
  }
}
</style>
