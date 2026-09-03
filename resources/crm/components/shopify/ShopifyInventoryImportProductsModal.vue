<script setup>
import { computed, ref } from "vue";
import api from "../../services/api";
import ShopifyCsvUploadModal from "./ShopifyCsvUploadModal.vue";
import { useToast } from "../../composables/useToast";

const props = defineProps({
  open: { type: Boolean, default: false },
  accounts: { type: Array, default: () => [] },
  /** Pre-selects the account currently filtered on the list page. */
  clientAccountId: { type: [String, Number], default: "" },
});

const emit = defineEmits(["update:open", "queued"]);

const toast = useToast();
const busy = ref(false);

const columns = computed(() => [
  { label: "Name", required: true },
  { label: "SKU", required: true },
  { label: "Barcode", required: false },
  { label: "Weight", required: false },
  { label: "Height", required: false },
  { label: "Width", required: false },
  { label: "Length", required: false },
]);

async function submit({ file, accountId }) {
  busy.value = true;
  try {
    const fd = new FormData();
    fd.append("file", file);
    fd.append("client_account_id", String(accountId));
    const { data } = await api.post("/shopify/inventory/import", fd, {
      headers: { "Content-Type": "multipart/form-data" },
      timeout: 180000,
    });
    toast.success(data?.message || "Upload received. Products are being created in the background.");
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
    title="Upload Inventory Products"
    subtitle="Add your products in bulk using a CSV file."
    :columns="columns"
    :accounts="props.accounts"
    :default-account-id="props.clientAccountId"
    require-account
    template-name="inventory-products-template.csv"
    submit-label="Upload CSV"
    :busy="busy"
    @update:open="emit('update:open', $event)"
    @submit="submit"
  />
</template>
