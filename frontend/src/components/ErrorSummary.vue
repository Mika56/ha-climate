<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'

const props = defineProps<{ errors: Record<string, string> }>()
const { t } = useI18n()
const entries = computed(() => Object.entries(props.errors))
</script>

<template>
  <div v-if="entries.length > 0" class="card banner tone-error stack" role="alert">
    <strong>{{ t('errors.fixFields') }}</strong>
    <ul>
      <li v-for="[path, message] in entries" :key="path">
        <code>{{ path }}</code> {{ message }}
      </li>
    </ul>
  </div>
</template>
