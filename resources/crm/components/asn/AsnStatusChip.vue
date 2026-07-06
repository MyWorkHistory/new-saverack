<script setup>
import { computed } from "vue";
import CrmMaterialIcon from "../common/CrmMaterialIcon.vue";
import { asnStatusDisplay } from "../../utils/asnStatusDisplay.js";

const props = defineProps({
  status: { type: String, default: "" },
});

const display = computed(() => asnStatusDisplay(props.status));
</script>

<template>
  <span class="asn-status-cell">
    <span class="asn-status-cell__icon" :style="display.iconStyle" aria-hidden="true">
      <CrmMaterialIcon :name="display.icon" :size="14" />
    </span>
    <span class="asn-status-cell__label" :style="{ color: display.labelColor }">
      {{ display.label }}
    </span>
  </span>
</template>
