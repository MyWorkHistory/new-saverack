<script setup>
import { computed } from "vue";
import CrmMaterialIcon from "../common/CrmMaterialIcon.vue";
import { ltlStatusDisplay } from "../../utils/ltlStatusDisplay.js";

const props = defineProps({
  status: { type: String, default: "" },
  /** When true, show a caret (clickable status on detail). */
  caret: { type: Boolean, default: false },
});

const display = computed(() => ltlStatusDisplay(props.status));
</script>

<template>
  <span class="asn-status-cell">
    <span class="asn-status-cell__icon" :style="display.iconStyle" aria-hidden="true">
      <CrmMaterialIcon :name="display.icon" :size="14" />
    </span>
    <span class="asn-status-cell__label" :style="{ color: display.labelColor }">
      {{ display.label }}
    </span>
    <span
      v-if="caret"
      class="asn-status-cell__label"
      :style="{ color: display.labelColor }"
      aria-hidden="true"
    >▾</span>
  </span>
</template>
