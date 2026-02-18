<template>
  <router-view />
</template>

<script setup>
// Auth check is handled by the router guard (router/index.js)
// to avoid a race condition with double fetchUser calls
</script>
