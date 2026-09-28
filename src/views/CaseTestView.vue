<script setup>
/**
 * Case test view.
 *
 * Open issues sit directly under the status control, above the comments: knowing what is
 * currently broken on this case is the reason the tool exists, and burying it below a
 * comment thread would defeat that.
 */

import {computed, onBeforeUnmount, onMounted, ref, watch} from 'vue';
import {RouterLink, useRouter} from 'vue-router';

import AssigneeDialog from '../components/AssigneeDialog.vue';
import EmptyState from '../components/EmptyState.vue';
import PriorityDot from '../components/PriorityDot.vue';
import RichTextEditor from '../components/RichTextEditor.vue';
import StatusBadge from '../components/StatusBadge.vue';
import StatusControl from '../components/StatusControl.vue';
import {api, bootstrap} from '../api/client.js';
import {absoluteTime, relativeTime} from '../utils/format.js';
import {RUN_STATUSES, statusLabel} from '../utils/status.js';
import {useRunStore} from '../stores/runs.js';
import {useUiStore} from '../stores/ui.js';

const props = defineProps({
  id: {type: [String, Number], required: true},
  caseId: {type: [String, Number], required: true}
});

const router = useRouter();
const runStore = useRunStore();
const ui = useUiStore();

const runId = computed(() => Number(props.id));
const caseId = computed(() => Number(props.caseId));

const testCase = ref(null);
const issues = ref([]);
const resolvedIssues = ref([]);
const comments = ref([]);
const loading = ref(true);

const commentDraft = ref('');
const postingComment = ref(false);

const replyingToId = ref(0);
const replyDraft = ref('');
const postingReply = ref(false);

// Matches CommentRepository::MAX_DEPTH: a comment, a reply, and a reply to that reply.
const MAX_COMMENT_DEPTH = 3;

const editingCommentId = ref(0);
const editCommentDraft = ref('');
const savingCommentEdit = ref(false);

const issueFormOpen = ref(false);
const issueDraft = ref({title: '', description: '', github_url: ''});
const savingIssue = ref(false);

const resolvingId = ref(0);
const resolutionNote = ref('');

// History stays collapsed until asked for: what is still broken is the reason for the
// screen, and a list of everything ever wrong with the case would drown it.
const historyOpen = ref(false);

const savingAssignment = ref(false);
const assignDialogOpen = ref(false);
const savingAssignees = ref(false);

const result = computed(() => runStore.resultsByCaseId[caseId.value] ?? null);
const isOpen = computed(() => runStore.run?.status === 'open');
const canTest = computed(() => Boolean(bootstrap.caps?.runTests) && isOpen.value);

/**
 * Comments in reading order — each reply directly under its parent, oldest first — with
 * the depth each one sits at. A reply whose parent is missing is shown at the top level.
 */
const threadedComments = computed(() => {
  const ids = new Set(comments.value.map((comment) => comment.id));
  const children = new Map();

  for (const comment of comments.value) {
    const parentId = ids.has(comment.parent_id) ? comment.parent_id : 0;

    if (!children.has(parentId)) {
      children.set(parentId, []);
    }

    children.get(parentId).push(comment);
  }

  const ordered = [];
  const walk = (parentId, depth) => {
    for (const comment of children.get(parentId) ?? []) {
      ordered.push({comment, depth});
      walk(comment.id, depth + 1);
    }
  };

  walk(0, 1);

  return ordered;
});

const position = computed(() => runStore.orderedCaseIds.indexOf(caseId.value));
const previousCaseId = computed(() =>
  position.value > 0 ? runStore.orderedCaseIds[position.value - 1] : null
);
const nextCaseId = computed(() =>
  position.value >= 0 && position.value < runStore.orderedCaseIds.length - 1
    ? runStore.orderedCaseIds[position.value + 1]
    : null
);

const previousRun = computed(() => runStore.previousStatus[caseId.value] ?? null);

const lockedByOther = computed(
  () => result.value?.in_progress_by && result.value.in_progress_by.id !== bootstrap.currentUser?.id
);

const assignees = computed(() => result.value?.assignees ?? []);

const assignedToMe = computed(() =>
  assignees.value.some((person) => person.id === bootstrap.currentUser?.id)
);

/**
 * Whether this tester was put on the run, and may therefore hand out its cases — to
 * themselves or to anyone else on it. Managers are included so they can tidy up a board
 * they oversee without adding themselves to the run.
 */
const canAssign = computed(
  () =>
    canTest.value &&
    (Boolean(bootstrap.caps?.manageCases) ||
      (runStore.run?.assignees ?? []).some((person) => person.id === bootstrap.currentUser?.id))
);

/**
 * The people this case can be handed to: whoever is on the run.
 *
 * Assignment is scoped to the run rather than to every tester on the site, so the picker
 * never offers somebody who would have no business seeing the case.
 */
const candidates = computed(() => runStore.run?.assignees ?? []);

/**
 * Applies the dialog's draft to this case.
 *
 * Sequential rather than parallel: each write returns the whole result row, so overlapping
 * them would let an earlier response land last and drop a later change.
 *
 * @param {Object} changes People to add and remove, as {add: [], remove: []}.
 * @returns {Promise<void>}
 */
async function saveAssignees({add, remove}) {
  savingAssignees.value = true;

  try {
    for (const person of add) {
      await runStore.setAssignment(result.value.id, person, true);
    }

    for (const person of remove) {
      await runStore.setAssignment(result.value.id, person, false);
    }

    assignDialogOpen.value = false;
    ui.toast(wp.i18n.__('Assignees updated.', 'mandragora-qa-test-manager'));
  } catch (error) {
    // The dialog stays open on failure, so the draft is still there to retry or cancel.
    ui.toastError(error, wp.i18n.__('Those assignments could not be saved.', 'mandragora-qa-test-manager'));
  } finally {
    savingAssignees.value = false;
  }
}

/**
 * Whether this tester may take a given person off the case.
 *
 * @param {Object} person Assignee.
 * @returns {boolean}
 */
function canUnassign(person) {
  return canTest.value && (person.id === bootstrap.currentUser?.id || canAssign.value);
}

/**
 * Claims or releases this case for the current tester.
 *
 * @returns {Promise<void>}
 */
async function toggleSelf() {
  savingAssignment.value = true;

  try {
    await runStore.setAssignment(result.value.id, bootstrap.currentUser, !assignedToMe.value);
    ui.toast(
      assignedToMe.value
        ? wp.i18n.__('Assigned to you.', 'mandragora-qa-test-manager')
        : wp.i18n.__('You are off this case.', 'mandragora-qa-test-manager')
    );
  } catch (error) {
    ui.toastError(error, wp.i18n.__('That assignment could not be saved.', 'mandragora-qa-test-manager'));
  } finally {
    savingAssignment.value = false;
  }
}

/**
 * Takes one tester off this case.
 *
 * @param {Object} person Assignee.
 * @returns {Promise<void>}
 */
async function removeAssignee(person) {
  try {
    await runStore.setAssignment(result.value.id, person, false);
  } catch (error) {
    ui.toastError(error, wp.i18n.__('That assignment could not be removed.', 'mandragora-qa-test-manager'));
  }
}

/**
 * Loads the case, its issues and this run's comments.
 *
 * @returns {Promise<void>}
 */
async function loadCase() {
  loading.value = true;
  // Browser history can change the case under an open dialog, which would leave it showing
  // the previous case's assignees.
  assignDialogOpen.value = false;
  historyOpen.value = false;

  try {
    if (!runStore.run || runStore.run.id !== runId.value) {
      await runStore.loadRun(runId.value);
      await runStore.loadPreviousStatus(runId.value);
    }

    const payload = await api.cases.get(caseId.value);

    testCase.value = payload;
    issues.value = payload.issues ?? [];
    resolvedIssues.value = payload.resolved_issues ?? [];

    if (result.value) {
      comments.value = await api.results.comments(result.value.id);

      if (canTest.value) {
        // The lock is passive: it renders as a label for other testers and never blocks a
        // second submission.
        await api.results.lock(result.value.id).catch(() => {});
      }
    }
  } catch (error) {
    ui.toastError(error, wp.i18n.__('This case could not be loaded.', 'mandragora-qa-test-manager'));
  } finally {
    loading.value = false;
  }
}

/**
 * Releases the lock this tester holds.
 *
 * @returns {void}
 */
function releaseLock() {
  if (result.value && canTest.value) {
    api.results.unlock(result.value.id).catch(() => {});
  }
}

/**
 * Sets the result status.
 *
 * @param {string} status New status.
 * @returns {Promise<void>}
 */
async function setStatus(status) {
  try {
    await runStore.setStatus(result.value.id, status);
  } catch (error) {
    ui.toastError(error, wp.i18n.__('That result could not be saved.', 'mandragora-qa-test-manager'));
  }
}

/**
 * Posts a comment. Not optimistic — comments wait for the server.
 *
 * @returns {Promise<void>}
 */
async function postComment() {
  const content = commentDraft.value.trim();

  if (!content || content === '<p><br></p>') {
    return;
  }

  postingComment.value = true;

  try {
    const comment = await api.results.addComment(result.value.id, content);

    comments.value = [...comments.value, comment];
    commentDraft.value = '';
    runStore.replaceResult({...result.value, comment_count: result.value.comment_count + 1});
    ui.toast(wp.i18n.__('Comment added.', 'mandragora-qa-test-manager'));
  } catch (error) {
    ui.toastError(error, wp.i18n.__('The comment could not be added.', 'mandragora-qa-test-manager'));
  } finally {
    postingComment.value = false;
  }
}

/**
 * Opens the reply form under a comment, closing any open edit.
 *
 * @param {Object} comment Comment being replied to.
 * @returns {void}
 */
function startReply(comment) {
  cancelEditComment();
  replyingToId.value = comment.id;
  replyDraft.value = '';
}

/**
 * Closes the reply form without posting.
 *
 * @returns {void}
 */
function cancelReply() {
  replyingToId.value = 0;
  replyDraft.value = '';
}

/**
 * Posts the reply being written.
 *
 * @returns {Promise<void>}
 */
async function postReply() {
  const content = replyDraft.value.trim();

  if (!content || content === '<p><br></p>') {
    return;
  }

  postingReply.value = true;

  try {
    const comment = await api.results.addComment(result.value.id, content, replyingToId.value);

    comments.value = [...comments.value, comment];
    cancelReply();
    runStore.replaceResult({...result.value, comment_count: result.value.comment_count + 1});
    ui.toast(wp.i18n.__('Reply added.', 'mandragora-qa-test-manager'));
  } catch (error) {
    ui.toastError(error, wp.i18n.__('The reply could not be added.', 'mandragora-qa-test-manager'));
  } finally {
    postingReply.value = false;
  }
}

/**
 * Opens the inline editor for a comment.
 *
 * @param {Object} comment Comment row.
 * @returns {void}
 */
function startEditComment(comment) {
  cancelReply();
  editingCommentId.value = comment.id;
  editCommentDraft.value = comment.content;
}

/**
 * Closes the inline comment editor without saving.
 *
 * @returns {void}
 */
function cancelEditComment() {
  editingCommentId.value = 0;
  editCommentDraft.value = '';
}

/**
 * Saves the comment being edited.
 *
 * @returns {Promise<void>}
 */
async function saveCommentEdit() {
  const content = editCommentDraft.value.trim();

  if (!content || content === '<p><br></p>') {
    return;
  }

  savingCommentEdit.value = true;

  try {
    const updated = await api.comments.update(editingCommentId.value, content);

    comments.value = comments.value.map((item) => (item.id === updated.id ? updated : item));
    cancelEditComment();
    ui.toast(wp.i18n.__('Comment updated.', 'mandragora-qa-test-manager'));
  } catch (error) {
    ui.toastError(error, wp.i18n.__('The comment could not be updated.', 'mandragora-qa-test-manager'));
  } finally {
    savingCommentEdit.value = false;
  }
}

/**
 * Deletes a comment.
 *
 * @param {Object} comment Comment row.
 * @returns {Promise<void>}
 */
async function deleteComment(comment) {
  const hasReplies = comments.value.some((item) => item.parent_id === comment.id);
  const question = hasReplies
    ? wp.i18n.__('Delete this comment and all of its replies?', 'mandragora-qa-test-manager')
    : wp.i18n.__('Delete this comment?', 'mandragora-qa-test-manager');

  if (!window.confirm(question)) {
    return;
  }

  try {
    const response = await api.comments.remove(comment.id);
    const deletedIds = new Set(response?.deleted_ids ?? [comment.id]);

    comments.value = comments.value.filter((item) => !deletedIds.has(item.id));

    if (deletedIds.has(replyingToId.value)) {
      cancelReply();
    }

    if (deletedIds.has(editingCommentId.value)) {
      cancelEditComment();
    }

    runStore.replaceResult({
      ...result.value,
      comment_count: Math.max(0, result.value.comment_count - deletedIds.size)
    });
    ui.toast(wp.i18n.__('Comment deleted.', 'mandragora-qa-test-manager'));
  } catch (error) {
    ui.toastError(error, wp.i18n.__('The comment could not be deleted.', 'mandragora-qa-test-manager'));
  }
}

/**
 * Raises an issue against this case.
 *
 * @returns {Promise<void>}
 */
async function raiseIssue() {
  if (!issueDraft.value.title.trim()) {
    return;
  }

  savingIssue.value = true;

  try {
    const issue = await api.cases.raiseIssue(caseId.value, {
      title: issueDraft.value.title,
      description: issueDraft.value.description,
      github_url: issueDraft.value.github_url,
      origin_run_id: runId.value
    });

    issues.value = [issue, ...issues.value];
    issueDraft.value = {title: '', description: '', github_url: ''};
    issueFormOpen.value = false;

    if (result.value) {
      runStore.replaceResult({
        ...result.value,
        open_issue_count: result.value.open_issue_count + 1
      });
    }

    ui.toast(wp.i18n.__('Issue raised.', 'mandragora-qa-test-manager'));
  } catch (error) {
    ui.toastError(error, wp.i18n.__('The issue could not be raised.', 'mandragora-qa-test-manager'));
  } finally {
    savingIssue.value = false;
  }
}

/**
 * Resolves or closes an issue, which removes its banner from every later run and moves it
 * into this case's issue history.
 *
 * @param {Object} issue Issue row.
 * @param {'resolved'|'wontfix'} status New status.
 * @returns {Promise<void>}
 */
async function resolveIssue(issue, status) {
  try {
    const resolved = await api.issues.update(issue.id, {
      status,
      resolution_note: resolutionNote.value
    });

    issues.value = issues.value.filter((item) => item.id !== issue.id);
    // Into the history rather than out of sight, so the tester can still see who closed it
    // and why without reloading the case.
    resolvedIssues.value = [resolved, ...resolvedIssues.value];
    resolvingId.value = 0;
    resolutionNote.value = '';

    if (result.value) {
      runStore.replaceResult({
        ...result.value,
        open_issue_count: Math.max(0, result.value.open_issue_count - 1)
      });
    }

    ui.toast(
      status === 'resolved'
        ? wp.i18n.__('Issue resolved.', 'mandragora-qa-test-manager')
        : wp.i18n.__('Issue closed as won’t fix.', 'mandragora-qa-test-manager')
    );
  } catch (error) {
    ui.toastError(error, wp.i18n.__('The issue could not be updated.', 'mandragora-qa-test-manager'));
  }
}

/**
 * How an issue was closed, for the history list.
 *
 * @param {Object} issue Issue row.
 * @returns {string}
 */
function closureLabel(issue) {
  return issue.status === 'wontfix'
    ? wp.i18n.__('Won’t fix', 'mandragora-qa-test-manager')
    : wp.i18n.__('Resolved', 'mandragora-qa-test-manager');
}

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
 * Moves to another case in this run.
 *
 * @param {number|null} target Case identifier.
 * @returns {void}
 */
function go(target) {
  if (target) {
    releaseLock();
    router.push(`/runs/${runId.value}/cases/${target}`);
  }
}

watch(caseId, loadCase);

onMounted(loadCase);

onBeforeUnmount(releaseLock);
</script>

<template>
  <div class="qa-stack">
    <p v-if="loading" class="qa-skeleton">{{ __('Loading case…', 'mandragora-qa-test-manager') }}</p>

    <template v-else-if="testCase">
      <div class="qa-page-head">
        <div class="qa-page-head__meta">
          <RouterLink :to="`/runs/${runId}`" class="qa-subtitle">
            {{ __('← Back to run', 'mandragora-qa-test-manager') }}
          </RouterLink>
          <h2 class="qa-row">
            <PriorityDot :priority="testCase.priority" />
            <span>{{
              sprintf(
                _x('%1$s → %2$s', 'suite name → case title', 'mandragora-qa-test-manager'),
                testCase.suite_name,
                testCase.title
              )
            }}</span>
          </h2>
        </div>

        <div class="qa-row">
          <button
            type="button"
            class="qa-button qa-button--quiet"
            :disabled="!previousCaseId"
            @click="go(previousCaseId)"
          >
            {{ __('← Previous', 'mandragora-qa-test-manager') }}
          </button>
          <button
            type="button"
            class="qa-button qa-button--quiet"
            :disabled="!nextCaseId"
            @click="go(nextCaseId)"
          >
            {{ __('Next →', 'mandragora-qa-test-manager') }}
          </button>
        </div>
      </div>

      <div v-if="lockedByOther" class="qa-notice qa-notice--warning">
        {{
          sprintf(
            __('%s is testing this. You can still record your own result.', 'mandragora-qa-test-manager'),
            result.in_progress_by.name
          )
        }}
      </div>

      <div v-if="!isOpen" class="qa-notice qa-notice--warning">
        {{
          sprintf(
            __('This run is %s. Results and comments are read-only.', 'mandragora-qa-test-manager'),
            runStatusLabel(runStore.run?.status).toLowerCase()
          )
        }}
      </div>

      <div class="qa-card">
        <div class="qa-card__head">
          <h3>{{ __('Result', 'mandragora-qa-test-manager') }}</h3>
          <span v-if="result?.tested_by" class="qa-muted">
            {{ sprintf(__('Set by %s', 'mandragora-qa-test-manager'), result.tested_by.name) }}
            <span :title="absoluteTime(result.tested_at)">{{
              relativeTime(result.tested_at)
            }}</span>
          </span>
        </div>
        <div class="qa-card__body">
          <StatusControl
            v-if="canTest && result"
            :model-value="result.status"
            :case-title="testCase.title"
            @update:model-value="setStatus"
          />
          <StatusBadge v-else-if="result" :status="result.status" />
          <p v-else class="qa-muted">{{ __('This case is not part of this run.', 'mandragora-qa-test-manager') }}</p>
        </div>
      </div>

      <div v-if="result" class="qa-card">
        <div class="qa-card__head">
          <h3>{{ __('Assigned testers', 'mandragora-qa-test-manager') }}</h3>
          <div v-if="canAssign" class="qa-row">
            <button
              type="button"
              class="qa-button qa-button--small"
              :class="{'qa-button--primary': !assignedToMe}"
              :disabled="savingAssignment"
              @click="toggleSelf"
            >
              {{ assignedToMe ? __('Unassign me', 'mandragora-qa-test-manager') : __('Assign me', 'mandragora-qa-test-manager') }}
            </button>
            <button
              type="button"
              class="qa-button qa-button--small"
              @click="assignDialogOpen = true"
            >
              {{ __('Assign others…', 'mandragora-qa-test-manager') }}
            </button>
          </div>
        </div>
        <div class="qa-card__body">
          <div v-if="assignees.length" class="qa-chips">
            <span v-for="person in assignees" :key="person.id" class="qa-person-badge">
              <img
                class="qa-avatars__item"
                :src="person.avatar"
                :alt="person.name"
                width="24"
                height="24"
                loading="lazy"
              />
              <span>{{ person.name }}</span>
              <button
                v-if="canUnassign(person)"
                type="button"
                class="qa-person-badge__remove"
                :aria-label="sprintf(__('Unassign %s', 'mandragora-qa-test-manager'), person.name)"
                @click="removeAssignee(person)"
              >
                ×
              </button>
            </span>
          </div>
          <p v-else class="qa-muted">
            {{ __('Nobody is assigned to this case yet.', 'mandragora-qa-test-manager') }}
            <template v-if="canAssign">{{
              __('Claim it so the rest of the team knows.', 'mandragora-qa-test-manager')
            }}</template>
          </p>
        </div>

        <AssigneeDialog
          :open="assignDialogOpen"
          :title="__('Assign this case', 'mandragora-qa-test-manager')"
          :empty-text="
            __(
              'Nobody is assigned to this run yet, so there is no one to hand this case to.',
              'mandragora-qa-test-manager'
            )
          "
          :candidates="candidates"
          :assigned="assignees"
          :saving="savingAssignees"
          @close="assignDialogOpen = false"
          @save="saveAssignees"
        />
      </div>

      <div class="qa-grid-2">
        <div class="qa-card">
          <div class="qa-card__head">
            <h3>{{ __('Steps', 'mandragora-qa-test-manager') }}</h3>
          </div>
          <div
            class="qa-card__body qa-prose"
            v-html="
              testCase.steps || `<p class='qa-muted'>${__('No steps recorded.', 'mandragora-qa-test-manager')}</p>`
            "
          />
        </div>

        <div class="qa-card">
          <div class="qa-card__head">
            <h3>{{ __('Expected result', 'mandragora-qa-test-manager') }}</h3>
          </div>
          <div
            class="qa-card__body qa-prose"
            v-html="
              testCase.expected ||
              `<p class='qa-muted'>${__('No expected result recorded.', 'mandragora-qa-test-manager')}</p>`
            "
          />
        </div>
      </div>

      <!--
        Open issues are shown outright; resolved ones sit behind a toggle below them. A list
        of everything that was ever wrong with a case becomes noise fast, but who closed an
        old issue and why is worth a click when today's symptom looks familiar.
      -->
      <div v-if="issues.length" class="qa-stack qa-stack--tight">
        <h3>
          {{
            sprintf(
              _n(
                '%d open issue on this case',
                '%d open issues on this case',
                issues.length,
                'mandragora-qa-test-manager'
              ),
              issues.length
            )
          }}
        </h3>
        <p class="qa-subtitle">{{ __('Raised in any run, still unresolved.', 'mandragora-qa-test-manager') }}</p>

        <div v-for="issue in issues" :key="issue.id" class="qa-issue">
          <div class="qa-issue__head">
            <div>
              <p class="qa-issue__title">{{ issue.title }}</p>
              <p class="qa-issue__meta">
                {{ issue.created_by.name }} ·
                <span :title="absoluteTime(issue.created_at)">{{
                  relativeTime(issue.created_at)
                }}</span>
                <template v-if="issue.origin_run_id">
                  ·
                  {{ sprintf(__('raised in run #%d', 'mandragora-qa-test-manager'), issue.origin_run_id) }}</template
                >
              </p>
            </div>
            <a
              v-if="issue.github_url"
              class="qa-button qa-button--small qa-button--quiet"
              :href="issue.github_url"
              target="_blank"
              rel="noopener noreferrer"
            >
              {{ __('GitHub ↗', 'mandragora-qa-test-manager') }}
            </a>
          </div>

          <div
            v-if="issue.description"
            class="qa-issue__body qa-prose"
            v-html="issue.description"
          />

          <div v-if="bootstrap.caps?.runTests">
            <div v-if="resolvingId === issue.id" class="qa-stack qa-stack--tight">
              <label class="qa-sr-only" :for="`note-${issue.id}`">{{
                __('Resolution note', 'mandragora-qa-test-manager')
              }}</label>
              <textarea
                :id="`note-${issue.id}`"
                v-model="resolutionNote"
                class="qa-textarea"
                :placeholder="__('What fixed it, or why it will not be fixed.', 'mandragora-qa-test-manager')"
              />
              <div class="qa-row">
                <button
                  type="button"
                  class="qa-button qa-button--primary qa-button--small"
                  @click="resolveIssue(issue, 'resolved')"
                >
                  {{ __('Resolve issue', 'mandragora-qa-test-manager') }}
                </button>
                <button
                  type="button"
                  class="qa-button qa-button--small"
                  @click="resolveIssue(issue, 'wontfix')"
                >
                  {{ __('Won’t fix', 'mandragora-qa-test-manager') }}
                </button>
                <button
                  type="button"
                  class="qa-button qa-button--quiet qa-button--small"
                  @click="resolvingId = 0"
                >
                  {{ __('Cancel', 'mandragora-qa-test-manager') }}
                </button>
              </div>
            </div>
            <button
              v-else
              type="button"
              class="qa-button qa-button--small"
              @click="
                resolvingId = issue.id;
                resolutionNote = '';
              "
            >
              {{ __('Resolve issue', 'mandragora-qa-test-manager') }}
            </button>
          </div>
        </div>
      </div>

      <!--
        Collapsed by default, and counted in the button so the toggle is worth pressing only
        when there is something behind it.
      -->
      <div v-if="resolvedIssues.length" class="qa-stack qa-stack--tight">
        <div>
          <button
            type="button"
            class="qa-button qa-button--small qa-button--quiet"
            :aria-expanded="historyOpen"
            @click="historyOpen = !historyOpen"
          >
            {{
              historyOpen
                ? sprintf(
                    _n(
                      'Hide %d closed issue',
                      'Hide %d closed issues',
                      resolvedIssues.length,
                      'mandragora-qa-test-manager'
                    ),
                    resolvedIssues.length
                  )
                : sprintf(
                    _n(
                      'Show %d closed issue',
                      'Show %d closed issues',
                      resolvedIssues.length,
                      'mandragora-qa-test-manager'
                    ),
                    resolvedIssues.length
                  )
            }}
          </button>
        </div>

        <div v-if="historyOpen" class="qa-stack qa-stack--tight">
          <div v-for="issue in resolvedIssues" :key="issue.id" class="qa-issue qa-issue--resolved">
            <div class="qa-issue__head">
              <div>
                <p class="qa-issue__title">
                  {{ issue.title }}
                  <span class="qa-badge">{{ closureLabel(issue) }}</span>
                </p>
                <p class="qa-issue__meta">
                  {{ sprintf(__('Raised by %s', 'mandragora-qa-test-manager'), issue.created_by.name) }} ·
                  <span :title="absoluteTime(issue.created_at)">{{
                    relativeTime(issue.created_at)
                  }}</span>
                  <template v-if="issue.origin_run_id">
                    ·
                    {{
                      sprintf(__('raised in run #%d', 'mandragora-qa-test-manager'), issue.origin_run_id)
                    }}</template
                  >
                </p>
                <p class="qa-issue__meta">
                  {{
                    sprintf(
                      _x('%1$s by %2$s', 'closure label, e.g. Resolved, by person', 'mandragora-qa-test-manager'),
                      closureLabel(issue),
                      issue.resolved_by?.name ?? __('somebody', 'mandragora-qa-test-manager')
                    )
                  }}
                  <template v-if="issue.resolved_at">
                    ·
                    <span :title="absoluteTime(issue.resolved_at)">{{
                      relativeTime(issue.resolved_at)
                    }}</span>
                  </template>
                </p>
              </div>
              <a
                v-if="issue.github_url"
                class="qa-button qa-button--small qa-button--quiet"
                :href="issue.github_url"
                target="_blank"
                rel="noopener noreferrer"
              >
                {{ __('GitHub ↗', 'mandragora-qa-test-manager') }}
              </a>
            </div>

            <div
              v-if="issue.description"
              class="qa-issue__body qa-prose"
              v-html="issue.description"
            />

            <p v-if="issue.resolution_note" class="qa-issue__resolution">
              {{ issue.resolution_note }}
            </p>
          </div>
        </div>
      </div>

      <div v-if="bootstrap.caps?.runTests">
        <button
          v-if="!issueFormOpen"
          type="button"
          class="qa-button qa-button--danger"
          @click="issueFormOpen = true"
        >
          {{ __('Raise an issue', 'mandragora-qa-test-manager') }}
        </button>

        <form v-else class="qa-card" @submit.prevent="raiseIssue">
          <div class="qa-card__head">
            <h3>{{ __('Raise an issue', 'mandragora-qa-test-manager') }}</h3>
          </div>
          <div class="qa-card__body qa-stack">
            <div class="qa-field">
              <label class="qa-field__label" for="issue-title">{{
                __('Title', 'mandragora-qa-test-manager')
              }}</label>
              <input
                id="issue-title"
                v-model="issueDraft.title"
                class="qa-input"
                type="text"
                :placeholder="__('What is broken', 'mandragora-qa-test-manager')"
                required
              />
            </div>

            <div class="qa-field">
              <span class="qa-field__label">{{ __('Description', 'mandragora-qa-test-manager') }}</span>
              <RichTextEditor
                v-model="issueDraft.description"
                :placeholder="__('What you saw, and what you expected.', 'mandragora-qa-test-manager')"
              />
            </div>

            <div class="qa-field">
              <label class="qa-field__label" for="issue-url">{{
                __('GitHub issue', 'mandragora-qa-test-manager')
              }}</label>
              <input
                id="issue-url"
                v-model="issueDraft.github_url"
                class="qa-input"
                type="url"
                placeholder="https://github.com/owner/repo/issues/123"
              />
              <span class="qa-field__hint">{{
                __(
                  'Must be a github.com link. Leave blank if you have not filed it yet.',
                  'mandragora-qa-test-manager'
                )
              }}</span>
            </div>

            <div class="qa-row">
              <button type="submit" class="qa-button qa-button--primary" :disabled="savingIssue">
                {{ savingIssue ? __('Saving…', 'mandragora-qa-test-manager') : __('Raise issue', 'mandragora-qa-test-manager') }}
              </button>
              <button
                type="button"
                class="qa-button qa-button--quiet"
                @click="issueFormOpen = false"
              >
                {{ __('Cancel', 'mandragora-qa-test-manager') }}
              </button>
            </div>
          </div>
        </form>
      </div>

      <div v-if="result" class="qa-card">
        <div class="qa-card__head">
          <h3>{{ __('Comments', 'mandragora-qa-test-manager') }}</h3>
          <span class="qa-muted">{{ __('Scoped to this run', 'mandragora-qa-test-manager') }}</span>
        </div>

        <div class="qa-card__body qa-stack">
          <EmptyState
            v-if="!comments.length"
            :title="__('No comments on this case yet.', 'mandragora-qa-test-manager')"
            :description="__('Add one when a result needs explaining.', 'mandragora-qa-test-manager')"
          />

          <div v-else>
            <div
              v-for="{comment, depth} in threadedComments"
              :key="comment.id"
              class="qa-comment"
              :class="{'qa-comment--reply': depth > 1}"
              :style="{'--qa-comment-depth': depth - 1}"
            >
              <img
                class="qa-comment__avatar"
                :src="comment.author.avatar"
                :alt="comment.author.name"
                width="28"
                height="28"
                loading="lazy"
              />
              <div class="qa-comment__main">
                <div class="qa-comment__head">
                  <span class="qa-comment__author">{{ comment.author.name }}</span>
                  <span :title="absoluteTime(comment.created_at)">{{
                    relativeTime(comment.created_at)
                  }}</span>
                  <span
                    v-if="isOpen && editingCommentId !== comment.id"
                    class="qa-comment__actions"
                  >
                    <button
                      v-if="canTest && depth < MAX_COMMENT_DEPTH"
                      type="button"
                      class="qa-icon-button"
                      :title="__('Reply', 'mandragora-qa-test-manager')"
                      :aria-label="__('Reply to comment', 'mandragora-qa-test-manager')"
                      @click="startReply(comment)"
                    >
                      <span class="dashicons dashicons-undo" aria-hidden="true" />
                    </button>
                    <button
                      v-if="comment.author.id === bootstrap.currentUser?.id"
                      type="button"
                      class="qa-icon-button"
                      :title="__('Edit comment', 'mandragora-qa-test-manager')"
                      :aria-label="__('Edit comment', 'mandragora-qa-test-manager')"
                      @click="startEditComment(comment)"
                    >
                      <span class="dashicons dashicons-edit" aria-hidden="true" />
                    </button>
                    <button
                      v-if="
                        comment.author.id === bootstrap.currentUser?.id ||
                        bootstrap.caps?.manageCases
                      "
                      type="button"
                      class="qa-icon-button qa-icon-button--danger"
                      :title="__('Delete comment', 'mandragora-qa-test-manager')"
                      :aria-label="__('Delete comment', 'mandragora-qa-test-manager')"
                      @click="deleteComment(comment)"
                    >
                      <span class="dashicons dashicons-trash" aria-hidden="true" />
                    </button>
                  </span>
                </div>
                <form
                  v-if="editingCommentId === comment.id"
                  class="qa-stack qa-stack--tight"
                  @submit.prevent="saveCommentEdit"
                >
                  <RichTextEditor v-model="editCommentDraft" />
                  <div class="qa-row">
                    <button
                      type="submit"
                      class="qa-button qa-button--primary qa-button--small"
                      :disabled="savingCommentEdit"
                    >
                      {{ savingCommentEdit ? __('Saving…', 'mandragora-qa-test-manager') : __('Save', 'mandragora-qa-test-manager') }}
                    </button>
                    <button
                      type="button"
                      class="qa-button qa-button--small qa-button--quiet"
                      :disabled="savingCommentEdit"
                      @click="cancelEditComment"
                    >
                      {{ __('Cancel', 'mandragora-qa-test-manager') }}
                    </button>
                  </div>
                </form>
                <div v-else class="qa-comment__body qa-prose" v-html="comment.content" />
                <form
                  v-if="replyingToId === comment.id"
                  class="qa-stack qa-stack--tight qa-comment__reply-form"
                  @submit.prevent="postReply"
                >
                  <RichTextEditor
                    v-model="replyDraft"
                    :placeholder="sprintf(__('Reply to %s', 'mandragora-qa-test-manager'), comment.author.name)"
                  />
                  <div class="qa-row">
                    <button
                      type="submit"
                      class="qa-button qa-button--primary qa-button--small"
                      :disabled="postingReply"
                    >
                      {{ postingReply ? __('Replying…', 'mandragora-qa-test-manager') : __('Reply', 'mandragora-qa-test-manager') }}
                    </button>
                    <button
                      type="button"
                      class="qa-button qa-button--small qa-button--quiet"
                      :disabled="postingReply"
                      @click="cancelReply"
                    >
                      {{ __('Cancel', 'mandragora-qa-test-manager') }}
                    </button>
                  </div>
                </form>
              </div>
            </div>
          </div>

          <form v-if="canTest" class="qa-stack qa-stack--tight" @submit.prevent="postComment">
            <RichTextEditor
              v-model="commentDraft"
              :placeholder="__('Add a comment', 'mandragora-qa-test-manager')"
            />
            <div>
              <button type="submit" class="qa-button qa-button--primary" :disabled="postingComment">
                {{ postingComment ? __('Adding…', 'mandragora-qa-test-manager') : __('Add comment', 'mandragora-qa-test-manager') }}
              </button>
            </div>
          </form>
        </div>
      </div>
    </template>

    <EmptyState v-else :title="__('That case could not be found.', 'mandragora-qa-test-manager')">
      <RouterLink class="qa-button" :to="`/runs/${runId}`">{{
        __('Back to the run', 'mandragora-qa-test-manager')
      }}</RouterLink>
    </EmptyState>
  </div>
</template>
