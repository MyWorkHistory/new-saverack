<script setup>
defineProps({
  href: { type: String, default: "#" },
  label: { type: String, default: "View tutorial" },
});
</script>

<template>
  <a
    :href="href"
    class="portal-onboard-tutorial-link text-secondary"
    target="_blank"
    rel="noopener noreferrer"
    :aria-label="label"
    :title="label"
  >
    <svg
      class="portal-onboard-tutorial-link__icon"
      width="16"
      height="16"
      viewBox="0 0 24 24"
      fill="none"
      stroke="currentColor"
      stroke-width="2"
      aria-hidden="true"
    >
      <circle cx="12" cy="12" r="10" />
      <path stroke-linecap="round" d="M9.5 9.25a2.25 2.25 0 1 1 3.36 2c-.82.74-1.36 1.24-1.36 2.25V14" />
      <circle cx="12" cy="17.25" r="0.75" fill="currentColor" stroke="none" />
    </svg>
  </a>
</template>

<style scoped>
.portal-onboard-tutorial-link {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  line-height: 1;
  text-decoration: none;
}

.portal-onboard-tutorial-link:hover {
  color: var(--bs-primary) !important;
}
</style>
