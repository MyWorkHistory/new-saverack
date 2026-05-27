import { computed, ref } from "vue";
import { formatDateTimeUs } from "../utils/formatUserDates.js";

/**
 * Client-side "last refreshed" timestamp for portal pages that load ShipHero-backed data.
 */
export function usePortalLastRefreshed() {
  const lastRefreshedAt = ref(null);

  function markRefreshed(sourceTimestamp = null) {
    if (typeof sourceTimestamp === "string" && sourceTimestamp.trim()) {
      const parsed = new Date(sourceTimestamp);
      if (!Number.isNaN(parsed.getTime())) {
        lastRefreshedAt.value = parsed;
        return;
      }
    }
    lastRefreshedAt.value = new Date();
  }

  const lastRefreshedLabel = computed(() => {
    if (!lastRefreshedAt.value) return null;
    return formatDateTimeUs(lastRefreshedAt.value);
  });

  return { lastRefreshedAt, markRefreshed, lastRefreshedLabel };
}
