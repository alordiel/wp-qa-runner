<script setup>
/**
 * Run list — the landing screen.
 *
 * Progress is the primary information here, so it gets the widest column and the fail
 * count is called out even when the segment is a sliver.
 */

import {onMounted, ref} from 'vue';
import {RouterLink} from 'vue-router';

import AvatarStack from '../components/AvatarStack.vue';
import EmptyState from '../components/EmptyState.vue';
import ProgressBar from '../components/ProgressBar.vue';
import {bootstrap} from '../api/client.js';
import {shortDate} from '../utils/format.js';
import {RUN_STATUSES} from '../utils/status.js';
import {useRunStore} from '../stores/runs.js';
import {useUiStore} from '../stores/ui.js';

const runStore = useRunStore();
const ui = useUiStore();

const filter = ref('open');
const loading = ref(true);

const FILTERS = [
  {value: 'open', label: wp.i18n._x('Open', 'run status', 'qa-runner')},
  {value: 'completed', label: wp.i18n.__('Completed', 'qa-runner')},
  {value: '', label: wp.i18n.__('All', 'qa-runner')}
];

/**
 * Translated label for a run status.
 *
 * @param {string} status Run status.
 * @returns {string}
 */
function runStatusLabel(status) {
  return RUN_STATUSES.find((item) => item.value === status)?.label ?? status;
}

/**
 * Loads the run list for the current filter.
 *
 * @returns {Promise<void>}
 */
async function load() {
  loading.value = true;

  try {
    await runStore.loadRuns(filter.value);
  } catch (error) {
    ui.toastError(error, wp.i18n.__('The runs could not be loaded.', 'qa-runner'));
  } finally {
    loading.value = false;
  }
}

/**
 * Switches the status filter.
 *
 * @param {string} value Filter value.
 * @returns {Promise<void>}
 */
async function setFilter(value) {
  filter.value = value;

  await load();
}

onMounted(load);
</script>

<template>
  <div class="qa-stack">
    <div class="qa-page-head">
      <div class="qa-page-head__meta">
        <h2>{{ __('Test runs', 'qa-runner') }}</h2>
      </div>
      <RouterLink
        v-if="bootstrap.caps?.runTests"
        class="qa-button qa-button--primary"
        to="/runs/new"
      >
        {{ __('New run', 'qa-runner') }}
      </RouterLink>
    </div>

    <div class="qa-chips" role="group" :aria-label="__('Filter runs by status', 'qa-runner')">
      <button
        v-for="option in FILTERS"
        :key="option.label"
        type="button"
        class="qa-chip"
        :class="{'is-active': filter === option.value}"
        :aria-pressed="filter === option.value"
        @click="setFilter(option.value)"
      >
        {{ option.label }}
      </button>
    </div>

    <div class="qa-card">
      <p v-if="loading" class="qa-skeleton">{{ __('Loading runs…', 'qa-runner') }}</p>

      <EmptyState
        v-else-if="!runStore.runs.length"
        :title="
          filter === 'open'
            ? __('No open runs. Create one to start testing.', 'qa-runner')
            : __('No runs match this filter.', 'qa-runner')
        "
      >
        <RouterLink
          v-if="bootstrap.caps?.runTests && filter === 'open'"
          class="qa-button qa-button--primary"
          to="/runs/new"
        >
          {{ __('New run', 'qa-runner') }}
        </RouterLink>
      </EmptyState>

      <div v-else class="qa-table-scroll">
        <table class="qa-table">
          <thead>
            <tr>
              <th scope="col">{{ _x('Run', 'noun', 'qa-runner') }}</th>
              <th scope="col">{{ __('Version', 'qa-runner') }}</th>
              <th scope="col" style="min-width: 200px">{{ __('Progress', 'qa-runner') }}</th>
              <th scope="col">{{ __('Assignees', 'qa-runner') }}</th>
              <th scope="col">{{ __('Created', 'qa-runner') }}</th>
              <th scope="col">{{ __('Status', 'qa-runner') }}</th>
            </tr>
          </thead>
          <tbody>
            <tr
              v-for="run in runStore.runs"
              :key="run.id"
              :class="{'is-completed': run.status === 'completed'}"
            >
              <td>
                <div class="qa-run-cell">
                  <RouterLink :to="`/runs/${run.id}`">{{ run.name }}</RouterLink>
                  <span class="qa-badge qa-badge--env">{{ run.environment }}</span>
                </div>
              </td>
              <td class="qa-muted">{{ run.version }}</td>
              <td><ProgressBar :counts="run.counts" :issues="run?.open_issue_count" /></td>
              <td><AvatarStack :people="run.assignees" /></td>
              <td class="qa-muted">{{ shortDate(run.created_at) }}</td>
              <td>
                <span class="qa-badge">{{ runStatusLabel(run.status) }}</span>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</template>
