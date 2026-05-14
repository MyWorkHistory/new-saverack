<script setup>
import { computed, onMounted, ref } from "vue";
import { useRoute } from "vue-router";
import api from "../../services/api";
import { BRAND_MARK_SRC } from "../../utils/brandAssets.js";
import { setCrmPageMeta } from "../../composables/useCrmPageMeta.js";

const route = useRoute();
const asn = ref(null);
const err = ref("");
const markSrc = computed(() => BRAND_MARK_SRC());

const id = computed(() => String(route.params.id || ""));

onMounted(async () => {
  setCrmPageMeta({ title: "Save Rack | Shipping Label", description: "4x6 label." });
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
      <img :src="markSrc" alt="Save Rack" class="label-logo mb-2" width="120" height="120" />
      <div class="label-brand">Save Rack</div>
      <div class="label-asn">ASN# {{ asn.asn_number }}</div>
      <div class="label-addr mt-3">
        <div>3135 Drane Field Rd #20</div>
        <div>Lakeland, FL 33811</div>
      </div>
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
.label-logo {
  object-fit: contain;
}
.label-brand {
  font-size: 1.35rem;
  font-weight: 700;
}
.label-asn {
  font-size: 1.1rem;
  font-weight: 600;
  margin-top: 0.25rem;
}
.label-addr {
  font-size: 0.95rem;
  line-height: 1.45;
}
@media print {
  @page {
    size: 4in 6in;
    margin: 0.12in;
  }
  .label-sheet {
    min-height: auto;
  }
}
</style>
