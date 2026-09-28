<script setup>
/**
 * Case library — the permanent instructions, grouped by suite.
 *
 * Cases carry no status. This is also the only place resolved issues are visible, as an
 * audit trail.
 */

import {computed, onMounted, ref} from 'vue';
import {RouterLink, useRouter} from 'vue-router';

import EmptyState from '../components/EmptyState.vue';
import PriorityDot from '../components/PriorityDot.vue';
import {api} from '../api/client.js';
import {PRIORITIES, issueStatusLabel} from '../utils/status.js';
import {shortDate} from '../utils/format.js';
import {useCaseStore} from '../stores/cases.js';
import {useUiStore} from '../stores/ui.js';

const router = useRouter();
const caseStore = useCaseStore();
const ui = useUiStore();

const priority = ref('');
const showArchived = ref(false);
const search = ref('');
const loading = ref(true);
const historyFor = ref(0);
const history = ref([]);
const cloning = ref(0);

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
    ui.toastError(error, wp.i18n.__('The case library could not be loaded.', 'mandragora-qa-test-manager'));
  } finally {
    loading.value = false;
  }
}

/**
 * Copies a case and opens the copy for editing, which is the only reason to clone one.
 *
 * @param {Object} item Case row.
 * @returns {Promise<void>}
 */
async function clone(item) {
  if (cloning.value) {
    return;
  }

  cloning.value = item.id;

  try {
    const copy = await caseStore.cloneCase(item.id);

    ui.toast(wp.i18n.__('Case cloned.', 'mandragora-qa-test-manager'));
    router.push(`/cases/${copy.id}/edit`);
  } catch (error) {
    ui.toastError(error, wp.i18n.__('The case could not be cloned.', 'mandragora-qa-test-manager'));
  } finally {
    cloning.value = 0;
  }
}

/**
 * Archives a case. Never a hard delete: results reference it.
 *
 * @param {Object} item Case row.
 * @returns {Promise<void>}
 */
async function archive(item) {
  const question = wp.i18n.sprintf(
    /* translators: %s: case title. */
    wp.i18n.__('Archive "%s"? It stays in past runs but cannot join new ones.', 'mandragora-qa-test-manager'),
    item.title
  );

  if (!window.confirm(question)) {
    return;
  }

  try {
    await caseStore.archiveCase(item.id);
    ui.toast(wp.i18n.__('Case archived.', 'mandragora-qa-test-manager'));
  } catch (error) {
    ui.toastError(error, wp.i18n.__('The case could not be archived.', 'mandragora-qa-test-manager'));
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
    ui.toastError(error, wp.i18n.__('The issue history could not be loaded.', 'mandragora-qa-test-manager'));
  }
}

onMounted(load);
</script>

<template>
  <div class="qa-stack">
    <div class="qa-page-head">
      <div class="qa-page-head__meta">
        <h2>{{ __('Case library', 'mandragora-qa-test-manager') }}</h2>
      </div>
      <div class="qa-row">
        <RouterLink class="qa-button qa-button--quiet" to="/suites">{{
          __('Manage suites', 'mandragora-qa-test-manager')
        }}</RouterLink>
        <RouterLink class="qa-button qa-button--primary" to="/cases/new">{{
          __('New case', 'mandragora-qa-test-manager')
        }}</RouterLink>
      </div>
    </div>

    <div class="qa-card">
      <div class="qa-card__head" style="flex-wrap: wrap">
        <div class="qa-row">
          <label class="qa-sr-only" for="case-search">{{ __('Search cases', 'mandragora-qa-test-manager') }}</label>
          <input
            id="case-search"
            v-model="search"
            class="qa-input"
            type="search"
            :placeholder="__('Search titles', 'mandragora-qa-test-manager')"
            style="width: auto"
          />

          <label class="qa-sr-only" for="case-priority">{{ __('Priority', 'mandragora-qa-test-manager') }}</label>
          <select id="case-priority" v-model="priority" class="qa-select" style="width: auto">
            <option value="">{{ __('All priorities', 'mandragora-qa-test-manager') }}</option>
            <option v-for="option in PRIORITIES" :key="option.value" :value="option.value">
              {{ option.label }}
            </option>
          </select>

          <label class="qa-checkbox">
            <input v-model="showArchived" type="checkbox" />
            <span>{{ __('Show archived', 'mandragora-qa-test-manager') }}</span>
          </label>
        </div>
      </div>

      <p v-if="loading" class="qa-skeleton">{{ __('Loading cases…', 'mandragora-qa-test-manager') }}</p>

      <EmptyState
        v-else-if="!groups.length"
        :title="__('No cases yet. Add one to start building the library.', 'mandragora-qa-test-manager')"
        :description="
          __('Cases live in suites, so create a suite first if you have none.', 'mandragora-qa-test-manager')
        "
      >
        <RouterLink class="qa-button qa-button--primary" to="/cases/new">{{
          __('New case', 'mandragora-qa-test-manager')
        }}</RouterLink>
      </EmptyState>

      <div v-for="group in groups" v-else :key="group.id" class="qa-suite-group">
        <div class="qa-card__head">
          <h3>{{ group.name }}</h3>
          <div class="qa-row">
            <span class="qa-muted qa-count">
              {{
                sprintf(
                  _n('%d case', '%d cases', group.cases.length, 'mandragora-qa-test-manager'),
                  group.cases.length
                )
              }}
            </span>
            <RouterLink
              class="qa-button qa-button--small qa-button--quiet"
              :to="`/cases/new?suite=${group.id}`"
            >
              {{ __('New case here', 'mandragora-qa-test-manager') }}
            </RouterLink>
          </div>
        </div>

        <div v-for="item in group.cases" :key="item.id">
          <div class="qa-case-row">
            <div class="qa-case-row__main">
              <div class="qa-case-row__title">
                <PriorityDot :priority="item.priority" />
                <RouterLink :to="`/cases/${item.id}/edit`">{{ item.title }}</RouterLink>
                <span v-if="!item.is_active" class="qa-badge">{{
                  __('Archived', 'mandragora-qa-test-manager')
                }}</span>
              </div>
              <div class="qa-case-row__meta">
                <span>{{
                  sprintf(__('Updated %s', 'mandragora-qa-test-manager'), shortDate(item.updated_at))
                }}</span>
              </div>
            </div>
            <div class="qa-case-row__controls">
              <button
                type="button"
                class="qa-button qa-button--small qa-button--quiet"
                @click="toggleHistory(item.id)"
              >
                {{
                  historyFor === item.id
                    ? __('Hide issues', 'mandragora-qa-test-manager')
                    : __('Issue history', 'mandragora-qa-test-manager')
                }}
              </button>
              <button
                type="button"
                class="qa-button qa-button--small qa-button--quiet"
                :disabled="cloning === item.id"
                @click="clone(item)"
              >
                {{ cloning === item.id ? __('Cloning…', 'mandragora-qa-test-manager') : __('Clone', 'mandragora-qa-test-manager') }}
              </button>
              <RouterLink class="qa-button qa-button--small" :to="`/cases/${item.id}/edit`">{{
                __('Edit', 'mandragora-qa-test-manager')
              }}</RouterLink>
              <button
                v-if="item.is_active"
                type="button"
                class="qa-button qa-button--small qa-button--danger"
                @click="archive(item)"
              >
                {{ __('Archive', 'mandragora-qa-test-manager') }}
              </button>
            </div>
          </div>

          <div v-if="historyFor === item.id" class="qa-card__body qa-stack qa-stack--tight">
            <p v-if="!history.length" class="qa-muted">
              {{ __('No issues have ever been raised on this case.', 'mandragora-qa-test-manager') }}
            </p>
            <table v-else class="qa-table">
              <thead>
                <tr>
                  <th scope="col">{{ __('Issue', 'mandragora-qa-test-manager') }}</th>
                  <th scope="col">{{ __('Status', 'mandragora-qa-test-manager') }}</th>
                  <th scope="col">{{ __('Raised', 'mandragora-qa-test-manager') }}</th>
                  <th scope="col">{{ __('Link', 'mandragora-qa-test-manager') }}</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="issue in history" :key="issue.id">
                  <td>{{ issue.title }}</td>
                  <td>
                    <span class="qa-badge">{{ issueStatusLabel(issue.status) }}</span>
                  </td>
                  <td class="qa-muted">
                    {{
                      sprintf(
                        _x('%1$s, %2$s', 'issue raiser name, date', 'mandragora-qa-test-manager'),
                        issue.created_by.name,
                        shortDate(issue.created_at)
                      )
                    }}
                  </td>
                  <td>
                    <a
                      v-if="issue.github_url"
                      :href="issue.github_url"
                      target="_blank"
                      rel="noopener noreferrer"
                    >
                      {{ __('GitHub ↗', 'mandragora-qa-test-manager') }}
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
