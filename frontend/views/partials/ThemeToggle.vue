<template>
  <a v-if="!theme.locked" class="fs-theme-toggle" role="button" :title="label" :aria-label="label" @click="cycle">
    <b-icon :icon="icon" size="is-small" />
  </a>
</template>

<script>
import { theme, cycleTheme } from '../../theme'

export default {
  name: 'ThemeToggle',
  data() {
    return { theme }
  },
  computed: {
    icon() {
      return { auto: 'adjust', light: 'sun', dark: 'moon' }[this.theme.mode]
    },
    label() {
      const names = { auto: 'Theme: automatic (follows your system)', light: 'Theme: light', dark: 'Theme: dark' }
      return names[this.theme.mode] + ' - click to change'
    },
  },
  methods: {
    cycle: cycleTheme,
  },
}
</script>
