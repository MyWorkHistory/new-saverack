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
const warehouseLines = computed(() => {
  const addr = ret.value?.return_warehouse_address || {};
  return [addr.line1, addr.line2].filter((l) => String(l || "").trim() !== "");
});

onMounted(async () => {
  setCrmPageMeta({ title: "Save Rack | Shipping Label", description: "4x6 return shipping label." });
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
  <div class="label-sheet">
    <p v-if="err" class="p-3 text-danger">{{ err }}</p>
    <div v-else-if="ret" class="label-inner">
      <div class="label-account">{{ accountName }}</div>
      <div class="label-rma">{{ formatRmaLabel(ret.rma_number) }}</div>
      <div class="label-addr mt-3">
        <div v-for="(line, i) in warehouseLines" :key="'l-' + i">{{ line }}</div>
      </div>
      <p class="label-print-hint small text-secondary mt-4 mb-0">Print Shipping Label (4×6)</p>
    </div>
  </div>
</template>

<style scoped>
.label-sheet {
  min-height: 100vh;
  display: flex;
  align-items: center;
  justify-content: center;
  background: #fff;
  color: #1a1a1a;
}
.label-inner {
  text-align: center;
  padding: 0.5in;
}
.label-account {
  font-size: 1.85rem;
  font-weight: 800;
}
.label-rma {
  font-size: 3.35rem;
  font-weight: 800;
  letter-spacing: 0.04em;
  margin-top: 0.45rem;
}
.label-addr {
  font-size: 1.25rem;
  line-height: 1.4;
}
@media print {
  @page {
    size: 4in 6in;
    margin: 0.12in;
  }
  .label-print-hint {
    display: none;
  }
}
</style>
