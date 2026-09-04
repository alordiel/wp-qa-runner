<script setup>
/**
 * Create or edit one case.
 */

import {computed, onMounted, ref} from 'vue';
import {RouterLink, useRoute, useRouter} from 'vue-router';

import RichTextEditor from '../components/RichTextEditor.vue';
import {api} from '../api/client.js';
import {PRIORITIES} from '../utils/status.js';
import {useCaseStore} from '../stores/cases.js';
import {useUiStore} from '../stores/ui.js';

const props = defineProps({
  id: {type: [String, Number], default: null}
});

const route = useRoute();
const router = useRouter();
const caseStore = useCaseStore();
const ui = useUiStore();

const isEdit = computed(() => Boolean(props.id));
const loading = ref(true);
const saving = ref(false);

const form = ref({
  suite_id: '',
  title: '',
  steps: '',
  expected: '',
  priority: 'normal',
  is_active: true
});

const canSubmit = computed(
  () => form.value.title.trim() !== '' && form.value.suite_id !== '' && !saving.value
);

/**
 * Saves the case and returns to the library.
 *
 * @returns {Promise<void>}
 */
async function submit() {
  if (!canSubmit.value) {
    return;
  }

  saving.value = true;

  const payload = {
    suite_id: Number(form.value.suite_id),
    title: form.value.title,
    steps: form.value.steps,
    expected: form.value.expected,
    priority: form.value.priority,
    is_active: form.value.is_active
  };

  try {
    if (isEdit.value) {
      await api.cases.update(Number(props.id), payload);
      ui.toast('Case saved.');
    } else {
      await api.cases.create(payload);
      ui.toast('Case created.');
    }

    router.push('/cases');
  } catch (error) {
    ui.toastError(error, 'The case could not be saved.');
  } finally {
    saving.value = false;
  }
}

onMounted(async () => {
  try {
    await caseStore.loadSuites();

    if (isEdit.value) {
      const item = await api.cases.get(Number(props.id));

      form.value = {
        suite_id: String(item.suite_id),
        title: item.title,
        steps: item.steps ?? '',
        expected: item.expected ?? '',
        priority: item.priority,
        is_active: item.is_active
      };
    } else {
      const preselected = route.query.suite;

      form.value.suite_id = preselected
        ? String(preselected)
        : String(caseStore.suites[0]?.id ?? '');
    }
  } catch (error) {
    ui.toastError(error, 'This case could not be loaded.');
  } finally {
    loading.value = false;
  }
});
</script>

<template>
  <form class="qa-stack" @submit.prevent="submit">
    <div class="qa-page-head">
      <div class="qa-page-head__meta">
        <h2>{{ isEdit ? 'Edit case' : 'New case' }}</h2>
        <p>
          Cases are written once, edited rarely. They can be reused by many runs. If you would like to edit a case, make sure it is minor changes, for major one just create new test.
        </p>
      </div>
      <div class="qa-row">
        <RouterLink class="qa-button qa-button--quiet" to="/cases">Cancel</RouterLink>
        <button type="submit" class="qa-button qa-button--primary" :disabled="!canSubmit">
          {{ saving ? 'Saving…' : 'Save case' }}
        </button>
      </div>
    </div>

    <p v-if="loading" class="qa-skeleton">Loading…</p>

    <div v-else class="qa-card">
      <div class="qa-card__body qa-stack">
        <div class="qa-row" style="align-items: flex-start; gap: 16px">
          <div class="qa-field" style="flex: 2; min-width: 220px">
            <label class="qa-field__label" for="case-title">Title</label>
            <input
              id="case-title"
              v-model="form.title"
              class="qa-input"
              type="text"
              placeholder="Log in with a valid account"
              required
            />
          </div>

          <div class="qa-field" style="flex: 1; min-width: 160px">
            <label class="qa-field__label" for="case-suite">Suite</label>
            <select id="case-suite" v-model="form.suite_id" class="qa-select" required>
              <option value="" disabled>Choose a suite</option>
              <option v-for="suite in caseStore.suites" :key="suite.id" :value="String(suite.id)">
                {{ suite.name }}
              </option>
            </select>
          </div>

          <div class="qa-field" style="flex: 1; min-width: 140px">
            <label class="qa-field__label" for="case-priority">Priority</label>
            <select id="case-priority" v-model="form.priority" class="qa-select">
              <option v-for="option in PRIORITIES" :key="option.value" :value="option.value">
                {{ option.label }}
              </option>
            </select>
          </div>
        </div>

        <div class="qa-field">
          <span class="qa-field__label">Steps</span>
          <RichTextEditor v-model="form.steps" placeholder="What the tester should do, in order." />
        </div>

        <div class="qa-field">
          <span class="qa-field__label">Expected result</span>
          <RichTextEditor
            v-model="form.expected"
            placeholder="What should happen if the case passes."
          />
        </div>

        <label v-if="isEdit" class="qa-checkbox">
          <input v-model="form.is_active" type="checkbox" />
          <span>
            Active
            <span class="qa-field__hint"
              >Archived cases stay in past runs but cannot join new ones.</span
            >
          </span>
        </label>
      </div>
    </div>
  </form>
</template>
