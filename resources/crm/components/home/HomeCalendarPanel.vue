<script setup>
import { onMounted, ref } from "vue";
import { RouterLink } from "vue-router";
import CrmMaterialIcon from "../common/CrmMaterialIcon.vue";
import CrmLoadingSpinner from "../common/CrmLoadingSpinner.vue";
import { useResourceCalendarEvents } from "../../composables/useResourceCalendarEvents.js";
import { parseCalendarDay } from "../../utils/formatUserDates.js";

const { loadUpcoming } = useResourceCalendarEvents();

const loading = ref(true);
const events = ref([]);

function formatDateShort(val) {
  const d = parseCalendarDay(val);
  if (!d) return "—";
  return new Intl.DateTimeFormat("en-US", { month: "short", day: "numeric" }).format(d);
}

function formatEventDateRange(event) {
  const start = formatDateShort(event.start_date);
  if (!event.end_date || event.end_date === event.start_date) {
    return start;
  }
  const end = formatDateShort(event.end_date);
  return `${start} – ${end}`;
}

onMounted(async () => {
  loading.value = true;
  try {
    events.value = await loadUpcoming(4);
  } catch {
    events.value = [];
  } finally {
    loading.value = false;
  }
});
</script>

<template>
  <section class="home-list-panel">
    <div class="home-list-panel__header">
      <div class="home-list-panel__header-left">
        <div
          class="home-list-panel__header-icon"
          style="background: #dbeafe; color: #2563eb"
          aria-hidden="true"
        >
          <CrmMaterialIcon name="calendarMonth" :size="22" />
        </div>
        <h2 class="home-list-panel__title">Calendar</h2>
      </div>
      <RouterLink to="/admin/resources/calendar" class="home-list-panel__header-link text-secondary">
        View All
      </RouterLink>
    </div>

    <div class="home-list-panel__body">
      <div v-if="loading" class="d-flex justify-content-center py-4">
        <CrmLoadingSpinner />
      </div>
      <p v-else-if="!events.length" class="text-muted small mb-0 px-1">No upcoming events.</p>
      <div v-for="event in events" :key="event.id" class="home-list-panel__row">
        <span class="home-calendar-date">{{ formatEventDateRange(event) }}</span>
        <div class="home-list-panel__row-main min-w-0">
          <span class="home-list-panel__row-title text-truncate d-block">{{ event.title }}</span>
          <p class="home-list-panel__row-sub mb-0">
            {{ event.category_label }}{{ event.is_personal ? " · Personal" : "" }}
          </p>
        </div>
      </div>
    </div>

    <div class="home-list-panel__footer">
      <RouterLink to="/admin/resources/calendar" class="home-list-panel__footer-link">
        View Full Calendar
      </RouterLink>
    </div>
  </section>
</template>
