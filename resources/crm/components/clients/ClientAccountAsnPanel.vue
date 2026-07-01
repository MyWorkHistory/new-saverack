<script setup>
import UserAsnListPage from "../../pages/user-asn/UserAsnListPage.vue";

defineProps({
  accountId: { type: [String, Number], required: true },
});
</script>

<template>
  <UserAsnListPage
    :fixed-client-account-id="accountId"
    embedded
    detail-route-name="admin-asn-detail"
  />
</template>
