<script setup>
import { computed, onMounted, ref } from "vue";
import CrmLoadingSpinner from "../../components/common/CrmLoadingSpinner.vue";
import PortalFulfillmentAgreementModal from "../../components/user-portal/PortalFulfillmentAgreementModal.vue";
import PortalMyAccountPageHeader from "../../components/user-portal/PortalMyAccountPageHeader.vue";
import { setCrmPageMeta } from "../../composables/useCrmPageMeta.js";
import { useToast } from "../../composables/useToast";
import api from "../../services/api";

const toast = useToast();
const loading = ref(true);
const onboarding = ref(null);

const fulfillmentAgreement = computed(() => onboarding.value?.fulfillment_agreement || null);
const profile = computed(() => onboarding.value?.profile || null);
const agreementDefaultCompany = computed(() => profile.value?.company_name || "");
const agreementDefaultRepName = computed(() => {
  const name = String(profile.value?.name || "").trim();
  if (name) return name;
  return [
    String(profile.value?.contact_first_name || "").trim(),
    String(profile.value?.contact_last_name || "").trim(),
  ]
    .filter(Boolean)
    .join(" ");
});

setCrmPageMeta({
  title: "Save Rack | Fulfillment Agreement",
  description: "Review and manage your fulfillment agreement.",
});

async function loadOnboarding() {
  loading.value = true;
  try {
    const { data } = await api.get("/portal/onboarding");
    onboarding.value = data;
  } catch (e) {
    toast.errorFrom(e, "Could not load fulfillment agreement.");
  } finally {
    loading.value = false;
  }
}

function onAccepted(data) {
  if (data && typeof data === "object") {
    onboarding.value = data;
  }
}

onMounted(() => loadOnboarding());
</script>

<template>
  <div class="staff-page staff-page--wide">
    <PortalMyAccountPageHeader title="Fulfillment Agreement" />

    <div v-if="loading" class="d-flex justify-content-center py-5">
      <CrmLoadingSpinner />
    </div>

    <div v-else class="staff-table-card staff-datatable-card staff-datatable-card--white p-0 overflow-hidden">
      <PortalFulfillmentAgreementModal
        page-mode
        :agreement="fulfillmentAgreement"
        :default-company="agreementDefaultCompany"
        :default-rep-name="agreementDefaultRepName"
        @accepted="onAccepted"
      />
    </div>
  </div>
</template>
