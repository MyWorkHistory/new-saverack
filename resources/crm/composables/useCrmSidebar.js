import { computed, ref } from "vue";

const isExpanded = ref(true);
const isMobileOpen = ref(false);

export function useCrmSidebar() {
  function toggleSidebar() {
    if (typeof window !== "undefined" && window.innerWidth >= 1024) {
      isExpanded.value = !isExpanded.value;
    } else {
      isMobileOpen.value = !isMobileOpen.value;
    }
  }

  function toggleMobileSidebar() {
    isMobileOpen.value = !isMobileOpen.value;
  }

  function closeMobile() {
    isMobileOpen.value = false;
  }

  const sidebarWidthClass = computed(() =>
    isExpanded.value ? "lg:w-[290px]" : "lg:w-[90px]",
  );

  const mainMarginClass = computed(() =>
    isExpanded.value ? "lg:ml-[290px]" : "lg:ml-[90px]",
  );

  return {
    isExpanded,
    isMobileOpen,
    toggleSidebar,
    toggleMobileSidebar,
    closeMobile,
    sidebarWidthClass,
    mainMarginClass,
  };
}
