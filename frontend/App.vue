<template>
  <div v-if="$store.state.initialized" id="wrapper">
    <template v-if="is('guest') && ! can('write') && ! can('read') && ! can('upload')">
      <ThemeToggle class="fs-theme-floating" />
      <Login />
    </template>
    <div v-else id="inner">
      <router-view />
    </div>
  </div>
</template>

<script>
import Login from './views/Login'
import ThemeToggle from './views/partials/ThemeToggle'

export default {
  name: 'App',
  components: { Login, ThemeToggle }
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
</style>
