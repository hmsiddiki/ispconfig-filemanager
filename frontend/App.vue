<template>
  <div v-if="$store.state.initialized" id="wrapper">
    <template v-if="is('guest') && ! can('write') && ! can('read') && ! can('upload')">
      <ThemeToggle class="fs-theme-floating" />
      <Login />
    </template>
    <div v-else id="inner">
      <router-view />
    </div>
    <span v-if="version" class="fs-version" :title="'ISPConfig File Manager ' + version">v{{ version }}</span>
  </div>
</template>

<script>
import Login from './views/Login'
import ThemeToggle from './views/partials/ThemeToggle'

export default {
  name: 'App',
  components: { Login, ThemeToggle },
  data() {
    return {
      // set at build time from package.json (vue.config.js)
      /* eslint-disable-next-line */
      version: process.env.VUE_APP_VERSION || '',
    }
  },
}
</script>

<style lang="scss">
@import "~bulma/sass/utilities/_all";

// Theme base variables
@import "assets/scss/theme/variables";

@import "~bulma";
@import "~buefy/src/scss/buefy";

// Custom styles
@import "assets/scss/custom";

/* Modern file-manager look (loaded last so it wins over the stock styles) */
@import "assets/scss/theme/modern";

/* Code editor theme */
@import "assets/scss/theme/editor";

/* The release number in the bottom right corner: small and faint, under the job cards (z-index 40) */
.fs-version {
  position: fixed;
  right: 0.5rem;
  bottom: 0.3rem;
  z-index: 30;
  font-size: 0.7rem;
  line-height: 1;
  opacity: 0.45;
  pointer-events: none;
  user-select: none;
}
</style>
