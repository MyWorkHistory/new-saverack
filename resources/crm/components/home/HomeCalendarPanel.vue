<script setup>
import CrmMaterialIcon from "../common/CrmMaterialIcon.vue";

const PLACEHOLDER_EVENTS = [
  { dateLabel: "Jul 3", title: "Inbound ASN — Acme Co", sub: "Expected delivery" },
  { dateLabel: "Jul 4", title: "Account Review — Beta LLC", sub: "Onboarding call" },
  { dateLabel: "Jul 5", title: "Restock Upload — Gamma Inc", sub: "Inventory snapshot" },
  { dateLabel: "Jul 7", title: "Shipping Hold Release", sub: "Paused account follow-up" },
];
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
      <a href="#" class="home-list-panel__header-link text-secondary" @click.prevent>View All</a>
    </div>

    <div class="home-list-panel__body">
      <div v-for="(event, index) in PLACEHOLDER_EVENTS" :key="index" class="home-list-panel__row">
        <span class="home-calendar-date">{{ event.dateLabel }}</span>
        <div class="home-list-panel__row-main min-w-0">
          <span class="home-list-panel__row-title text-truncate d-block">{{ event.title }}</span>
          <p class="home-list-panel__row-sub mb-0">{{ event.sub }}</p>
        </div>
      </div>
    </div>

    <div class="home-list-panel__footer">
      <a
        href="#"
        class="home-list-panel__footer-link home-list-panel__footer-link--disabled"
        aria-disabled="true"
        @click.prevent
      >
        View Full Calendar
      </a>
    </div>
  </section>
</template>
