<script setup>
import { computed, onMounted, ref } from "vue";
import { useRoute } from "vue-router";
import api from "../../services/api";
import { setCrmPageMeta } from "../../composables/useCrmPageMeta.js";
import { formatAsnLabel } from "../../utils/formatAsnDisplay.js";

const route = useRoute();
const asn = ref(null);
const err = ref("");

const id = computed(() => String(route.params.id || ""));

const accountName = computed(() => {
  const fromAsn = String(asn.value?.client_account_company_name || "").trim();
  return fromAsn || "Save Rack";
});

onMounted(async () => {
  setCrmPageMeta({ title: "Save Rack | Identification Label", description: "ASN identification label." });
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
  <div class="label-sheet">
    <p v-if="err" class="p-3 text-danger">{{ err }}</p>
    <div v-else-if="asn" class="label-inner">
      <div class="label-account">{{ accountName }}</div>
      <div class="label-asn-number">{{ formatAsnLabel(asn.asn_number) }}</div>
      <div class="label-addr mt-3">
        <div>3135 Drane Field Rd #20</div>
        <div>Lakeland, FL 33811</div>
      </div>
      <p class="label-print-hint small text-secondary mt-4 mb-0">Print Identification Label</p>
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
  line-height: 1.15;
}
.label-asn-number {
  font-size: 3.35rem;
  font-weight: 800;
  letter-spacing: 0.04em;
  margin-top: 0.45rem;
  line-height: 1.1;
}
.label-addr {
  font-size: 1.25rem;
  line-height: 1.4;
}
.label-print-hint {
  font-weight: 600;
}
@media print {
  @page {
    size: 4in 6in;
    margin: 0.12in;
  }
  .label-sheet {
    min-height: auto;
  }
  .label-print-hint {
    display: none;
  }
}
</style>
