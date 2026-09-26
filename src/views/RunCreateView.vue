<script setup>
/**
 * Run create — metadata at the top, case picker below, one screen.
 */

import {computed, onMounted, ref} from 'vue';
import {RouterLink, useRouter} from 'vue-router';

import EmptyState from '../components/EmptyState.vue';
import PriorityDot from '../components/PriorityDot.vue';
import {api} from '../api/client.js';
import {PRIORITIES} from '../utils/status.js';
import {useCaseStore} from '../stores/cases.js';
import {useUiStore} from '../stores/ui.js';

const router = useRouter();
const caseStore = useCaseStore();
const ui = useUiStore();

const form = ref({
  name: '',
  environment: 'staging',
  version: '',
  notes: ''
});

const assigneeIds = ref([]);
const selectedIds = ref([]);
const suiteFilter = ref([]);
const priorityFilter = ref([]);
const search = ref('');
const saving = ref(false);
const loading = ref(true);

const ENVIRONMENTS = window.qaRunner?.environments ?? ['local', 'staging', 'production'];

const hasFilter = computed(
  () =>
    suiteFilter.value.length > 0 || priorityFilter.value.length > 0 || search.value.trim() !== ''
);

const visibleCases = computed(() => {
  if (!hasFilter.value) {
    return [];
  }

  const term = search.value.trim().toLowerCase();

  return caseStore.activeCases.filter((item) => {
    if (suiteFilter.value.length && !suiteFilter.value.includes(item.suite_id)) {
      return false;
    }

    if (priorityFilter.value.length && !priorityFilter.value.includes(item.priority)) {
      return false;
    }

    return !term || item.title.toLowerCase().includes(term);
  });
});

const selectedCases = computed(() => {
  const byId = new Map(caseStore.cases.map((item) => [item.id, item]));

  return selectedIds.value.map((id) => byId.get(id)).filter(Boolean);
});

const canSubmit = computed(
  () =>
    form.value.name.trim() !== '' &&
    form.value.version.trim() !== '' &&
    selectedIds.value.length > 0 &&
    !saving.value
);

/**
 * Returns the array with the value added or removed.
 *
 * Kept as a plain array helper rather than one that takes a ref: top-level refs are
 * auto-unwrapped in the template, so a template handler can never hand one over.
 *
 * @param {Array} list Current values.
 * @param {*} value Value to toggle.
 * @returns {Array} A new array.
 */
function toggled(list, value) {
  return list.includes(value) ? list.filter((item) => item !== value) : [...list, value];
}

/**
 * Toggles a case in the selection.
 *
 * @param {number} id Case identifier.
 * @returns {void}
 */
function toggleCase(id) {
  selectedIds.value = toggled(selectedIds.value, id);
}

/**
 * Toggles a suite in the filter.
 *
 * @param {number} suiteId Suite identifier.
 * @returns {void}
 */
function toggleSuite(suiteId) {
  suiteFilter.value = toggled(suiteFilter.value, suiteId);
}

/**
 * Toggles a priority in the filter.
 *
 * @param {string} priority Priority value.
 * @returns {void}
 */
function togglePriority(priority) {
  priorityFilter.value = toggled(priorityFilter.value, priority);
}

/**
 * Adds every active critical case — the common smoke-run shortcut.
 *
 * @returns {void}
 */
function selectAllCritical() {
  const critical = caseStore.activeCases
    .filter((item) => item.priority === 'critical')
    .map((item) => item.id);

  selectedIds.value = [...new Set([...selectedIds.value, ...critical])];

  ui.toast(
    wp.i18n.sprintf(
      /* translators: %d: number of critical cases added. */
      wp.i18n._n(
        'Added %d critical case.',
        'Added %d critical cases.',
        critical.length,
        'qa-runner'
      ),
      critical.length
    )
  );
}

/**
 * Adds every case currently visible under the filters.
 *
 * @returns {void}
 */
function selectVisible() {
  selectedIds.value = [
    ...new Set([...selectedIds.value, ...visibleCases.value.map((item) => item.id)])
  ];
}

/**
 * Creates the run and opens it.
 *
 * @returns {Promise<void>}
 */
async function submit() {
  if (!canSubmit.value) {
    return;
  }

  saving.value = true;

  try {
    const run = await api.runs.create({
      name: form.value.name,
      environment: form.value.environment,
      version: form.value.version,
      notes: form.value.notes,
      case_ids: selectedIds.value,
      assignee_ids: assigneeIds.value
    });

    ui.toast(wp.i18n.__('Run created.', 'qa-runner'));
    router.push(`/runs/${run.id}`);
  } catch (error) {
    ui.toastError(error, wp.i18n.__('The run could not be created.', 'qa-runner'));
  } finally {
    saving.value = false;
  }
}

onMounted(async () => {
  try {
    await Promise.all([
      caseStore.loadSuites(),
      caseStore.loadUsers(),
      caseStore.loadCases({active: true})
    ]);
  } catch (error) {
    ui.toastError(error, wp.i18n.__('The case library could not be loaded.', 'qa-runner'));
  } finally {
    loading.value = false;
  }
});
</script>

<template>
  <form class="qa-stack" @submit.prevent="submit">
    <div class="qa-page-head">
      <div class="qa-page-head__meta">
        <h2>{{ __('New run', 'qa-runner') }}</h2>
        <p class="qa-subtitle">
          {{
            __(
              'Environment and version are required — a run without them cannot be audited later.',
              'qa-runner'
            )
          }}
        </p>
      </div>
      <div class="qa-row">
        <RouterLink class="qa-button qa-button--quiet" to="/">{{
          __('Cancel', 'qa-runner')
        }}</RouterLink>
        <button type="submit" class="qa-button qa-button--primary" :disabled="!canSubmit">
          {{ saving ? __('Creating…', 'qa-runner') : __('Create run', 'qa-runner') }}
        </button>
      </div>
    </div>

    <div class="qa-card">
      <div class="qa-card__body qa-stack">
        <div class="qa-field">
          <label class="qa-field__label" for="run-name">{{ __('Name', 'qa-runner') }}</label>
          <input
            id="run-name"
            v-model="form.name"
            class="qa-input"
            type="text"
            :placeholder="__('2.4.0 — Account changes', 'qa-runner')"
            required
          />
        </div>

        <div class="qa-row" style="align-items: flex-start; gap: 16px">
          <div class="qa-field" style="flex: 1; min-width: 180px">
            <label class="qa-field__label" for="run-environment">{{
              __('Environment', 'qa-runner')
            }}</label>
            <select id="run-environment" v-model="form.environment" class="qa-select">
              <option v-for="environment in ENVIRONMENTS" :key="environment" :value="environment">
                {{ environment }}
              </option>
            </select>
          </div>

          <div class="qa-field" style="flex: 1; min-width: 180px">
            <label class="qa-field__label" for="run-version">{{
              __('Version', 'qa-runner')
            }}</label>
            <input
              id="run-version"
              v-model="form.version"
              class="qa-input"
              type="text"
              :placeholder="__('2.4.0 or a commit ref', 'qa-runner')"
              required
            />
          </div>
        </div>

        <div class="qa-field">
          <label class="qa-field__label" for="run-notes">{{ __('Notes', 'qa-runner') }}</label>
          <textarea
            id="run-notes"
            v-model="form.notes"
            class="qa-textarea"
            :placeholder="
              __('What this run covers, and anything the testers should know.', 'qa-runner')
            "
          />
        </div>

        <div class="qa-field">
          <span class="qa-field__label">{{ __('Assignees', 'qa-runner') }}</span>
          <p class="qa-field__hint">
            {{
              __(
                'Assignment is informational and sends one email. Anyone on the QA team can test any case in an open run.',
                'qa-runner'
              )
            }}
          </p>
          <div class="qa-row">
            <label v-for="user in caseStore.users" :key="user.id" class="qa-checkbox">
              <input v-model="assigneeIds" type="checkbox" :value="user.id" />
              <span>{{ user.name }}</span>
            </label>
            <span v-if="!caseStore.users.length" class="qa-muted">{{
              __('No one else can run tests yet.', 'qa-runner')
            }}</span>
          </div>
        </div>
      </div>
    </div>

    <div class="qa-page-head">
      <div class="qa-page-head__meta">
        <h2>{{ __('Cases', 'qa-runner') }}</h2>
        <p class="qa-subtitle">
          {{
            sprintf(
              _n('%d case selected', '%d cases selected', selectedIds.length, 'qa-runner'),
              selectedIds.length
            )
          }}
        </p>
      </div>
      <button type="button" class="qa-button qa-button--quiet" @click="selectAllCritical">
        {{ __('Select all critical', 'qa-runner') }}
      </button>
    </div>

    <div class="qa-picker">
      <div class="qa-picker__filters">
        <div class="qa-filter-group">
          <span class="qa-filter-group__label">{{ __('Search case', 'qa-runner') }}</span>
          <input
            v-model="search"
            class="qa-input"
            type="search"
            :placeholder="__('Case title', 'qa-runner')"
          />
        </div>

        <div class="qa-filter-group">
          <span class="qa-filter-group__label">{{ __('Suite', 'qa-runner') }}</span>
          <label v-for="suite in caseStore.suites" :key="suite.id" class="qa-checkbox">
            <input
              type="checkbox"
              :checked="suiteFilter.includes(suite.id)"
              @change="toggleSuite(suite.id)"
            />
            <span
              >{{ suite.name }} <span class="qa-muted">({{ suite.case_count }})</span></span
            >
          </label>
        </div>

        <div class="qa-filter-group">
          <span class="qa-filter-group__label">{{ __('Priority', 'qa-runner') }}</span>
          <label v-for="priority in PRIORITIES" :key="priority.value" class="qa-checkbox">
            <input
              type="checkbox"
              :checked="priorityFilter.includes(priority.value)"
              @change="togglePriority(priority.value)"
            />
            <span>{{ priority.label }}</span>
          </label>
        </div>
      </div>

      <div class="qa-picker__results">
        <div class="qa-card__head">
          <span>
            {{
              sprintf(
                _n('%d case matching', '%d cases matching', visibleCases.length, 'qa-runner'),
                visibleCases.length
              )
            }}
          </span>
          <button
            type="button"
            class="qa-button qa-button--small qa-button--quiet"
            :disabled="!visibleCases.length"
            @click="selectVisible"
          >
            {{ __('Add all matching', 'qa-runner') }}
          </button>
        </div>

        <p v-if="loading" class="qa-skeleton">{{ __('Loading cases…', 'qa-runner') }}</p>

        <EmptyState
          v-else-if="!hasFilter"
          :title="__('Pick a suite to start selecting cases.', 'qa-runner')"
          :description="
            __(
              'Filter by suite, priority or title, then tick the cases this run should cover.',
              'qa-runner'
            )
          "
        />

        <EmptyState
          v-else-if="!visibleCases.length"
          :title="__('No cases match these filters.', 'qa-runner')"
          :description="__('Try widening the suite or priority selection.', 'qa-runner')"
        />

        <div v-else class="qa-picker__list">
          <label v-for="item in visibleCases" :key="item.id" class="qa-picker__item">
            <input
              type="checkbox"
              :checked="selectedIds.includes(item.id)"
              @change="toggleCase(item.id)"
            />
            <span class="qa-case-row__main">
              <span class="qa-case-row__title">
                <PriorityDot :priority="item.priority" />
                <span>{{ item.title }}</span>
              </span>
              <span class="qa-case-row__meta">{{ item.suite_name }}</span>
            </span>
          </label>
        </div>
      </div>
    </div>

    <div v-if="selectedCases.length" class="qa-card">
      <div class="qa-card__head">
        <h3>{{ __('Selected cases', 'qa-runner') }}</h3>
        <button
          type="button"
          class="qa-button qa-button--small qa-button--quiet"
          @click="selectedIds = []"
        >
          {{ __('Clear selection', 'qa-runner') }}
        </button>
      </div>
      <div class="qa-card__body qa-chips">
        <button
          v-for="item in selectedCases"
          :key="item.id"
          type="button"
          class="qa-chip is-active"
          :title="sprintf(__('Remove %s', 'qa-runner'), item.title)"
          @click="toggleCase(item.id)"
        >
          {{ item.title }} ×
        </button>
      </div>
    </div>
  </form>
</template>
