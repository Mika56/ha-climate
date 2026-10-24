<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import LocaleSwitcher from '@/components/LocaleSwitcher.vue'

const { t } = useI18n()
</script>

<template>
  <main>
    <h1>{{ t('app.title') }}</h1>
    <p>{{ t('app.tagline') }}</p>
    <LocaleSwitcher />
  </main>
</template>
