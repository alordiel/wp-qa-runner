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
      ui.toast(wp.i18n.__('Case saved.', 'mandragora-qa-test-manager'));
    } else {
      await api.cases.create(payload);
      ui.toast(wp.i18n.__('Case created.', 'mandragora-qa-test-manager'));
    }

    router.push('/cases');
  } catch (error) {
    ui.toastError(error, wp.i18n.__('The case could not be saved.', 'mandragora-qa-test-manager'));
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
    ui.toastError(error, wp.i18n.__('This case could not be loaded.', 'mandragora-qa-test-manager'));
  } finally {
    loading.value = false;
  }
});
</script>

<template>
  <form class="qa-stack" @submit.prevent="submit">
    <div class="qa-page-head">
      <div class="qa-page-head__meta">
        <h2>{{ isEdit ? __('Edit case', 'mandragora-qa-test-manager') : __('New case', 'mandragora-qa-test-manager') }}</h2>
        <p>
          {{
            __(
              'Cases are written once and edited rarely, and many runs can reuse them. Keep edits to minor changes; for a major change, create a new case instead.',
              'mandragora-qa-test-manager'
            )
          }}
        </p>
      </div>
      <div class="qa-row">
        <RouterLink class="qa-button qa-button--quiet" to="/cases">{{
          __('Cancel', 'mandragora-qa-test-manager')
        }}</RouterLink>
        <button type="submit" class="qa-button qa-button--primary" :disabled="!canSubmit">
          {{ saving ? __('Saving…', 'mandragora-qa-test-manager') : __('Save case', 'mandragora-qa-test-manager') }}
        </button>
      </div>
    </div>

    <p v-if="loading" class="qa-skeleton">{{ __('Loading…', 'mandragora-qa-test-manager') }}</p>

    <div v-else class="qa-card">
      <div class="qa-card__body qa-stack">
        <div class="qa-row" style="align-items: flex-start; gap: 16px">
          <div class="qa-field" style="flex: 2; min-width: 220px">
            <label class="qa-field__label" for="case-title">{{ __('Title', 'mandragora-qa-test-manager') }}</label>
            <input
              id="case-title"
              v-model="form.title"
              class="qa-input"
              type="text"
              :placeholder="__('Log in with a valid account', 'mandragora-qa-test-manager')"
              required
            />
          </div>

          <div class="qa-field" style="flex: 1; min-width: 160px">
            <label class="qa-field__label" for="case-suite">{{ __('Suite', 'mandragora-qa-test-manager') }}</label>
            <select id="case-suite" v-model="form.suite_id" class="qa-select" required>
              <option value="" disabled>{{ __('Choose a suite', 'mandragora-qa-test-manager') }}</option>
              <option v-for="suite in caseStore.suites" :key="suite.id" :value="String(suite.id)">
                {{ suite.name }}
              </option>
            </select>
          </div>

          <div class="qa-field" style="flex: 1; min-width: 140px">
            <label class="qa-field__label" for="case-priority">{{
              __('Priority', 'mandragora-qa-test-manager')
            }}</label>
            <select id="case-priority" v-model="form.priority" class="qa-select">
              <option v-for="option in PRIORITIES" :key="option.value" :value="option.value">
                {{ option.label }}
              </option>
            </select>
          </div>
        </div>

        <div class="qa-field">
          <span class="qa-field__label">{{ __('Steps', 'mandragora-qa-test-manager') }}</span>
          <RichTextEditor
            v-model="form.steps"
            :placeholder="__('What the tester should do, in order.', 'mandragora-qa-test-manager')"
          />
        </div>

        <div class="qa-field">
          <span class="qa-field__label">{{ __('Expected result', 'mandragora-qa-test-manager') }}</span>
          <RichTextEditor
            v-model="form.expected"
            :placeholder="__('What should happen if the case passes.', 'mandragora-qa-test-manager')"
          />
        </div>

        <label v-if="isEdit" class="qa-checkbox">
          <input v-model="form.is_active" type="checkbox" />
          <span>
            {{ __('Active', 'mandragora-qa-test-manager') }}
            <span class="qa-field__hint">{{
              __('Archived cases stay in past runs but cannot join new ones.', 'mandragora-qa-test-manager')
            }}</span>
          </span>
        </label>
      </div>
    </div>
  </form>
</template>
