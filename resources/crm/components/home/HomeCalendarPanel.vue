<script setup>
import { computed, inject, onMounted, ref } from "vue";
import { RouterLink } from "vue-router";
import CrmMaterialIcon from "../common/CrmMaterialIcon.vue";
import CrmLoadingSpinner from "../common/CrmLoadingSpinner.vue";
import CalendarEventDetailModal from "../resources/CalendarEventDetailModal.vue";
import CalendarEventDrawer from "../resources/CalendarEventDrawer.vue";
import { useResourceCalendarEvents } from "../../composables/useResourceCalendarEvents.js";
import {
  calendarColorWash,
  calendarEventDateParts,
} from "../../utils/calendarEventDisplay.js";
import { canManageCalendarEvent } from "../../utils/calendarEventPermissions.js";
import { crmIsAdmin } from "../../utils/crmUser.js";

const crmUser = inject("crmUser", ref(null));

const {
  saving,
  deleting,
  categories,
  loadMeta,
  loadUpcoming,
  updateEvent,
  deleteEvent,
} = useResourceCalendarEvents();

const loading = ref(true);
const events = ref([]);
const detailOpen = ref(false);
const detailEvent = ref(null);
const drawerOpen = ref(false);
const editingEvent = ref(null);

const detailCanEdit = computed(() => canManageCalendarEvent(crmUser.value, detailEvent.value));
const detailCanDelete = computed(() => canManageCalendarEvent(crmUser.value, detailEvent.value));
const drawerCanSave = computed(() => canManageCalendarEvent(crmUser.value, editingEvent.value));
const drawerCanDelete = computed(() => canManageCalendarEvent(crmUser.value, editingEvent.value));

function userHasPerm(key) {
  const u = crmUser.value;
  if (!u) return false;
  if (crmIsAdmin(u) || u.is_crm_owner) return true;
  return Array.isArray(u.permission_keys) && u.permission_keys.includes(key);
}

function eventAccentColor(event) {
  if (event?.is_personal) {
    return "#6b7280";
  }
  return event?.category_color || "#6b7280";
}

function eventDateParts(event) {
  return calendarEventDateParts(event);
}

function eventDateWidgetStyle(event) {
  const color = eventAccentColor(event);
  return {
    color,
    background: calendarColorWash(color, 0.14),
  };
}

function eventRuleStyle(event) {
  return { background: eventAccentColor(event) };
}

function eventCategoryLabel(event) {
  const label = String(event?.category_label || "").trim();
  if (event?.is_personal) {
    return label ? `${label} · Personal` : "Personal";
  }
  return label || "—";
}

async function refreshEvents() {
  loading.value = true;
  try {
    events.value = await loadUpcoming(4);
  } catch {
    events.value = [];
  } finally {
    loading.value = false;
  }
}

function openDetail(event) {
  detailEvent.value = event;
  detailOpen.value = true;
}

function closeDetail() {
  detailOpen.value = false;
  detailEvent.value = null;
}

function openEditDrawer(event) {
  editingEvent.value = event;
  drawerOpen.value = true;
}

function onDetailEdit() {
  const ev = detailEvent.value;
  if (!ev) return;
  closeDetail();
  openEditDrawer(ev);
}

async function onDetailDelete() {
  if (!detailEvent.value?.id) return;
  try {
    await deleteEvent(detailEvent.value.id);
    closeDetail();
    await refreshEvents();
  } catch {
    /* toast handled */
  }
}

async function onDrawerSave(payload) {
  if (!editingEvent.value?.id) return;
  try {
    await updateEvent(editingEvent.value.id, payload);
    drawerOpen.value = false;
    editingEvent.value = null;
    await refreshEvents();
  } catch {
    /* toast handled */
  }
}

async function onDrawerDelete() {
  if (!editingEvent.value?.id) return;
  try {
    await deleteEvent(editingEvent.value.id);
    drawerOpen.value = false;
    editingEvent.value = null;
    await refreshEvents();
  } catch {
    /* toast handled */
  }
}

onMounted(async () => {
  try {
    if (userHasPerm("resources.view")) {
      await loadMeta();
    }
  } catch {
    /* optional */
  }
  await refreshEvents();
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

    <div class="home-list-panel__body home-list-panel__body--calendar">
      <div v-if="loading" class="d-flex justify-content-center py-4">
        <CrmLoadingSpinner />
      </div>
      <p v-else-if="!events.length" class="text-muted small mb-0 px-1">No upcoming events.</p>
      <div v-else class="home-calendar-list">
        <button
          v-for="event in events"
          :key="event.id"
          type="button"
          class="home-calendar-event"
          @click="openDetail(event)"
        >
          <div class="home-calendar-event__date" :style="eventDateWidgetStyle(event)">
            <span class="home-calendar-event__month">{{ eventDateParts(event).month }}</span>
            <span class="home-calendar-event__day">{{ eventDateParts(event).day }}</span>
          </div>
          <span
            class="home-calendar-event__rule"
            :style="eventRuleStyle(event)"
            aria-hidden="true"
          />
          <div class="home-calendar-event__text min-w-0">
            <span class="home-calendar-event__title">{{ event.title }}</span>
            <span class="home-calendar-event__category">{{ eventCategoryLabel(event) }}</span>
          </div>
        </button>
      </div>
    </div>

    <div class="home-list-panel__footer">
      <RouterLink to="/admin/resources/calendar" class="home-list-panel__footer-link">
        View Full Calendar
      </RouterLink>
    </div>

    <CalendarEventDetailModal
      :open="detailOpen"
      :event="detailEvent"
      :can-edit="detailCanEdit"
      :can-delete="detailCanDelete"
      :deleting="deleting"
      @close="closeDetail"
      @edit="onDetailEdit"
      @delete="onDetailDelete"
    />

    <CalendarEventDrawer
      v-model:open="drawerOpen"
      mode="edit"
      :event="editingEvent"
      :categories="categories"
      :initial-start-date="editingEvent?.start_date || ''"
      :initial-end-date="editingEvent?.end_date || ''"
      :can-save="drawerCanSave"
      :can-delete="drawerCanDelete"
      :busy="saving"
      :deleting="deleting"
      @save="onDrawerSave"
      @delete="onDrawerDelete"
    />
  </section>
</template>
