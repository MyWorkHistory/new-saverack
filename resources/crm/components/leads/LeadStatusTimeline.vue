<script setup>
import { computed } from "vue";
import { leadStatusLabel } from "../../constants/leads.js";
import { formatDateTimeUs, formatDateUs } from "../../utils/formatUserDates.js";

const props = defineProps({
  events: { type: Array, default: () => [] },
});

const ordered = computed(() => {
  const list = Array.isArray(props.events) ? [...props.events] : [];
  return list.sort((a, b) => Number(a.id) - Number(b.id));
});

function eventTitle(ev) {
  if (ev?.note === "Lead created") return "Lead Created";
  return leadStatusLabel(ev?.status);
}
</script>

<template>
  <div class="lead-status-timeline">
    <div
      v-for="(ev, idx) in ordered"
      :key="ev.id"
      class="lead-status-timeline__item"
      :class="{ 'lead-status-timeline__item--current': ev.is_current }"
    >
      <div class="lead-status-timeline__rail" aria-hidden="true">
        <span class="lead-status-timeline__dot" />
        <span v-if="idx < ordered.length - 1" class="lead-status-timeline__line" />
      </div>
      <div class="lead-status-timeline__body min-w-0 flex-grow-1">
        <div class="d-flex flex-wrap align-items-center gap-2 mb-1">
          <h4 class="lead-status-timeline__title mb-0">
            {{ eventTitle(ev) }}
          </h4>
          <span v-if="ev.is_current" class="lead-status-timeline__current-badge">Current</span>
        </div>
        <time class="lead-status-timeline__time small text-secondary" :datetime="ev.created_at">
          {{ formatDateUs(ev.created_at) }}
          <template v-if="ev.created_at"> · {{ formatDateTimeUs(ev.created_at) }}</template>
        </time>

        <div v-if="ev.template_name" class="lead-status-timeline__template mt-2">
          <div class="small text-secondary mb-1">Email Template: {{ ev.template_name }}</div>
          <div class="lead-status-timeline__template-card">
            <span class="lead-status-timeline__template-icon" aria-hidden="true">
              <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.75" viewBox="0 0 24 24">
                <path
                  stroke-linecap="round"
                  stroke-linejoin="round"
                  d="M21.75 6.75v10.5a2.25 2.25 0 01-2.25 2.25h-15a2.25 2.25 0 01-2.25-2.25V6.75m19.5 0A2.25 2.25 0 0019.5 4.5h-15a2.25 2.25 0 00-2.25 2.25m19.5 0v.243a2.25 2.25 0 01-1.07 1.916l-7.5 4.615a2.25 2.25 0 01-2.36 0L3.32 8.91a2.25 2.25 0 01-1.07-1.916V6.75"
                />
              </svg>
            </span>
            <div class="min-w-0">
              <div class="fw-semibold small text-body text-break">{{ ev.template_name }}</div>
              <div class="small text-secondary">
                Sent {{ formatDateTimeUs(ev.created_at) }}
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
    <p v-if="!ordered.length" class="small text-secondary mb-0">No status history yet.</p>
  </div>
</template>

<style scoped>
.lead-status-timeline__item {
  display: flex;
  gap: 0.75rem;
  position: relative;
  padding-bottom: 1.25rem;
}

.lead-status-timeline__item:last-child {
  padding-bottom: 0;
}

.lead-status-timeline__rail {
  display: flex;
  flex-direction: column;
  align-items: center;
  width: 1rem;
  flex-shrink: 0;
}

.lead-status-timeline__dot {
  width: 0.7rem;
  height: 0.7rem;
  border-radius: 999px;
  background: #94a3b8;
  border: 2px solid #e2e8f0;
  margin-top: 0.2rem;
}

.lead-status-timeline__item--current .lead-status-timeline__dot {
  background: #2563eb;
  border-color: #bfdbfe;
}

.lead-status-timeline__line {
  flex: 1;
  width: 2px;
  background: #e2e8f0;
  margin-top: 0.25rem;
  min-height: 1.5rem;
}

.lead-status-timeline__title {
  font-size: 0.9375rem;
  font-weight: 600;
  color: #0f172a;
}

.lead-status-timeline__current-badge {
  display: inline-flex;
  align-items: center;
  padding: 0.1rem 0.5rem;
  border-radius: 999px;
  font-size: 0.65rem;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0.02em;
  background: #dbeafe;
  color: #1d4ed8;
}

.lead-status-timeline__template-card {
  display: flex;
  align-items: flex-start;
  gap: 0.65rem;
  padding: 0.65rem 0.75rem;
  border: 1px solid #e2e8f0;
  border-radius: 0.5rem;
  background: #f8fafc;
}

.lead-status-timeline__template-icon {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 2rem;
  height: 2rem;
  border-radius: 0.5rem;
  background: #dbeafe;
  color: #2563eb;
  flex-shrink: 0;
}
</style>
