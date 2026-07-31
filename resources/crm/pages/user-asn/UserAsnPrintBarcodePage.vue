<script setup>
import { computed, onMounted, ref } from "vue";
import { useRoute } from "vue-router";
import api from "../../services/api";
import { setCrmPageMeta } from "../../composables/useCrmPageMeta.js";

const route = useRoute();
const err = ref("");
const barcode = ref("");
const sku = ref("");
const productName = ref("");

const asnId = computed(() => String(route.params.asnId || ""));
const lineId = computed(() => String(route.params.lineId || ""));
const clientAccountId = computed(() => String(route.query.client_account_id || ""));

function truncateName(value) {
  const raw = String(value || "").trim();
  if (raw.length <= 48) return raw;
  return `${raw.slice(0, 47)}…`;
}

onMounted(async () => {
  setCrmPageMeta({ title: "Save Rack | Barcode", description: "Print product barcode." });
  try {
    const { data: asn } = await api.get(`/asns/${asnId.value}`);
    const line = (asn.lines || []).find((l) => String(l.id) === lineId.value);
    if (!line) {
      err.value = "Line not found.";
      return;
    }
    sku.value = line.sku;
    productName.value = truncateName(line.name);
    const { data: prod } = await api.get(`/inventory/products/${encodeURIComponent(line.sku)}`, {
      params: { client_account_id: clientAccountId.value },
    });
    if (!productName.value) {
      productName.value = truncateName(prod?.product?.name);
    }
    const raw = prod?.product?.barcode;
    barcode.value = typeof raw === "string" && raw.trim() !== "" ? raw.trim() : "";
    if (!barcode.value) {
      err.value = "No barcode on file for this SKU in ShipHero.";
      return;
    }
    const JsBarcode = (await import("jsbarcode")).default;
    const svg = document.getElementById("barcode-svg");
    if (svg) {
      JsBarcode(svg, barcode.value, { format: "CODE128", displayValue: true, fontSize: 14, height: 60 });
    }
    setTimeout(() => window.print(), 300);
  } catch {
    err.value = "Could not load barcode.";
  }
});
</script>

<template>
  <div class="asn-print-root p-4 text-center">
    <p v-if="err" class="text-danger">{{ err }}</p>
    <template v-else>
      <p class="sku-line small text-secondary mb-1">{{ sku }}</p>
      <p v-if="productName" class="name-line mb-2">{{ productName }}</p>
      <svg id="barcode-svg" class="mx-auto d-block"></svg>
    </template>
  </div>
</template>

<style scoped>
.name-line {
  font-size: 0.8125rem;
  color: #333;
  margin-left: auto;
  margin-right: auto;
  max-width: 100%;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

@media print {
  .asn-print-root {
    padding: 0;
  }
}
</style>
