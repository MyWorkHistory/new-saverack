<script setup>
import { computed, onMounted, watch } from "vue";
import {
  FULFILLMENT_SIGNATURE_STYLES,
  ensureFulfillmentSignatureFonts,
  fulfillmentSignatureStyleById,
} from "../../constants/fulfillmentAgreementSignatures.js";

const props = defineProps({
  modelValue: { type: String, default: "dancing_script" },
  signatureText: { type: String, default: "" },
});

const emit = defineEmits(["update:modelValue"]);

onMounted(() => {
  ensureFulfillmentSignatureFonts();
});

watch(
  () => props.modelValue,
  () => ensureFulfillmentSignatureFonts(),
);

const previewStyle = computed(() => {
  const style = fulfillmentSignatureStyleById(props.modelValue);
  return { fontFamily: style.fontFamily };
});

const previewText = computed(() => {
  const t = String(props.signatureText || "").trim();
  return t || "Sign Here";
});

function selectStyle(id) {
  emit("update:modelValue", id);
}
</script>

<template>
  <div class="fa-sig-picker">
    <div class="fa-sig-picker__preview" :style="previewStyle" aria-hidden="true">
      {{ previewText }}
    </div>
    <div class="fa-sig-picker__styles" role="listbox" aria-label="Signature style">
      <button
        v-for="style in FULFILLMENT_SIGNATURE_STYLES"
        :key="style.id"
        type="button"
        class="fa-sig-picker__style"
        :class="{ 'fa-sig-picker__style--active': modelValue === style.id }"
        role="option"
        :aria-selected="modelValue === style.id"
        :style="{ fontFamily: style.fontFamily }"
        @click="selectStyle(style.id)"
      >
        {{ style.label }}
      </button>
    </div>
  </div>
</template>

<style scoped>
.fa-sig-picker__preview {
  min-height: 4.5rem;
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 0.75rem 1rem;
  border: 1px solid #d8d6de;
  border-radius: 0.5rem;
  background: #fafafa;
  font-size: 2rem;
  line-height: 1.2;
  color: #1a1a1a;
  margin-bottom: 0.75rem;
}
.fa-sig-picker__styles {
  display: flex;
  flex-wrap: wrap;
  gap: 0.5rem;
}
.fa-sig-picker__style {
  border: 1px solid #d8d6de;
  background: #fff;
  border-radius: 0.375rem;
  padding: 0.35rem 0.65rem;
  font-size: 1.05rem;
  line-height: 1.2;
  color: #4b4b4b;
  cursor: pointer;
}
.fa-sig-picker__style--active {
  border-color: #1e3a8a;
  color: #1e3a8a;
  box-shadow: 0 0 0 1px #1e3a8a;
}
</style>
