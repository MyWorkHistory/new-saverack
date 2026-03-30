<script setup>
import { onMounted, ref } from "vue";
import api from "../services/api";
import PageHeader from "../components/common/PageHeader.vue";
import StatCard from "../components/dashboard/StatCard.vue";

const summary = ref({
  total_users: 0,
  active_users: 0,
  activities_today: 0,
});

onMounted(async () => {
  const { data } = await api.get("/dashboard/summary");
  summary.value = data;
});
</script>

<template>
  <div class="space-y-6">
    <PageHeader title="Dashboard" subtitle="Overview of CRM activity and users" />
    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-3">
      <StatCard title="Total Users" :value="summary.total_users" subtitle="All registered users" />
      <StatCard title="Active Users" :value="summary.active_users" subtitle="Currently active accounts" />
      <StatCard title="Activities Today" :value="summary.activities_today" subtitle="Audit events created today" />
    </div>
  </div>
</template>

