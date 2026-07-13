<script setup>
import CrmMaterialIcon from "../common/CrmMaterialIcon.vue";
import { HOLD_TYPE_SECTIONS } from "../../constants/holdSummaryCards.js";

const props = defineProps({
  getTotalCount: { type: Function, required: true },
});

const emit = defineEmits(["select"]);

const nf = new Intl.NumberFormat(undefined, { maximumFractionDigits: 0 });

function formatCount(key) {
  return nf.format(Number(props.getTotalCount(key) || 0));
}

function onSelect(key) {
  emit("select", key);
}
</script>

<template>
  <div class="on-hold-summary-cards mb-4">
    <div class="row g-3">
      <div
        v-for="section in HOLD_TYPE_SECTIONS"
        :key="section.key"
        class="col-12 col-md-6 col-xl-2"
      >
        <button
          type="button"
          class="staff-datatable-card staff-datatable-card--white on-hold-summary-card h-100 w-100"
          @click="onSelect(section.key)"
        >
          <div
            class="on-hold-summary-card__icon"
            :style="section.iconStyle"
            aria-hidden="true"
          >
            <CrmMaterialIcon :name="section.icon" :size="22" />
          </div>
          <div class="on-hold-summary-card__body min-w-0">
            <p class="on-hold-summary-card__title" :style="{ color: section.titleColor }">
              {{ section.titleUpper }}
            </p>
            <p class="on-hold-summary-card__sub">{{ section.sub }}</p>
          </div>
          <p class="on-hold-summary-card__value" :style="{ color: section.titleColor }">
            {{ formatCount(section.key) }}
          </p>
        </button>
      </div>
    </div>
  </div>
</template>
