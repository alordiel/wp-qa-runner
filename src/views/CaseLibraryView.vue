<script setup>
/**
 * Case library — the permanent instructions, grouped by suite.
 *
 * Cases carry no status. This is also the only place resolved issues are visible, as an
 * audit trail.
 */

import {computed, onMounted, ref} from 'vue';
import {RouterLink} from 'vue-router';

import EmptyState from '../components/EmptyState.vue';
import PriorityDot from '../components/PriorityDot.vue';
import {api} from '../api/client.js';
import {PRIORITIES} from '../utils/status.js';
import {plural, shortDate} from '../utils/format.js';
import {useCaseStore} from '../stores/cases.js';
import {useUiStore} from '../stores/ui.js';

const caseStore = useCaseStore();
const ui = useUiStore();

const priority = ref('');
const showArchived = ref(false);
const search = ref('');
const loading = ref(true);
const historyFor = ref(0);
const history = ref([]);

const groups = computed(() => {
  const term = search.value.trim().toLowerCase();

  const visible = caseStore.cases.filter((item) => {
    if (!showArchived.value && !item.is_active) {
      return false;
    }

    if (priority.value && item.priority !== priority.value) {
      return false;
    }

    return !term || item.title.toLowerCase().includes(term);
  });

  const bySuite = new Map();

  caseStore.suites.forEach((suite) => {
    bySuite.set(suite.id, {id: suite.id, name: suite.name, cases: []});
  });

  visible.forEach((item) => {
    if (!bySuite.has(item.suite_id)) {
      bySuite.set(item.suite_id, {id: item.suite_id, name: item.suite_name, cases: []});
    }

    bySuite.get(item.suite_id).cases.push(item);
  });

  return [...bySuite.values()].filter((group) => group.cases.length > 0);
});

/**
 * Loads suites and the full case library.
 *
 * @returns {Promise<void>}
 */
async function load() {
  loading.value = true;

  try {
    await Promise.all([caseStore.loadSuites(), caseStore.loadCases()]);
  } catch (error) {
    ui.toastError(error, 'The case library could not be loaded.');
  } finally {
    loading.value = false;
  }
}

/**
 * Archives a case. Never a hard delete: results reference it.
 *
 * @param {Object} item Case row.
 * @returns {Promise<void>}
 */
async function archive(item) {
  if (!window.confirm(`Archive "${item.title}"? It stays in past runs but cannot join new ones.`)) {
    return;
  }

  try {
    await caseStore.archiveCase(item.id);
    ui.toast('Case archived.');
  } catch (error) {
    ui.toastError(error, 'The case could not be archived.');
  }
}

/**
 * Loads the full issue history for a case — the audit view, never shown while testing.
 *
 * @param {number} id Case identifier.
 * @returns {Promise<void>}
 */
async function toggleHistory(id) {
  if (historyFor.value === id) {
    historyFor.value = 0;

    return;
  }

  try {
    history.value = await api.cases.issues(id, 'all');
    historyFor.value = id;
  } catch (error) {
    ui.toastError(error, 'The issue history could not be loaded.');
  }
}

onMounted(load);
</script>

<template>
  <div class="qa-stack">
    <div class="qa-page-head">
      <div class="qa-page-head__meta">
        <h2>Case library</h2>
        <p class="qa-subtitle">
          Permanent instructions. A case has no status of its own — only results do.
        </p>
      </div>
      <div class="qa-row">
        <RouterLink class="qa-button qa-button--quiet" to="/suites">Manage suites</RouterLink>
        <RouterLink class="qa-button qa-button--primary" to="/cases/new">New case</RouterLink>
      </div>
    </div>

    <div class="qa-card">
      <div class="qa-card__head" style="flex-wrap: wrap">
        <div class="qa-row">
          <label class="qa-sr-only" for="case-search">Search cases</label>
          <input
            id="case-search"
            v-model="search"
            class="qa-input"
            type="search"
            placeholder="Search titles"
            style="width: auto"
          />

          <label class="qa-sr-only" for="case-priority">Priority</label>
          <select id="case-priority" v-model="priority" class="qa-select" style="width: auto">
            <option value="">All priorities</option>
            <option v-for="option in PRIORITIES" :key="option.value" :value="option.value">
              {{ option.label }}
            </option>
          </select>

          <label class="qa-checkbox">
            <input v-model="showArchived" type="checkbox" />
            <span>Show archived</span>
          </label>
        </div>
      </div>

      <p v-if="loading" class="qa-skeleton">Loading cases…</p>

      <EmptyState
        v-else-if="!groups.length"
        title="No cases yet. Add one to start building the library."
        description="Cases live in suites, so create a suite first if you have none."
      >
        <RouterLink class="qa-button qa-button--primary" to="/cases/new">New case</RouterLink>
      </EmptyState>

      <div v-for="group in groups" v-else :key="group.id" class="qa-suite-group">
        <div class="qa-card__head">
          <h3>{{ group.name }}</h3>
          <div class="qa-row">
            <span class="qa-muted qa-count">
              {{ group.cases.length }} {{ plural(group.cases.length, 'case') }}
            </span>
            <RouterLink
              class="qa-button qa-button--small qa-button--quiet"
              :to="`/cases/new?suite=${group.id}`"
            >
              New case here
            </RouterLink>
          </div>
        </div>

        <div v-for="item in group.cases" :key="item.id">
          <div class="qa-case-row">
            <div class="qa-case-row__main">
              <div class="qa-case-row__title">
                <PriorityDot :priority="item.priority" />
                <RouterLink :to="`/cases/${item.id}/edit`">{{ item.title }}</RouterLink>
                <span v-if="!item.is_active" class="qa-badge">Archived</span>
              </div>
              <div class="qa-case-row__meta">
                <span>Updated {{ shortDate(item.updated_at) }}</span>
              </div>
            </div>
            <div class="qa-case-row__controls">
              <button
                type="button"
                class="qa-button qa-button--small qa-button--quiet"
                @click="toggleHistory(item.id)"
              >
                {{ historyFor === item.id ? 'Hide issues' : 'Issue history' }}
              </button>
              <RouterLink class="qa-button qa-button--small" :to="`/cases/${item.id}/edit`"
                >Edit</RouterLink
              >
              <button
                v-if="item.is_active"
                type="button"
                class="qa-button qa-button--small qa-button--danger"
                @click="archive(item)"
              >
                Archive
              </button>
            </div>
          </div>

          <div v-if="historyFor === item.id" class="qa-card__body qa-stack qa-stack--tight">
            <p v-if="!history.length" class="qa-muted">
              No issues have ever been raised on this case.
            </p>
            <table v-else class="qa-table">
              <thead>
                <tr>
                  <th scope="col">Issue</th>
                  <th scope="col">Status</th>
                  <th scope="col">Raised</th>
                  <th scope="col">Link</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="issue in history" :key="issue.id">
                  <td>{{ issue.title }}</td>
                  <td>
                    <span class="qa-badge">{{ issue.status }}</span>
                  </td>
                  <td class="qa-muted">
                    {{ issue.created_by.name }}, {{ shortDate(issue.created_at) }}
                  </td>
                  <td>
                    <a
                      v-if="issue.github_url"
                      :href="issue.github_url"
                      target="_blank"
                      rel="noopener noreferrer"
                    >
                      GitHub ↗
                    </a>
                    <span v-else class="qa-muted">—</span>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>
