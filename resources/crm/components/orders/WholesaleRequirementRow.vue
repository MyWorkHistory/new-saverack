<script setup>
import CrmMaterialIcon from "../common/CrmMaterialIcon.vue";

defineProps({
  icon: { type: String, required: true },
  iconStyle: { type: Object, default: () => ({}) },
  label: { type: String, required: true },
  valueLabel: { type: String, default: "" },
  comment: { type: String, default: "" },
  editable: { type: Boolean, default: false },
});

const emit = defineEmits(["edit"]);
</script>

<template>
  <div class="wholesale-req-row">
    <div class="wholesale-req-row__icon" :style="iconStyle" aria-hidden="true">
      <CrmMaterialIcon :name="icon" :size="20" />
    </div>
    <div class="wholesale-req-row__body min-w-0 flex-grow-1">
      <div class="wholesale-req-row__line">
        <span class="wholesale-req-row__label">{{ label }}:</span>
        <span class="wholesale-req-row__value">{{ valueLabel || "—" }}</span>
      </div>
      <p v-if="comment" class="wholesale-req-row__comment mb-0">{{ comment }}</p>
    </div>
    <button
      v-if="editable"
      type="button"
      class="btn btn-link btn-sm p-0 text-decoration-none flex-shrink-0 wholesale-req-row__edit"
      @click="emit('edit')"
    >
      Edit
    </button>
  </div>
</template>

<style scoped>
.wholesale-req-row {
  display: flex;
  align-items: flex-start;
  gap: 0.75rem;
  padding: 0.75rem 0;
}

.wholesale-req-row + .wholesale-req-row {
  border-top: 1px solid var(--bs-border-color);
}

.wholesale-req-row__icon {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
  width: 2.25rem;
  height: 2.25rem;
  border-radius: 0.4375rem;
}

.wholesale-req-row__line {
  font-size: 0.875rem;
  line-height: 1.4;
}

.wholesale-req-row__label {
  font-weight: 700;
  margin-right: 0.35rem;
}

.wholesale-req-row__comment {
  margin-top: 0.25rem;
  font-size: 0.75rem;
  color: var(--bs-secondary-color);
  line-height: 1.35;
  white-space: pre-wrap;
}
</style>
