<script setup>
import { computed } from "vue";
import telegramIconUrl from "@public/images/telegram-icon.png";
import { inHouseSlackHref, slackChannelHref } from "../../utils/slackChannel.js";

/**
 * Channel indicators: email, Telegram, WhatsApp, Slack (client and/or in-house URL).
 */
const props = defineProps({
  telegramTitle: { type: String, default: "Telegram" },
  emailTitle: { type: String, default: "Email" },
  whatsappTitle: { type: String, default: "WhatsApp" },
  slackTitle: { type: String, default: "Slack" },
  notifyEmail: { type: Boolean, default: false },
  telegramHandle: { type: String, default: "" },
  whatsappE164: { type: String, default: "" },
  slackChannel: { type: String, default: "" },
  inHouseSlack: { type: String, default: "" },
  sizeClass: { type: String, default: "h-5 w-5" },
});

/** Prefer client Slack URL; else in-house app_redirect (or legacy URL). */
const slackIconHref = computed(() => {
  const client = slackChannelHref(props.slackChannel);
  if (client) return client;
  return inHouseSlackHref(props.inHouseSlack);
});
</script>

<template>
  <div class="flex items-center gap-2 text-gray-500 dark:text-gray-400">
    <span v-if="notifyEmail" class="inline-flex text-violet-600 dark:text-violet-400" :title="emailTitle">
      <svg
        :class="sizeClass"
        fill="none"
        viewBox="0 0 24 24"
        stroke="currentColor"
        stroke-width="2"
        aria-hidden="true"
      >
        <path
          stroke-linecap="round"
          stroke-linejoin="round"
          d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"
        />
      </svg>
    </span>
    <span
      v-if="telegramHandle"
      class="inline-flex shrink-0 items-center justify-center bg-transparent"
      :title="telegramTitle"
    >
      <img
        :src="telegramIconUrl"
        alt=""
        :class="[sizeClass, 'object-contain bg-transparent']"
        width="20"
        height="20"
        decoding="async"
        draggable="false"
      />
    </span>
    <span
      v-if="whatsappE164"
      class="inline-flex text-emerald-600 dark:text-emerald-400"
      :title="whatsappTitle"
    >
      <svg :class="sizeClass" fill="currentColor" viewBox="0 0 24 24" aria-hidden="true">
        <path
          d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.435 9.884-9.881 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413z"
        />
      </svg>
    </span>
    <a
      v-if="(slackChannel || inHouseSlack) && slackIconHref"
      class="inline-flex shrink-0 text-[#4A154B] dark:text-[#e01e5a] text-decoration-none"
      :href="slackIconHref"
      :title="slackTitle"
      :aria-label="`${slackTitle} (opens in new tab)`"
      target="_blank"
      rel="noopener noreferrer"
    >
      <svg :class="sizeClass" fill="currentColor" viewBox="0 0 24 24" aria-hidden="true">
        <path
          d="M5.042 15.165a2.528 2.528 0 0 1-2.52 2.523A2.528 2.528 0 0 1 0 15.165a2.527 2.527 0 0 1 2.522-2.52h2.52v2.52zM6.313 15.165a2.527 2.527 0 0 1 2.521-2.52 2.527 2.527 0 0 1 2.521 2.52v6.313A2.528 2.528 0 0 1 8.834 24a2.528 2.528 0 0 1-2.521-2.522v-6.313zM8.834 5.042a2.528 2.528 0 0 1-2.521-2.52A2.528 2.528 0 0 1 8.834 0a2.528 2.528 0 0 1 2.521 2.522v2.52H8.834V5.042zm0 1.27a2.528 2.528 0 0 1 2.521 2.521 2.528 2.528 0 0 1-2.521 2.521H2.522A2.528 2.528 0 0 1 0 8.834a2.528 2.528 0 0 1 2.522-2.521h6.312zm9.124 2.521a2.528 2.528 0 0 1 2.52-2.521A2.528 2.528 0 0 1 24 8.834a2.528 2.528 0 0 1-2.522 2.521h-2.522V8.834zm-1.269 0a2.528 2.528 0 0 1-2.523 2.521 2.527 2.527 0 0 1-2.52-2.521V2.522A2.527 2.527 0 0 1 15.165 0a2.528 2.528 0 0 1 2.523 2.522v6.312zm-2.523 9.124a2.528 2.528 0 0 1 2.523 2.52A2.528 2.528 0 0 1 15.165 24a2.528 2.528 0 0 1-2.523-2.522v-2.52h2.523zm0-1.268a2.527 2.527 0 0 1-2.52-2.523 2.526 2.526 0 0 1 2.52-2.52h6.313A2.527 2.527 0 0 1 24 15.165a2.528 2.528 0 0 1-2.522 2.523h-6.313z"
        />
      </svg>
    </a>
    <span
      v-else-if="slackChannel || inHouseSlack"
      class="inline-flex text-[#4A154B] dark:text-[#e01e5a]"
      :title="slackTitle"
    >
      <svg :class="sizeClass" fill="currentColor" viewBox="0 0 24 24" aria-hidden="true">
        <path
          d="M5.042 15.165a2.528 2.528 0 0 1-2.52 2.523A2.528 2.528 0 0 1 0 15.165a2.527 2.527 0 0 1 2.522-2.52h2.52v2.52zM6.313 15.165a2.527 2.527 0 0 1 2.521-2.52 2.527 2.527 0 0 1 2.521 2.52v6.313A2.528 2.528 0 0 1 8.834 24a2.528 2.528 0 0 1-2.521-2.522v-6.313zM8.834 5.042a2.528 2.528 0 0 1-2.521-2.52A2.528 2.528 0 0 1 8.834 0a2.528 2.528 0 0 1 2.521 2.522v2.52H8.834V5.042zm0 1.27a2.528 2.528 0 0 1 2.521 2.521 2.528 2.528 0 0 1-2.521 2.521H2.522A2.528 2.528 0 0 1 0 8.834a2.528 2.528 0 0 1 2.522-2.521h6.312zm9.124 2.521a2.528 2.528 0 0 1 2.52-2.521A2.528 2.528 0 0 1 24 8.834a2.528 2.528 0 0 1-2.522 2.521h-2.522V8.834zm-1.269 0a2.528 2.528 0 0 1-2.523 2.521 2.527 2.527 0 0 1-2.52-2.521V2.522A2.527 2.527 0 0 1 15.165 0a2.528 2.528 0 0 1 2.523 2.522v6.312zm-2.523 9.124a2.528 2.528 0 0 1 2.523 2.52A2.528 2.528 0 0 1 15.165 24a2.528 2.528 0 0 1-2.523-2.522v-2.52h2.523zm0-1.268a2.527 2.527 0 0 1-2.52-2.523 2.526 2.526 0 0 1 2.52-2.52h6.313A2.527 2.527 0 0 1 24 15.165a2.528 2.528 0 0 1-2.522 2.523h-6.313z"
        />
      </svg>
    </span>
    <span
      v-if="!notifyEmail && !telegramHandle && !whatsappE164 && !slackChannel && !inHouseSlack"
      class="text-gray-400"
      >—</span
    >
  </div>
</template>
