<script setup>
import { computed, ref } from "vue";
import ConfirmModal from "../common/ConfirmModal.vue";
import { formatCalendarEventDateRange } from "../../utils/calendarEventDisplay.js";

const props = defineProps({
  open: { type: Boolean, default: false },
  event: { type: Object, default: null },
  canEdit: { type: Boolean, default: false },
  canDelete: { type: Boolean, default: false },
  deleting: { type: Boolean, default: false },
});

const emit = defineEmits(["close", "edit", "delete"]);

const deleteConfirmOpen = ref(false);

const dateLabel = computed(() => formatCalendarEventDateRange(props.event));

const creatorLabel = computed(() => {
  const name = props.event?.creator?.name;
  if (name) return name;
  return "—";
});

const categoryColor = computed(() => props.event?.category_color || "#6b7280");

function onBackdrop() {
  if (!props.deleting) emit("close");
}

function requestDelete() {
  deleteConfirmOpen.value = true;
}

function confirmDelete() {
  deleteConfirmOpen.value = false;
  emit("delete");
}
</script>

<template>
  <Teleport to="body">
    <Transition name="crm-vx-confirm">
      <div
        v-if="open && event"
        class="crm-vx-modal-overlay calendar-event-detail-overlay"
        role="dialog"
        aria-modal="true"
        aria-labelledby="calendar-event-detail-title"
        @click.self="onBackdrop"
      >
        <div class="crm-vx-modal calendar-event-detail-modal" @click.stop>
          <button
            type="button"
            class="crm-vx-modal__close"
            aria-label="Close"
            :disabled="deleting"
            @click="emit('close')"
          >
            <svg
              width="20"
              height="20"
              fill="none"
              viewBox="0 0 24 24"
              stroke="currentColor"
              stroke-width="1.75"
              aria-hidden="true"
            >
              <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
            </svg>
          </button>

          <header class="crm-vx-modal__head">
            <div class="calendar-event-detail-modal__category-row">
              <span
                class="calendar-event-detail-modal__category-dot"
                :style="{ backgroundColor: categoryColor }"
                aria-hidden="true"
              />
              <span class="calendar-event-detail-modal__category-label">
                {{ event.category_label || "Event" }}
              </span>
              <span v-if="event.is_personal" class="calendar-event-detail-modal__personal-badge">
                Personal
              </span>
            </div>
            <h2 id="calendar-event-detail-title" class="crm-vx-modal__title mb-0">
              {{ event.title }}
            </h2>
          </header>

          <div class="crm-vx-modal__body pt-0">
            <dl class="calendar-event-detail-modal__meta">
              <div class="calendar-event-detail-modal__meta-row">
                <dt>Date</dt>
                <dd>{{ dateLabel }}</dd>
              </div>
              <div class="calendar-event-detail-modal__meta-row">
                <dt>Created By</dt>
                <dd>{{ creatorLabel }}</dd>
              </div>
            </dl>

            <div v-if="event.description" class="calendar-event-detail-modal__description">
              <div class="calendar-event-detail-modal__description-label">Description</div>
              <p class="calendar-event-detail-modal__description-text mb-0">
                {{ event.description }}
              </p>
            </div>
          </div>

          <footer class="crm-vx-modal__footer">
            <button
              v-if="canDelete"
              type="button"
              class="crm-vx-modal-btn crm-vx-modal-btn--danger me-auto"
              :disabled="deleting"
              @click="requestDelete"
            >
              {{ deleting ? "Deleting…" : "Delete Event" }}
            </button>
            <button
              type="button"
              class="crm-vx-modal-btn crm-vx-modal-btn--secondary"
              :disabled="deleting"
              @click="emit('close')"
            >
              Close
            </button>
            <button
              v-if="canEdit"
              type="button"
              class="crm-vx-modal-btn crm-vx-modal-btn--primary"
              :disabled="deleting"
              @click="emit('edit')"
            >
              Edit Event
            </button>
          </footer>
        </div>
      </div>
    </Transition>
  </Teleport>

  <ConfirmModal
    :open="deleteConfirmOpen"
    title="Delete Event"
    message="Delete this calendar event? This cannot be undone."
    confirm-label="Delete Event"
    :busy="deleting"
    @close="deleteConfirmOpen = false"
    @confirm="confirmDelete"
  />
</template>

<style scoped>
.crm-vx-confirm-enter-active,
.crm-vx-confirm-leave-active {
  transition: opacity 0.2s ease;
}
.crm-vx-confirm-enter-from,
.crm-vx-confirm-leave-to {
  opacity: 0;
}
</style>
