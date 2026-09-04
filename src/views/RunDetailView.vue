<script setup>
/**
 * Run detail — the main working screen.
 *
 * The case list is grouped by suite and every row carries its own status control, so a
 * forty-case run can be worked through without leaving this screen.
 */

import {computed, onBeforeUnmount, onMounted, ref, watch} from 'vue';
import {RouterLink} from 'vue-router';

import AssigneeDialog from '../components/AssigneeDialog.vue';
import AvatarStack from '../components/AvatarStack.vue';
import EmptyState from '../components/EmptyState.vue';
import PriorityDot from '../components/PriorityDot.vue';
import ProgressBar from '../components/ProgressBar.vue';
import RunDetailsDialog from '../components/RunDetailsDialog.vue';
import StatusBadge from '../components/StatusBadge.vue';
import StatusControl from '../components/StatusControl.vue';
import {api, bootstrap} from '../api/client.js';
import {absoluteTime, plural, relativeTime} from '../utils/format.js';
import {PRIORITIES, RESULT_STATUSES, statusLabel} from '../utils/status.js';
import {useCaseStore} from '../stores/cases.js';
import {useRunStore} from '../stores/runs.js';
import {useUiStore} from '../stores/ui.js';

const props = defineProps({
  id: {type: [String, Number], required: true}
});

const runStore = useRunStore();
const caseStore = useCaseStore();
const ui = useUiStore();

const runId = computed(() => Number(props.id));
const loading = ref(true);
const cloning = ref(false);
const runAssigneeDialogOpen = ref(false);
const savingRunAssignees = ref(false);
const runDetailsDialogOpen = ref(false);
const savingRunDetails = ref(false);

const filters = ref({
  status: '',
  suite: '',
  priority: '',
  onlyMine: false,
  onlyUnassigned: false,
  onlyFailedLastRun: false
});

const isOpen = computed(() => runStore.run?.status === 'open');
const canTest = computed(() => Boolean(bootstrap.caps?.runTests) && isOpen.value);

/**
 * Whether this tester was put on the run, and may therefore claim its cases. Managers are
 * included so they can tidy up a board they oversee without joining the run.
 */
const canAssignSelf = computed(
  () =>
    canTest.value &&
    (Boolean(bootstrap.caps?.manageCases) ||
      (runStore.run?.assignees ?? []).some((person) => person.id === bootstrap.currentUser?.id))
);

const filtered = computed(() =>
  runStore.results.filter((result) => {
    if (filters.value.status && result.status !== filters.value.status) {
      return false;
    }

    if (filters.value.suite && String(result.case.suite_id) !== filters.value.suite) {
      return false;
    }

    if (filters.value.priority && result.case.priority !== filters.value.priority) {
      return false;
    }

    if (filters.value.onlyMine && result.tested_by?.id !== bootstrap.currentUser?.id) {
      return false;
    }

    if (filters.value.onlyUnassigned && (result.assignees?.length ?? 0) > 0) {
      return false;
    }

    if (
      filters.value.onlyFailedLastRun &&
      runStore.previousStatus[result.case.id]?.status !== 'fail'
    ) {
      return false;
    }

    return true;
  })
);

/**
 * The filtered results, grouped by suite in display order.
 */
const groups = computed(() => {
  const bySuite = new Map();

  filtered.value.forEach((result) => {
    const key = result.case.suite_id;

    if (!bySuite.has(key)) {
      bySuite.set(key, {id: key, name: result.case.suite_name, results: []});
    }

    bySuite.get(key).results.push(result);
  });

  return [...bySuite.values()];
});

const regressions = computed(() =>
  runStore.results.filter(
    (result) =>
      result.status === 'fail' && runStore.previousStatus[result.case.id]?.status === 'pass'
  )
);

const hasFilters = computed(
  () =>
    filters.value.status !== '' ||
    filters.value.suite !== '' ||
    filters.value.priority !== '' ||
    filters.value.onlyMine ||
    filters.value.onlyUnassigned ||
    filters.value.onlyFailedLastRun
);

/**
 * Loads the run, its results and the previous-run comparison.
 *
 * @returns {Promise<void>}
 */
async function load() {
  loading.value = true;

  try {
    await runStore.loadRun(runId.value);
    await Promise.all([
      runStore.loadPreviousStatus(runId.value),
      caseStore.loadSuites(),
      caseStore.loadUsers()
    ]);
    runStore.startPolling(runId.value);
  } catch (error) {
    ui.toastError(error, 'This run could not be loaded.');
  } finally {
    loading.value = false;
  }
}

/**
 * Sets a result status optimistically.
 *
 * @param {Object} result Result row.
 * @param {string} status New status.
 * @returns {Promise<void>}
 */
async function setStatus(result, status) {
  try {
    await runStore.setStatus(result.id, status);
  } catch (error) {
    ui.toastError(error, 'That result could not be saved.');
  }
}

/**
 * Saves the edited run metadata.
 *
 * @param {Object} changes Changed fields only, as {name, environment, version, notes}.
 * @returns {Promise<void>}
 */
async function saveRunDetails(changes) {
  savingRunDetails.value = true;

  try {
    await runStore.updateRun(runId.value, changes);
    runDetailsDialogOpen.value = false;
    ui.toast('Run details updated.');
  } catch (error) {
    // The dialog stays open on failure, so the draft is still there to retry or cancel.
    ui.toastError(error, 'The run details could not be saved.');
  } finally {
    savingRunDetails.value = false;
  }
}

/**
 * Applies the dialog's draft to the run.
 *
 * @param {Object} changes People to add and remove, as {add: [], remove: []}.
 * @returns {Promise<void>}
 */
async function saveRunAssignees(changes) {
  savingRunAssignees.value = true;

  try {
    await runStore.setRunAssignees(runId.value, changes);
    runAssigneeDialogOpen.value = false;
    ui.toast('Run assignees updated.');
  } catch (error) {
    // The dialog stays open on failure, so the draft is still there to retry or cancel.
    ui.toastError(error, 'The run assignees could not be saved.');
  } finally {
    savingRunAssignees.value = false;
  }
}

/**
 * Claims or releases one case for the current tester.
 *
 * @param {Object} result Result row.
 * @returns {Promise<void>}
 */
async function toggleAssignment(result) {
  try {
    await runStore.setAssignment(result.id, bootstrap.currentUser, !isMine(result));
  } catch (error) {
    ui.toastError(error, 'That assignment could not be saved.');
  }
}

/**
 * Whether the current tester holds this case.
 *
 * @param {Object} result Result row.
 * @returns {boolean}
 */
function isMine(result) {
  return (result.assignees ?? []).some((person) => person.id === bootstrap.currentUser?.id);
}

/**
 * Completes the run. Always an explicit action — testers revisit results, so nothing
 * completes a run on its own when the last case is set.
 *
 * @returns {Promise<void>}
 */
async function completeRun() {
  try {
    await runStore.updateRun(runId.value, {status: 'completed'});
    runStore.stopPolling();
    ui.toast('Run completed.');
  } catch (error) {
    ui.toastError(error, 'The run could not be completed.');
  }
}

/**
 * Reopens a completed run so results can be edited again.
 *
 * @returns {Promise<void>}
 */
async function reopenRun() {
  try {
    await runStore.updateRun(runId.value, {status: 'open'});
    runStore.startPolling(runId.value);
    ui.toast('Run reopened.');
  } catch (error) {
    ui.toastError(error, 'The run could not be reopened.');
  }
}

/**
 * Clones the run into a fresh one with the same case selection.
 *
 * @returns {Promise<void>}
 */
async function cloneRun() {
  cloning.value = true;

  try {
    const clone = await api.runs.clone(runId.value);

    ui.toast('Run cloned.');
    window.location.hash = `#/runs/${clone.id}`;
  } catch (error) {
    ui.toastError(error, 'The run could not be cloned.');
  } finally {
    cloning.value = false;
  }
}

/**
 * Clears every list filter.
 *
 * @returns {void}
 */
function clearFilters() {
  filters.value = {
    status: '',
    suite: '',
    priority: '',
    onlyMine: false,
    onlyUnassigned: false,
    onlyFailedLastRun: false
  };
}

watch(runId, load);

onMounted(load);

onBeforeUnmount(() => runStore.reset());
</script>

<template>
  <div class="qa-stack">
    <p v-if="loading" class="qa-skeleton">Loading run…</p>

    <template v-else-if="runStore.run">
      <div class="qa-page-head">
        <div class="qa-page-head__meta">
          <h2 class="qa-run-title">{{ runStore.run.name }}</h2>
          <p class="qa-subtitle">
            environment: <strong>{{ runStore.run.environment }}</strong> · version: <strong>{{ runStore.run.version }}</strong> <br> created by
            <strong>{{ runStore.run.created_by.name }}</strong> &nbsp;
            <strong> <span :title="absoluteTime(runStore.run.created_at)">{{
              relativeTime(runStore.run.created_at)
            }}</span> </strong>
          </p>
          <p v-if="runStore.run.notes" class="qa-run-description">{{ runStore.run.notes }}</p>
        </div>

        <div class="qa-row">
          <button
            v-if="bootstrap.caps?.runTests"
            type="button"
            class="qa-button qa-button--quiet"
            @click="runDetailsDialogOpen = true"
          >
            Edit details
          </button>
          <button
            v-if="bootstrap.caps?.runTests"
            type="button"
            class="qa-button qa-button--quiet"
            :disabled="cloning"
            @click="cloneRun"
          >
            {{ cloning ? 'Cloning…' : 'Clone run' }}
          </button>
          <button
            v-if="bootstrap.caps?.runTests && isOpen"
            type="button"
            class="qa-button qa-button--primary"
            @click="completeRun"
          >
            Complete run
          </button>
          <button
            v-else-if="bootstrap.caps?.runTests && runStore.run.status === 'completed'"
            type="button"
            class="qa-button"
            @click="reopenRun"
          >
            Reopen run
          </button>
        </div>

        <RunDetailsDialog
          :open="runDetailsDialogOpen"
          :run="runStore.run"
          :saving="savingRunDetails"
          @close="runDetailsDialogOpen = false"
          @save="saveRunDetails"
        />
      </div>

      <div class="qa-card">
        <div class="qa-card__body qa-row" style="justify-content: space-between; gap: 24px">
          <ProgressBar :counts="runStore.run.counts" />
          <div class="qa-row" style="gap: 16px">
            <span class="qa-badge">{{ runStore.run.status }}</span>

            <AvatarStack :people="runStore.run.assignees" />

            <button
              v-if="bootstrap.caps?.runTests"
              type="button"
              class="qa-button qa-button--small"
              @click="runAssigneeDialogOpen = true"
            >
              Edit assignees
            </button>
          </div>
        </div>

        <AssigneeDialog
          :open="runAssigneeDialogOpen"
          title="Who is on this run"
          empty-text="No testers exist yet. Give somebody the QA Tester role first."
          removal-warning="Removing %s also drops the cases they claimed on this run. Adding them back will not restore those claims."
          :candidates="caseStore.users"
          :assigned="runStore.run.assignees"
          :saving="savingRunAssignees"
          @close="runAssigneeDialogOpen = false"
          @save="saveRunAssignees"
        />
      </div>

      <div v-if="!isOpen" class="qa-notice qa-notice--warning">
        This run is {{ runStore.run.status }}. Results, comments and locks are read-only.
      </div>

      <div v-if="regressions.length" class="qa-notice qa-notice--error">
        <strong>{{ regressions.length }} {{ plural(regressions.length, 'regression') }}.</strong>
        {{ plural(regressions.length, 'This case passed', 'These cases passed') }} in the previous
        run and {{ plural(regressions.length, 'fails', 'fail') }} now:
        {{ regressions.map((result) => result.case.title).join(', ') }}
      </div>

      <div class="qa-card">
        <div class="qa-card__head" style="flex-wrap: wrap">
          <div class="qa-row">
            <label class="qa-sr-only" for="filter-status">Status</label>
            <select
              id="filter-status"
              v-model="filters.status"
              class="qa-select"
              style="width: auto"
            >
              <option value="">All statuses</option>
              <option v-for="status in RESULT_STATUSES" :key="status.value" :value="status.value">
                {{ statusLabel(status.value) }}
              </option>
            </select>

            <label class="qa-sr-only" for="filter-suite">Suite</label>
            <select id="filter-suite" v-model="filters.suite" class="qa-select" style="width: auto">
              <option value="">All suites</option>
              <option v-for="suite in caseStore.suites" :key="suite.id" :value="String(suite.id)">
                {{ suite.name }}
              </option>
            </select>

            <label class="qa-sr-only" for="filter-priority">Priority</label>
            <select
              id="filter-priority"
              v-model="filters.priority"
              class="qa-select"
              style="width: auto"
            >
              <option value="">All priorities</option>
              <option v-for="priority in PRIORITIES" :key="priority.value" :value="priority.value">
                {{ priority.label }}
              </option>
            </select>

            <label class="qa-checkbox">
              <input v-model="filters.onlyMine" type="checkbox" />
              <span>Only mine</span>
            </label>

            <label class="qa-checkbox">
              <input v-model="filters.onlyUnassigned" type="checkbox" />
              <span>Unassigned only</span>
            </label>

            <label class="qa-checkbox">
              <input v-model="filters.onlyFailedLastRun" type="checkbox" />
              <span>Only failed last run</span>
            </label>
          </div>

          <button
            v-if="hasFilters"
            type="button"
            class="qa-button qa-button--small qa-button--quiet"
            @click="clearFilters"
          >
            Clear filters
          </button>
        </div>

        <EmptyState
          v-if="!filtered.length"
          :title="hasFilters ? 'No cases match these filters.' : 'This run has no cases yet.'"
          :description="hasFilters ? 'Clear a filter to see more of the run.' : ''"
        />

        <div v-for="group in groups" :key="group.id" class="qa-suite-group">
          <div class="qa-card__head">
            <h3>{{ group.name }}</h3>
            <span class="qa-muted qa-count">
              {{ group.results.length }} {{ plural(group.results.length, 'case') }}
            </span>
          </div>

          <div v-for="result in group.results" :key="result.id" class="qa-case-row">
            <div class="qa-case-row__main">
              <div class="qa-case-row__title">
                <PriorityDot :priority="result.case.priority" />
                <RouterLink :to="`/runs/${runId}/cases/${result.case.id}`">
                  {{ result.case.title }}
                </RouterLink>
              </div>
              <div class="qa-case-row__meta">
                <AvatarStack v-if="result.assignees?.length" :people="result.assignees" />

                <span v-if="result.tested_by">
                  {{ result.tested_by.name }}
                  <span :title="absoluteTime(result.tested_at)">{{
                    relativeTime(result.tested_at)
                  }}</span>
                </span>
                <span v-else>Not tested yet</span>

                <span v-if="result.comment_count" class="qa-badge">
                  {{ result.comment_count }} {{ plural(result.comment_count, 'comment') }}
                </span>

                <span v-if="result.open_issue_count" class="qa-badge qa-badge--issue">
                  {{ result.open_issue_count }} open {{ plural(result.open_issue_count, 'issue') }}
                </span>

                <span
                  v-if="
                    result.in_progress_by && result.in_progress_by.id !== bootstrap.currentUser?.id
                  "
                  class="qa-badge qa-badge--lock"
                >
                  {{ result.in_progress_by.name }} is testing this
                </span>

                <span
                  v-if="runStore.previousStatus[result.case.id]"
                  class="qa-muted"
                  :title="`Previous run: ${runStore.previousStatus[result.case.id].run_name}`"
                >
                  Last run: {{ statusLabel(runStore.previousStatus[result.case.id].status) }}
                </span>
              </div>
            </div>

            <div class="qa-case-row__controls">
              <button
                v-if="canAssignSelf"
                type="button"
                class="qa-button qa-button--small qa-button--quiet"
                :title="isMine(result) ? 'Take yourself off this case' : 'Claim this case'"
                @click="toggleAssignment(result)"
              >
                {{ isMine(result) ? 'Unassign me' : 'Assign me' }}
              </button>

              <StatusControl
                v-if="canTest"
                :model-value="result.status"
                :case-title="result.case.title"
                @update:model-value="setStatus(result, $event)"
              />
              <StatusBadge v-else :status="result.status" />
            </div>
          </div>
        </div>
      </div>
    </template>

    <EmptyState v-else title="That run could not be found." description="It may have been deleted.">
      <RouterLink class="qa-button" to="/">Back to runs</RouterLink>
    </EmptyState>
  </div>
</template>
