<script setup>
const props = defineProps({
  open: { type: Boolean, default: false },
  name: { type: String, default: "" },
  imageUrl: { type: String, default: "" },
});

const emit = defineEmits(["close"]);
</script>

<template>
  <Teleport to="body">
    <div v-if="open" class="crm-vx-modal-overlay photo-lightbox-overlay" @click.self="emit('close')">
      <div class="photo-lightbox" @click.stop>
        <header class="photo-lightbox__head d-flex align-items-center justify-content-between gap-2">
          <h2 class="h6 mb-0 fw-semibold text-body">{{ name || "Photo" }}</h2>
          <button type="button" class="btn btn-sm btn-outline-secondary" @click="emit('close')">Close</button>
        </header>
        <div class="photo-lightbox__body">
          <img v-if="imageUrl" :src="imageUrl" :alt="name || 'Photo'" class="photo-lightbox__img" />
        </div>
      </div>
    </div>
  </Teleport>
</template>

<style scoped>
.photo-lightbox-overlay {
  align-items: center;
  justify-content: center;
  padding: 1rem;
}

.photo-lightbox {
  background: var(--bs-body-bg, #fff);
  border-radius: 0.5rem;
  max-width: min(96vw, 1200px);
  max-height: 96vh;
  display: flex;
  flex-direction: column;
  overflow: hidden;
  box-shadow: 0 1rem 3rem rgba(0, 0, 0, 0.2);
}

.photo-lightbox__head {
  padding: 0.75rem 1rem;
  border-bottom: 1px solid var(--bs-border-color, #dee2e6);
}

.photo-lightbox__body {
  padding: 1rem;
  overflow: auto;
  display: flex;
  align-items: center;
  justify-content: center;
}

.photo-lightbox__img {
  max-width: 100%;
  max-height: calc(96vh - 5rem);
  width: auto;
  height: auto;
  object-fit: contain;
}
</style>
