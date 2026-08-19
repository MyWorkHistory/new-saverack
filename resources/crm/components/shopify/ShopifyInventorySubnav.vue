<script setup>
const props = defineProps({
  productsActive: { type: Boolean, default: false },
  locationsActive: { type: Boolean, default: false },
});
</script>

<template>
  <div class="shopify-inv-subnav mb-3">
    <RouterLink
      to="/admin/shopify/inventory"
      class="shopify-inv-subnav__link"
      :class="{ 'is-active': productsActive }"
    >
      Products
    </RouterLink>
    <RouterLink
      to="/admin/shopify/locations"
      class="shopify-inv-subnav__link"
      :class="{ 'is-active': locationsActive }"
    >
      Locations
    </RouterLink>
  </div>
</template>

<style scoped>
.shopify-inv-subnav {
  display: inline-flex;
  gap: 0.25rem;
  padding: 0.2rem;
  border: 1px solid rgba(15, 23, 42, 0.1);
  border-radius: 0.65rem;
  background: #fff;
}
.shopify-inv-subnav__link {
  padding: 0.4rem 0.9rem;
  border-radius: 0.5rem;
  font-size: 0.875rem;
  font-weight: 600;
  color: #64748b;
  text-decoration: none;
}
.shopify-inv-subnav__link:hover {
  color: #1e293b;
  background: rgba(37, 99, 235, 0.06);
}
.shopify-inv-subnav__link.is-active {
  color: #1d4ed8;
  background: rgba(37, 99, 235, 0.12);
}
</style>
