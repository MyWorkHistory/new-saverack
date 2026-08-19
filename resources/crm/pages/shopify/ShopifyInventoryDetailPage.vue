<script setup>
import { onMounted, reactive, ref } from "vue";
import { useRoute, useRouter } from "vue-router";
import api from "../../services/api";
import CrmLoadingSpinner from "../../components/common/CrmLoadingSpinner.vue";
import { setCrmPageMeta } from "../../composables/useCrmPageMeta.js";
import { useToast } from "../../composables/useToast";

const route = useRoute();
const router = useRouter();
const toast = useToast();
const loading = ref(true);
const busy = ref(false);
const variant = ref(null);
const form = reactive({
  product_title: "",
  title: "",
  sku: "",
  barcode: "",
  weight: "",
  weight_unit: "POUNDS",
  length: "",
  width: "",
  height: "",
  dimension_unit: "INCHES",
  inventory: [],
});

async function load() {
  loading.value = true;
  try {
    const { data } = await api.get(`/shopify/inventory/${route.params.id}`);
    variant.value = data?.variant || null;
    form.product_title = variant.value?.product_title || "";
    form.title = variant.value?.title || "";
    form.sku = variant.value?.sku || "";
    form.barcode = variant.value?.barcode || "";
    form.weight = variant.value?.weight ?? "";
    form.weight_unit = variant.value?.weight_unit || "POUNDS";
    form.length = variant.value?.length ?? "";
    form.width = variant.value?.width ?? "";
    form.height = variant.value?.height ?? "";
    form.dimension_unit = variant.value?.dimension_unit || "INCHES";
    form.inventory = (variant.value?.inventory || []).map((row) => ({
      location_id: row.location_id,
      location_name: row.location_name,
      available: row.available,
    }));
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
      barcode: form.barcode,
      weight: form.weight === "" ? null : Number(form.weight),
      weight_unit: form.weight_unit,
      length: form.length === "" ? null : Number(form.length),
      width: form.width === "" ? null : Number(form.width),
      height: form.height === "" ? null : Number(form.height),
      dimension_unit: form.dimension_unit,
      inventory: form.inventory
        .filter((row) => row.available !== "" && row.available != null)
        .map((row) => ({
          location_id: row.location_id,
          available: Number(row.available),
        })),
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

<template>
  <div class="staff-page staff-page--wide">
    <div
      v-if="loading"
      class="p-5 d-flex justify-content-center"
    >
      <CrmLoadingSpinner message="Loading…" />
    </div>

    <template v-else>
      <header class="order-detail-page__hero mb-4">
        <button
          type="button"
          class="btn btn-link btn-sm text-secondary px-0 py-0 mb-2 text-decoration-none order-detail-page__back-link"
          @click="router.push({ name: 'shopify-inventory' })"
        >
          &lt; Back to Shopify Inventory
        </button>
        <div class="d-flex flex-wrap justify-content-between align-items-start gap-3">
          <div class="min-w-0">
            <h1 class="h4 mb-1 fw-bold text-body">
              {{ form.product_title || "Variant" }}
            </h1>
            <p class="small text-secondary mb-0 order-detail-page__hero-meta">
              {{ form.sku || "—" }}
              <span v-if="form.title"> · {{ form.title }}</span>
            </p>
          </div>
          <div class="d-flex flex-wrap gap-2 align-items-center flex-shrink-0">
            <button
              type="button"
              class="btn btn-primary staff-page-primary fw-semibold"
              :disabled="busy"
              @click="save"
            >
              {{ busy ? "Saving…" : "Save To Shopify" }}
            </button>
          </div>
        </div>
      </header>

      <div class="row g-3">
        <div class="col-12 col-lg-7">
          <div class="staff-table-card staff-datatable-card staff-datatable-card--white">
            <div class="px-4 py-3 border-bottom">
              <strong class="text-body">Variant Details</strong>
            </div>
            <div class="p-4">
              <div class="mb-3">
                <label
                  class="form-label"
                  for="shopify-variant-product-title"
                >Product Title</label>
                <input
                  id="shopify-variant-product-title"
                  v-model="form.product_title"
                  class="form-control"
                />
              </div>
              <div class="mb-3">
                <label
                  class="form-label"
                  for="shopify-variant-title"
                >Variant Title</label>
                <input
                  id="shopify-variant-title"
                  v-model="form.title"
                  class="form-control"
                />
              </div>
              <div class="mb-3">
                <label
                  class="form-label"
                  for="shopify-variant-sku"
                >SKU</label>
                <input
                  id="shopify-variant-sku"
                  v-model="form.sku"
                  class="form-control"
                />
              </div>
              <div class="mb-3">
                <label
                  class="form-label"
                  for="shopify-variant-barcode"
                >Barcode</label>
                <input
                  id="shopify-variant-barcode"
                  v-model="form.barcode"
                  class="form-control"
                />
              </div>
              <div class="row g-3">
                <div class="col-6">
                  <label
                    class="form-label"
                    for="shopify-variant-weight"
                  >Weight</label>
                  <input
                    id="shopify-variant-weight"
                    v-model="form.weight"
                    type="number"
                    min="0"
                    step="0.001"
                    class="form-control"
                  />
                </div>
                <div class="col-6">
                  <label
                    class="form-label"
                    for="shopify-variant-weight-unit"
                  >Weight Unit</label>
                  <select
                    id="shopify-variant-weight-unit"
                    v-model="form.weight_unit"
                    class="form-select"
                  >
                    <option value="POUNDS">POUNDS</option>
                    <option value="OUNCES">OUNCES</option>
                    <option value="GRAMS">GRAMS</option>
                    <option value="KILOGRAMS">KILOGRAMS</option>
                  </select>
                </div>
              </div>
              <div class="row g-3 mt-1">
                <div class="col-4">
                  <label class="form-label" for="shopify-variant-length">Length</label>
                  <input
                    id="shopify-variant-length"
                    v-model="form.length"
                    type="number"
                    min="0"
                    step="0.001"
                    class="form-control"
                  >
                </div>
                <div class="col-4">
                  <label class="form-label" for="shopify-variant-width">Width</label>
                  <input
                    id="shopify-variant-width"
                    v-model="form.width"
                    type="number"
                    min="0"
                    step="0.001"
                    class="form-control"
                  >
                </div>
                <div class="col-4">
                  <label class="form-label" for="shopify-variant-height">Height</label>
                  <input
                    id="shopify-variant-height"
                    v-model="form.height"
                    type="number"
                    min="0"
                    step="0.001"
                    class="form-control"
                  >
                </div>
              </div>
              <div class="mt-3">
                <label class="form-label" for="shopify-variant-dimension-unit">Dimension Unit</label>
                <select
                  id="shopify-variant-dimension-unit"
                  v-model="form.dimension_unit"
                  class="form-select"
                >
                  <option value="INCHES">INCHES</option>
                  <option value="CENTIMETERS">CENTIMETERS</option>
                </select>
              </div>
            </div>
          </div>
        </div>

        <div class="col-12 col-lg-5">
          <div class="staff-table-card staff-datatable-card staff-datatable-card--white">
            <div class="px-4 py-3 border-bottom">
              <strong class="text-body">Location Qty</strong>
            </div>
            <div
              v-if="!form.inventory.length"
              class="px-4 py-4 small text-secondary"
            >
              No location quantities. Save a quantity to push it to Shopify.
            </div>
            <div
              v-else
              class="table-responsive staff-table-wrap"
            >
              <table class="table table-hover align-middle mb-0 staff-data-table">
                <thead class="table-light staff-table-head">
                  <tr>
                    <th
                      class="staff-table-head__th"
                      scope="col"
                    >Location</th>
                    <th
                      class="staff-table-head__th text-end"
                      scope="col"
                    >Available</th>
                  </tr>
                </thead>
                <tbody>
                  <tr
                    v-for="loc in form.inventory"
                    :key="loc.location_id"
                    class="align-middle"
                  >
            <div
              v-else
              class="table-responsive staff-table-wrap"
            >
              <table class="table table-hover align-middle mb-0 staff-data-table">
                <thead class="table-light staff-table-head">
                  <tr>
                    <th
                      class="staff-table-head__th"
                      scope="col"
                    >Location</th>
                    <th
                      class="staff-table-head__th text-end"
                      scope="col"
                    >Available</th>
                  </tr>
                </thead>
                <tbody>
                  <tr
                    v-for="loc in variant.inventory"
                    :key="loc.location_id"
                    class="align-middle"
                  >
                    <td>{{ loc.location_name || "—" }}</td>
                    <td class="text-end fw-semibold">
                      <input
                        v-model="loc.available"
                        type="number"
                        class="form-control form-control-sm text-end"
                      >
                    </td>
                  </tr>
                </tbody>
              </table>
            </div>
          </div>
        </div>
      </div>
    </template>
  </div>
</template>
