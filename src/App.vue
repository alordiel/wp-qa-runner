<script setup>
/**
 * App shell: navigation, the expired-session banner and the toast region.
 */

import {onMounted} from 'vue';
import {RouterLink, RouterView, useRoute} from 'vue-router';

import ToastRegion from './components/ToastRegion.vue';
import {bootstrap, onSessionExpired} from './api/client.js';
import {useUiStore} from './stores/ui.js';

const ui = useUiStore();
const route = useRoute();

onSessionExpired(() => ui.expireSession());

onMounted(() => {
  if (route.query.denied) {
    ui.toast('You do not have permission to manage the case library.', 'error');
  }
});

/**
 * Reloads the page once the tester is ready.
 *
 * @returns {void}
 */
function reload() {
  window.location.reload();
}
</script>

<template>
  <div class="qa-shell">
    <h1>QA Runner</h1>

    <nav class="qa-nav" aria-label="QA Runner sections">
      <RouterLink class="qa-nav__link" active-class="is-active" to="/">Runs</RouterLink>
      <RouterLink
        v-if="bootstrap.caps?.manageCases"
        class="qa-nav__link"
        active-class="is-active"
        to="/cases"
      >
        Cases
      </RouterLink>
      <RouterLink
        v-if="bootstrap.caps?.manageCases"
        class="qa-nav__link"
        active-class="is-active"
        to="/suites"
      >
        Suites
      </RouterLink>
      <span class="qa-nav__spacer" />
      <RouterLink
        v-if="bootstrap.caps?.manageCases"
        class="qa-nav__link"
        active-class="is-active"
        to="/settings"
      >
        Settings
      </RouterLink>
    </nav>

    <!--
      Nonces expire after 24 hours and testers leave tabs open overnight. The banner stays
      until they act on it: reloading mid-edit would lose whatever they were writing.
    -->
    <div v-if="ui.sessionExpired" class="qa-notice qa-notice--error" role="alert">
      <div class="qa-row">
        <span>Your session expired. Reload the page to continue.</span>
        <button type="button" class="qa-button qa-button--small" @click="reload">Reload now</button>
      </div>
    </div>

    <RouterView />

    <ToastRegion />
  </div>
</template>
