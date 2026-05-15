<script setup>
import { computed, onMounted, ref } from "vue";
import { useRoute } from "vue-router";
import api from "../../services/api";
import { setCrmPageMeta } from "../../composables/useCrmPageMeta.js";
import { formatAsnDisplay } from "../../utils/formatAsnDisplay.js";

const route = useRoute();
const asn = ref(null);
const err = ref("");

const id = computed(() => String(route.params.id || ""));

onMounted(async () => {
  setCrmPageMeta({ title: "Save Rack | Packing Slip", description: "ASN packing slip." });
  try {
    const { data } = await api.get(`/asns/${id.value}`);
    asn.value = data;
    setTimeout(() => window.print(), 400);
  } catch {
    err.value = "Could not load ASN.";
  }
});
</script>

<template>
  <div class="slip-page staff-page staff-page--wide p-4">
    <p v-if="err" class="text-danger">{{ err }}</p>
    <template v-else-if="asn">
      <div class="company-name">Save Rack</div>
      <div class="asn-number">{{ formatAsnDisplay(asn.asn_number) }}</div>
      <hr class="my-4" />
      <table class="table table-sm">
        <thead>
          <tr>
            <th>Item</th>
            <th>SKU</th>
            <th class="text-end">Qty</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="line in asn.lines" :key="line.id">
            <td>{{ line.name }}</td>
            <td>{{ line.sku }}</td>
            <td class="text-end">{{ line.expected_qty }}</td>
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
  letter-spacing: 0.02em;
}
.asn-number {
  font-size: 1.65rem;
  font-weight: 700;
  margin-top: 0.35rem;
}
@media print {
  .slip-page {
    padding: 0.4in !important;
  }
}
</style>
