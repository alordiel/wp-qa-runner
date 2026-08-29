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
import {useRunStore} from '../stores/runs.js';
import {useUiStore} from '../stores/ui.js';

const runStore = useRunStore();
const ui = useUiStore();

const filter = ref('open');
const loading = ref(true);

const FILTERS = [
  {value: 'open', label: 'Open'},
  {value: 'completed', label: 'Completed'},
  {value: '', label: 'All'}
];

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
    ui.toastError(error, 'The runs could not be loaded.');
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
        <h2>Test runs</h2>
        <p class="qa-subtitle">Each run is one pass through a set of cases, at a point in time.</p>
      </div>
      <RouterLink
        v-if="bootstrap.caps?.runTests"
        class="qa-button qa-button--primary"
        to="/runs/new"
      >
        New run
      </RouterLink>
    </div>

    <div class="qa-chips" role="group" aria-label="Filter runs by status">
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
      <p v-if="loading" class="qa-skeleton">Loading runs…</p>

      <EmptyState
        v-else-if="!runStore.runs.length"
        :title="
          filter === 'open'
            ? 'No open runs. Create one to start testing.'
            : 'No runs match this filter.'
        "
      >
        <RouterLink
          v-if="bootstrap.caps?.runTests && filter === 'open'"
          class="qa-button qa-button--primary"
          to="/runs/new"
        >
          New run
        </RouterLink>
      </EmptyState>

      <div v-else class="qa-table-scroll">
        <table class="qa-table">
          <thead>
            <tr>
              <th scope="col">Run</th>
              <th scope="col">Environment</th>
              <th scope="col">Version</th>
              <th scope="col" style="min-width: 200px">Progress</th>
              <th scope="col">Assignees</th>
              <th scope="col">Created</th>
              <th scope="col">Status</th>
            </tr>
          </thead>
          <tbody>
            <tr
              v-for="run in runStore.runs"
              :key="run.id"
              :class="{'is-completed': run.status === 'completed'}"
            >
              <td>
                <RouterLink :to="`/runs/${run.id}`">{{ run.name }}</RouterLink>
              </td>
              <td>{{ run.environment }}</td>
              <td class="qa-muted">{{ run.version }}</td>
              <td><ProgressBar :counts="run.counts" /></td>
              <td><AvatarStack :people="run.assignees" /></td>
              <td class="qa-muted">{{ shortDate(run.created_at) }}</td>
              <td>
                <span class="qa-badge">{{ run.status }}</span>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</template>
