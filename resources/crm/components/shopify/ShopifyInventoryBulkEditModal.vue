<script setup>
import { computed, ref } from "vue";
import api from "../../services/api";
import ShopifyCsvUploadModal from "./ShopifyCsvUploadModal.vue";
import { useToast } from "../../composables/useToast";

const props = defineProps({
  open: { type: Boolean, default: false },
  /** Scopes SKU matching to one account. Empty means match across all accounts. */
  clientAccountId: { type: [String, Number], default: "" },
});

const emit = defineEmits(["update:open", "queued"]);

const toast = useToast();
const busy = ref(false);

const columns = computed(() => [
  { label: "Name", required: false },
  { label: "SKU", required: true },
  { label: "Barcode", required: false },
  { label: "Weight", required: false },
  { label: "Height", required: false },
  { label: "Width", required: false },
  { label: "Length", required: false },
]);

async function submit({ file }) {
  busy.value = true;
  try {
    const fd = new FormData();
    fd.append("file", file);
    if (props.clientAccountId) {
      fd.append("client_account_id", String(props.clientAccountId));
    }
    const { data } = await api.post("/shopify/inventory/bulk-edit", fd, {
      headers: { "Content-Type": "multipart/form-data" },
      timeout: 180000,
    });
    toast.success(data?.message || "Upload received. Products are being updated in the background.");
    emit("queued");
    emit("update:open", false);
  } catch (e) {
    toast.errorFrom(e, "Could not upload the CSV.");
  } finally {
    busy.value = false;
  }
}
</script>

<template>
  <ShopifyCsvUploadModal
    :open="props.open"
    title="Bulk Edit Products"
    subtitle="Update your products in bulk using a CSV file."
    :columns="columns"
    template-name="bulk-edit-products-template.csv"
    submit-label="Upload CSV"
    :busy="busy"
    @update:open="emit('update:open', $event)"
    @submit="submit"
  />
</template>
