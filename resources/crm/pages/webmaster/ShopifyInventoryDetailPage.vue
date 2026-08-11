<template>
  <div class="staff-page staff-page--wide">
    <div class="mb-3">
      <RouterLink
        class="small text-decoration-none"
        :to="{ name: 'webmaster-shopify-inventory' }"
      >
        ← Shopify Inventory
      </RouterLink>
      <h1 class="h4 mb-1 mt-2">{{ form.product_title || "Variant" }}</h1>
      <p class="small text-secondary mb-0">{{ form.sku || "—" }}</p>
    </div>

    <div
      v-if="loading"
      class="p-4"
    >
      <CrmLoadingSpinner label="Loading…" />
    </div>

    <div
      v-else
      class="staff-table-card staff-datatable-card staff-datatable-card--white p-3 p-md-4"
      style="max-width: 36rem"
    >
      <div class="mb-3">
        <label class="form-label small fw-semibold">Product Title</label>
        <input
          v-model="form.product_title"
          class="form-control form-control-sm"
        />
      </div>
      <div class="mb-3">
        <label class="form-label small fw-semibold">Variant Title</label>
        <input
          v-model="form.title"
          class="form-control form-control-sm"
        />
      </div>
      <div class="mb-3">
        <label class="form-label small fw-semibold">SKU</label>
        <input
          v-model="form.sku"
          class="form-control form-control-sm"
        />
      </div>
      <div class="row g-2 mb-3">
        <div class="col-6">
          <label class="form-label small fw-semibold">Weight</label>
          <input
            v-model="form.weight"
            type="number"
            min="0"
            step="0.001"
            class="form-control form-control-sm"
          />
        </div>
        <div class="col-6">
          <label class="form-label small fw-semibold">Weight Unit</label>
          <select
            v-model="form.weight_unit"
            class="form-select form-select-sm"
          >
            <option value="POUNDS">POUNDS</option>
            <option value="OUNCES">OUNCES</option>
            <option value="GRAMS">GRAMS</option>
            <option value="KILOGRAMS">KILOGRAMS</option>
          </select>
        </div>
      </div>

      <div
        v-if="(variant?.inventory || []).length"
        class="mb-3 small"
      >
        <div class="fw-semibold mb-1">Location Qty</div>
        <ul class="mb-0">
          <li
            v-for="loc in variant.inventory"
            :key="loc.location_id"
          >
            {{ loc.location_name }}: {{ loc.available }}
          </li>
        </ul>
      </div>

      <button
        type="button"
        class="btn btn-primary btn-sm"
        :disabled="busy"
        @click="save"
      >
        Save To Shopify
      </button>
    </div>
  </div>
</template>

<script setup>
import { onMounted, reactive, ref } from "vue";
import { RouterLink, useRoute } from "vue-router";
import api from "../../services/api";
import CrmLoadingSpinner from "../../components/common/CrmLoadingSpinner.vue";
import { setCrmPageMeta } from "../../composables/useCrmPageMeta.js";
import { useToast } from "../../composables/useToast";

const route = useRoute();
const toast = useToast();
const loading = ref(true);
const busy = ref(false);
const variant = ref(null);
const form = reactive({
  product_title: "",
  title: "",
  sku: "",
  weight: "",
  weight_unit: "POUNDS",
});

async function load() {
  loading.value = true;
  try {
    const { data } = await api.get(`/shopify/inventory/${route.params.id}`);
    variant.value = data?.variant || null;
    form.product_title = variant.value?.product_title || "";
    form.title = variant.value?.title || "";
    form.sku = variant.value?.sku || "";
    form.weight = variant.value?.weight ?? "";
    form.weight_unit = variant.value?.weight_unit || "POUNDS";
  } catch (e) {
    toast.errorFrom(e, "Could not load variant.");
  } finally {
    loading.value = false;
  }
}

async function save() {
  busy.value = true;
  try {
    const { data } = await api.patch(`/shopify/inventory/${route.params.id}`, {
      product_title: form.product_title,
      title: form.title,
      sku: form.sku,
      weight: form.weight === "" ? null : Number(form.weight),
      weight_unit: form.weight_unit,
    });
    toast.success(data?.message || "Saved To Shopify.");
    await load();
  } catch (e) {
    toast.errorFrom(e, "Could not save to Shopify.");
  } finally {
    busy.value = false;
  }
}

onMounted(() => {
  setCrmPageMeta({
    title: "Save Rack | Shopify Variant",
    description: "Edit Shopify variant and push to Shopify.",
  });
  void load();
});
</script>
