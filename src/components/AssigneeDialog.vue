<script setup>
/**
 * Picks people from a list, one checkbox each.
 *
 * Used both for handing a case to the testers on a run and for setting who is on the run in
 * the first place; the caller supplies the candidates and decides what a tick means.
 *
 * Built on the native <dialog> element, which brings focus trapping, Esc-to-close and a
 * backdrop with no script of our own. Each checkbox writes immediately rather than
 * collecting a batch behind a Save button: there is one endpoint per person either way, and
 * a tester who ticks a name and closes the tab should not lose the assignment.
 */

import {ref, watch} from 'vue';

const props = defineProps({
  open: {type: Boolean, default: false},
  title: {type: String, default: 'Assign'},
  /** Shown in place of the list when there are no candidates at all. */
  emptyText: {type: String, default: 'There is nobody to choose from.'},
  /** People who can be picked, as {id, name, avatar}. */
  candidates: {type: Array, default: () => []},
  /** People already picked, as {id, name, avatar}. */
  assigned: {type: Array, default: () => []}
});

const emit = defineEmits(['close', 'toggle']);

const dialog = ref(null);

/**
 * Identifiers with a write in flight, so a row cannot be double-submitted.
 *
 * @type {import('vue').Ref<number[]>}
 */
const saving = ref([]);

/**
 * Whether a person already holds this case.
 *
 * @param {Object} person Candidate.
 * @returns {boolean}
 */
function isAssigned(person) {
  return props.assigned.some((item) => item.id === person.id);
}

/**
 * Asks the parent to add or remove one person, holding the row until it settles.
 *
 * @param {Object} person Candidate.
 * @returns {Promise<void>}
 */
async function toggle(person) {
  if (saving.value.includes(person.id)) {
    return;
  }

  saving.value = [...saving.value, person.id];

  try {
    await new Promise((resolve) => {
      emit('toggle', {person, assigned: !isAssigned(person), done: resolve});
    });
  } finally {
    saving.value = saving.value.filter((id) => id !== person.id);
  }
}

// showModal() cannot be set declaratively, so the open prop drives it imperatively.
watch(
  () => props.open,
  (open) => {
    const element = dialog.value;

    if (!element) {
      return;
    }

    if (open && !element.open) {
      element.showModal();
    } else if (!open && element.open) {
      element.close();
    }
  }
);
</script>

<template>
  <dialog ref="dialog" class="qa-dialog" @close="emit('close')" @cancel="emit('close')">
    <div class="qa-dialog__head">
      <h3 class="qa-dialog__title">{{ title }}</h3>
      <button type="button" class="qa-dialog__close" aria-label="Close" @click="emit('close')">
        ×
      </button>
    </div>

    <div class="qa-dialog__body">
      <p v-if="!candidates.length" class="qa-muted">{{ emptyText }}</p>

      <ul v-else class="qa-dialog__list">
        <li v-for="person in candidates" :key="person.id">
          <label class="qa-checkbox">
            <input
              type="checkbox"
              :checked="isAssigned(person)"
              :disabled="saving.includes(person.id)"
              @change="toggle(person)"
            />
            <img
              class="qa-avatars__item"
              :src="person.avatar"
              alt=""
              width="24"
              height="24"
              loading="lazy"
            />
            <span>{{ person.name }}</span>
          </label>
        </li>
      </ul>
    </div>

    <div class="qa-dialog__foot">
      <button type="button" class="qa-button qa-button--primary" @click="emit('close')">
        Done
      </button>
    </div>
  </dialog>
</template>
