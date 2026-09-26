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
    ui.toast(
      wp.i18n.__('You do not have permission to manage the case library.', 'qa-runner'),
      'error'
    );
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
    <h1>{{ __('QA Runner', 'qa-runner') }}</h1>

    <nav class="qa-nav" :aria-label="__('QA Runner sections', 'qa-runner')">
      <RouterLink class="qa-nav__link" active-class="is-active" to="/">{{
        __('Runs', 'qa-runner')
      }}</RouterLink>
      <RouterLink
        v-if="bootstrap.caps?.manageCases"
        class="qa-nav__link"
        active-class="is-active"
        to="/cases"
      >
        {{ __('Cases', 'qa-runner') }}
      </RouterLink>
      <RouterLink
        v-if="bootstrap.caps?.manageCases"
        class="qa-nav__link"
        active-class="is-active"
        to="/suites"
      >
        {{ __('Suites', 'qa-runner') }}
      </RouterLink>
      <span class="qa-nav__spacer" />
      <RouterLink
        v-if="bootstrap.caps?.manageCases"
        class="qa-nav__link"
        active-class="is-active"
        to="/settings"
      >
        {{ __('Settings', 'qa-runner') }}
      </RouterLink>
    </nav>

    <!--
      Nonces expire after 24 hours and testers leave tabs open overnight. The banner stays
      until they act on it: reloading mid-edit would lose whatever they were writing.
    -->
    <div v-if="ui.sessionExpired" class="qa-notice qa-notice--error" role="alert">
      <div class="qa-row">
        <span>{{ __('Your session expired. Reload the page to continue.', 'qa-runner') }}</span>
        <button type="button" class="qa-button qa-button--small" @click="reload">
          {{ __('Reload now', 'qa-runner') }}
        </button>
      </div>
    </div>

    <RouterView />

    <ToastRegion />
  </div>
</template>
