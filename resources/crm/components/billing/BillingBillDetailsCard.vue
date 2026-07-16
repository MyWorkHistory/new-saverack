<script setup>
defineProps({
  title: { type: String, default: "Bill Details" },
  subtitle: { type: String, default: "Overview of this bill" },
  /**
   * Field descriptors:
   * { icon, label, value?, sub?, badge?: { label, class }, link?: { to, label, targetBlank? } }
   */
  fields: { type: Array, default: () => [] },
});

const ICON_PATHS = {
  doc: "M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4",
  calendar: "M8 7V3m8 4V3M4 11h16M5 5h14a1 1 0 011 1v14a1 1 0 01-1 1H5a1 1 0 01-1-1V6a1 1 0 011-1z",
  clock: "M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z",
  user: "M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z",
  status: "M9 12l2 2 4-4M12 21a9 9 0 110-18 9 9 0 010 18z",
  building: "M3 21h18M5 21V7l8-4v18M19 21V11l-6-4M9 9v.01M9 12v.01M9 15v.01M9 18v.01",
  hash: "M7 20l4-16m2 16l4-16M6 9h14M4 15h14",
  folder: "M3 7a2 2 0 012-2h4l2 2h8a2 2 0 012 2v8a2 2 0 01-2 2H5a2 2 0 01-2-2V7z",
  box: "M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4",
  return: "M9 15L3 9m0 0l6-6M3 9h13a5 5 0 010 10h-3",
};

function iconPath(name) {
  return ICON_PATHS[name] || ICON_PATHS.doc;
}
</script>

<template>
  <div class="staff-table-card overflow-hidden bill-details-card">
    <div class="bill-details-card__head">
      <span class="bill-details-card__head-icon" aria-hidden="true">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75">
          <path stroke-linecap="round" stroke-linejoin="round" :d="iconPath('doc')" />
        </svg>
      </span>
      <div class="min-w-0">
        <h3 class="bill-details-card__title mb-0">{{ title }}</h3>
        <p class="bill-details-card__subtitle mb-0">{{ subtitle }}</p>
      </div>
    </div>
    <div class="bill-details-grid">
      <div v-for="(field, idx) in fields" :key="idx" class="bill-details-field">
        <span class="bill-details-field__icon" aria-hidden="true">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75">
            <path stroke-linecap="round" stroke-linejoin="round" :d="iconPath(field.icon)" />
          </svg>
        </span>
        <div class="min-w-0">
          <p class="bill-details-field__label">{{ field.label }}</p>
          <p v-if="field.badge" class="bill-details-field__value">
            <span class="badge rounded-pill fw-medium" :class="field.badge.class">
              {{ field.badge.label }}
            </span>
          </p>
          <p v-else class="bill-details-field__value">{{ field.value || "—" }}</p>
          <p v-if="field.sub" class="bill-details-field__sub">{{ field.sub }}</p>
          <RouterLink
            v-if="field.link"
            :to="field.link.to"
            class="bill-details-field__link small text-decoration-none"
            :target="field.link.targetBlank ? '_blank' : undefined"
            :rel="field.link.targetBlank ? 'noopener noreferrer' : undefined"
          >
            {{ field.link.label }}
          </RouterLink>
        </div>
      </div>
    </div>
  </div>
</template>
