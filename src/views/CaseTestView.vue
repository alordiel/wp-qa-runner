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
import {absoluteTime, plural, relativeTime} from '../utils/format.js';
import {statusLabel} from '../utils/status.js';
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
const comments = ref([]);
const loading = ref(true);

const commentDraft = ref('');
const postingComment = ref(false);

const issueFormOpen = ref(false);
const issueDraft = ref({title: '', description: '', github_url: ''});
const savingIssue = ref(false);

const resolvingId = ref(0);
const resolutionNote = ref('');

const savingAssignment = ref(false);
const assignDialogOpen = ref(false);
const savingAssignees = ref(false);

const result = computed(() => runStore.resultsByCaseId[caseId.value] ?? null);
const isOpen = computed(() => runStore.run?.status === 'open');
const canTest = computed(() => Boolean(bootstrap.caps?.runTests) && isOpen.value);

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
    ui.toast('Assignees updated.');
  } catch (error) {
    // The dialog stays open on failure, so the draft is still there to retry or cancel.
    ui.toastError(error, 'Those assignments could not be saved.');
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
    ui.toast(assignedToMe.value ? 'Assigned to you.' : 'You are off this case.');
  } catch (error) {
    ui.toastError(error, 'That assignment could not be saved.');
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
    ui.toastError(error, 'That assignment could not be removed.');
  }
}

/**
 * Loads the case, its open issues and this run's comments.
 *
 * @returns {Promise<void>}
 */
async function loadCase() {
  loading.value = true;
  // Browser history can change the case under an open dialog, which would leave it showing
  // the previous case's assignees.
  assignDialogOpen.value = false;

  try {
    if (!runStore.run || runStore.run.id !== runId.value) {
      await runStore.loadRun(runId.value);
      await runStore.loadPreviousStatus(runId.value);
    }

    const payload = await api.cases.get(caseId.value);

    testCase.value = payload;
    issues.value = payload.issues ?? [];

    if (result.value) {
      comments.value = await api.results.comments(result.value.id);

      if (canTest.value) {
        // The lock is passive: it renders as a label for other testers and never blocks a
        // second submission.
        await api.results.lock(result.value.id).catch(() => {});
      }
    }
  } catch (error) {
    ui.toastError(error, 'This case could not be loaded.');
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
    ui.toastError(error, 'That result could not be saved.');
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
    ui.toast('Comment added.');
  } catch (error) {
    ui.toastError(error, 'The comment could not be added.');
  } finally {
    postingComment.value = false;
  }
}

/**
 * Deletes a comment.
 *
 * @param {Object} comment Comment row.
 * @returns {Promise<void>}
 */
async function deleteComment(comment) {
  if (!window.confirm('Delete this comment?')) {
    return;
  }

  try {
    await api.comments.remove(comment.id);

    comments.value = comments.value.filter((item) => item.id !== comment.id);
    runStore.replaceResult({
      ...result.value,
      comment_count: Math.max(0, result.value.comment_count - 1)
    });
    ui.toast('Comment deleted.');
  } catch (error) {
    ui.toastError(error, 'The comment could not be deleted.');
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

    ui.toast('Issue raised.');
  } catch (error) {
    ui.toastError(error, 'The issue could not be raised.');
  } finally {
    savingIssue.value = false;
  }
}

/**
 * Resolves or closes an issue, which removes its banner from every later run.
 *
 * @param {Object} issue Issue row.
 * @param {'resolved'|'wontfix'} status New status.
 * @returns {Promise<void>}
 */
async function resolveIssue(issue, status) {
  try {
    await api.issues.update(issue.id, {status, resolution_note: resolutionNote.value});

    issues.value = issues.value.filter((item) => item.id !== issue.id);
    resolvingId.value = 0;
    resolutionNote.value = '';

    if (result.value) {
      runStore.replaceResult({
        ...result.value,
        open_issue_count: Math.max(0, result.value.open_issue_count - 1)
      });
    }

    ui.toast(status === 'resolved' ? 'Issue resolved.' : "Issue closed as won't fix.");
  } catch (error) {
    ui.toastError(error, 'The issue could not be updated.');
  }
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
    <p v-if="loading" class="qa-skeleton">Loading case…</p>

    <template v-else-if="testCase">
      <div class="qa-page-head">
        <div class="qa-page-head__meta">
          <RouterLink :to="`/runs/${runId}`" class="qa-subtitle">
            ← {{ runStore.run?.name ?? 'Back to run' }}
          </RouterLink>
          <h2 class="qa-row">
            <PriorityDot :priority="testCase.priority" />
            <span>{{ testCase.title }}</span>
          </h2>
          <p class="qa-subtitle">
            {{ testCase.suite_name }}
            <template v-if="previousRun">
              · last run ({{ previousRun.run_name }}):
              {{ statusLabel(previousRun.status) }}
            </template>
          </p>
        </div>

        <div class="qa-row">
          <button
            type="button"
            class="qa-button qa-button--quiet"
            :disabled="!previousCaseId"
            @click="go(previousCaseId)"
          >
            ← Previous
          </button>
          <button
            type="button"
            class="qa-button qa-button--quiet"
            :disabled="!nextCaseId"
            @click="go(nextCaseId)"
          >
            Next →
          </button>
        </div>
      </div>

      <div v-if="lockedByOther" class="qa-notice qa-notice--warning">
        {{ result.in_progress_by.name }} is testing this. You can still record your own result.
      </div>

      <div v-if="!isOpen" class="qa-notice qa-notice--warning">
        This run is {{ runStore.run?.status }}. Results and comments are read-only.
      </div>

      <div class="qa-card">
        <div class="qa-card__head">
          <h3>Result</h3>
          <span v-if="result?.tested_by" class="qa-muted">
            Set by {{ result.tested_by.name }}
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
          <p v-else class="qa-muted">This case is not part of this run.</p>
        </div>
      </div>

      <div v-if="result" class="qa-card">
        <div class="qa-card__head">
          <h3>Assigned testers</h3>
          <div v-if="canAssign" class="qa-row">
            <button
              type="button"
              class="qa-button qa-button--small"
              :class="{'qa-button--primary': !assignedToMe}"
              :disabled="savingAssignment"
              @click="toggleSelf"
            >
              {{ assignedToMe ? 'Unassign me' : 'Assign me' }}
            </button>
            <button
              type="button"
              class="qa-button qa-button--small"
              @click="assignDialogOpen = true"
            >
              Assign others…
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
                :aria-label="`Unassign ${person.name}`"
                @click="removeAssignee(person)"
              >
                ×
              </button>
            </span>
          </div>
          <p v-else class="qa-muted">
            Nobody is assigned to this case yet.
            <template v-if="canAssign">Claim it so the rest of the team knows.</template>
          </p>
        </div>

        <AssigneeDialog
          :open="assignDialogOpen"
          title="Assign this case"
          empty-text="Nobody is assigned to this run yet, so there is no one to hand this case to."
          :candidates="candidates"
          :assigned="assignees"
          :saving="savingAssignees"
          @close="assignDialogOpen = false"
          @save="saveAssignees"
        />
      </div>

      <div class="qa-grid-2">
        <div class="qa-card">
          <div class="qa-card__head"><h3>Steps</h3></div>
          <div
            class="qa-card__body qa-prose"
            v-html="testCase.steps || '<p class=\'qa-muted\'>No steps recorded.</p>'"
          />
        </div>

        <div class="qa-card">
          <div class="qa-card__head"><h3>Expected result</h3></div>
          <div
            class="qa-card__body qa-prose"
            v-html="testCase.expected || '<p class=\'qa-muted\'>No expected result recorded.</p>'"
          />
        </div>
      </div>

      <!--
        Open issues only. A resolved issue is history, reachable from the case library; a
        list of everything that was ever wrong with a case becomes noise fast.
      -->
      <div v-if="issues.length" class="qa-stack qa-stack--tight">
        <h3>{{ issues.length }} open {{ plural(issues.length, 'issue') }} on this case</h3>
        <p class="qa-subtitle">Raised in any run, still unresolved.</p>

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
                  · raised in run #{{ issue.origin_run_id }}</template
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
              GitHub ↗
            </a>
          </div>

          <div
            v-if="issue.description"
            class="qa-issue__body qa-prose"
            v-html="issue.description"
          />

          <div v-if="bootstrap.caps?.runTests">
            <div v-if="resolvingId === issue.id" class="qa-stack qa-stack--tight">
              <label class="qa-sr-only" :for="`note-${issue.id}`">Resolution note</label>
              <textarea
                :id="`note-${issue.id}`"
                v-model="resolutionNote"
                class="qa-textarea"
                placeholder="What fixed it, or why it will not be fixed."
              />
              <div class="qa-row">
                <button
                  type="button"
                  class="qa-button qa-button--primary qa-button--small"
                  @click="resolveIssue(issue, 'resolved')"
                >
                  Resolve issue
                </button>
                <button
                  type="button"
                  class="qa-button qa-button--small"
                  @click="resolveIssue(issue, 'wontfix')"
                >
                  Won't fix
                </button>
                <button
                  type="button"
                  class="qa-button qa-button--quiet qa-button--small"
                  @click="resolvingId = 0"
                >
                  Cancel
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
              Resolve issue
            </button>
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
          Raise an issue
        </button>

        <form v-else class="qa-card" @submit.prevent="raiseIssue">
          <div class="qa-card__head"><h3>Raise an issue</h3></div>
          <div class="qa-card__body qa-stack">
            <div class="qa-field">
              <label class="qa-field__label" for="issue-title">Title</label>
              <input
                id="issue-title"
                v-model="issueDraft.title"
                class="qa-input"
                type="text"
                placeholder="What is broken"
                required
              />
            </div>

            <div class="qa-field">
              <span class="qa-field__label">Description</span>
              <RichTextEditor
                v-model="issueDraft.description"
                placeholder="What you saw, and what you expected."
              />
            </div>

            <div class="qa-field">
              <label class="qa-field__label" for="issue-url">GitHub issue</label>
              <input
                id="issue-url"
                v-model="issueDraft.github_url"
                class="qa-input"
                type="url"
                placeholder="https://github.com/owner/repo/issues/123"
              />
              <span class="qa-field__hint"
                >Must be a github.com link. Leave blank if you have not filed it yet.</span
              >
            </div>

            <div class="qa-row">
              <button type="submit" class="qa-button qa-button--primary" :disabled="savingIssue">
                {{ savingIssue ? 'Saving…' : 'Raise issue' }}
              </button>
              <button
                type="button"
                class="qa-button qa-button--quiet"
                @click="issueFormOpen = false"
              >
                Cancel
              </button>
            </div>
          </div>
        </form>
      </div>

      <div v-if="result" class="qa-card">
        <div class="qa-card__head">
          <h3>Comments</h3>
          <span class="qa-muted">Scoped to this run</span>
        </div>

        <div class="qa-card__body qa-stack">
          <EmptyState
            v-if="!comments.length"
            title="No comments on this case yet."
            description="Add one when a result needs explaining."
          />

          <div v-else>
            <div v-for="comment in comments" :key="comment.id" class="qa-comment">
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
                  <button
                    v-if="
                      isOpen &&
                      (comment.author.id === bootstrap.currentUser?.id ||
                        bootstrap.caps?.manageCases)
                    "
                    type="button"
                    class="qa-button qa-button--small qa-button--quiet"
                    @click="deleteComment(comment)"
                  >
                    Delete
                  </button>
                </div>
                <div class="qa-comment__body qa-prose" v-html="comment.content" />
              </div>
            </div>
          </div>

          <form v-if="canTest" class="qa-stack qa-stack--tight" @submit.prevent="postComment">
            <RichTextEditor v-model="commentDraft" placeholder="Add a comment" />
            <div>
              <button type="submit" class="qa-button qa-button--primary" :disabled="postingComment">
                {{ postingComment ? 'Adding…' : 'Add comment' }}
              </button>
            </div>
          </form>
        </div>
      </div>
    </template>

    <EmptyState v-else title="That case could not be found.">
      <RouterLink class="qa-button" :to="`/runs/${runId}`">Back to the run</RouterLink>
    </EmptyState>
  </div>
</template>
