<script setup>
import CrmMaterialIcon from "../common/CrmMaterialIcon.vue";

const props = defineProps({
  cards: { type: Array, default: () => [] },
});

const emit = defineEmits(["select"]);

const nf = new Intl.NumberFormat(undefined, { maximumFractionDigits: 0 });

function formatCount(total) {
  return nf.format(Number(total || 0));
}

function onSelect(key) {
  emit("select", key);
}
</script>

<template>
  <div class="row g-3 on-hold-summary-cards mb-4">
    <div
      v-for="card in props.cards"
      :key="card.key"
      class="col-12 col-md-6"
    >
      <button
        type="button"
        class="staff-datatable-card staff-datatable-card--white on-hold-summary-card h-100 w-100"
        @click="onSelect(card.key)"
      >
        <div
          class="on-hold-summary-card__icon"
          :style="card.iconStyle"
          aria-hidden="true"
        >
          <CrmMaterialIcon :name="card.icon" :size="22" />
        </div>
        <div class="on-hold-summary-card__body min-w-0">
          <p class="on-hold-summary-card__title" :style="{ color: card.titleColor }">
            {{ card.titleUpper }}
          </p>
          <p class="on-hold-summary-card__sub">{{ card.sub }}</p>
        </div>
        <p class="on-hold-summary-card__value" :style="{ color: card.titleColor }">
          {{ formatCount(card.total) }}
        </p>
      </button>
    </div>
  </div>
</template>
