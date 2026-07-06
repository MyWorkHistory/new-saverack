<script setup>
import { computed } from "vue";

const props = defineProps({
  status: { type: String, default: "" },
});

function statusLabel(s) {
  const x = String(s || "").toLowerCase();
  if (x === "draft") return "Draft";
  if (x === "pending") return "Pending";
  if (x === "in_progress") return "In Progress";
  if (x === "completed") return "Completed";
  if (x === "non_compliant") return "Non-Compliant";
  return s || "—";
}

const normalizedStatus = computed(() => String(props.status || "").toLowerCase() || "pending");

const chipClass = computed(() => `asn-status-chip--${normalizedStatus.value.replace(/-/g, "_")}`);
</script>

<template>
  <span class="asn-status-chip" :class="chipClass">
    <span class="asn-status-chip__dot" aria-hidden="true" />
    {{ statusLabel(status) }}
  </span>
</template>
