<script setup>
/**
 * Run progress.
 *
 * The primary information on the run list, so it has to read at a glance: proportional
 * segments plus a legend, with any non-zero fail count in the destructive colour even when
 * the segment itself is a sliver.
 */

import {computed} from 'vue';

const props = defineProps({
  counts: {type: Object, default: () => ({})},
  issues: {type: Number, default: () => 0},
  compact: {type: Boolean, default: false}
});

const total = computed(() => props.counts.total ?? 0);

const segments = computed(() =>
  ['pass', 'fail', 'blocked', 'skipped'].map((key) => ({
    key,
    count: props.counts[key] ?? 0,
    width: total.value ? ((props.counts[key] ?? 0) / total.value) * 100 : 0
  }))
);

const tested = computed(() => segments.value.reduce((sum, segment) => sum + segment.count, 0));
const remaining = computed(() => Math.max(0, total.value - tested.value));
const failCount = computed(() => props.counts.fail ?? 0);

/**
 * Wraps a count in the bold legend markup, for the translated "%s passed" strings.
 *
 * @param {number} count Count.
 * @returns {string} HTML.
 */
function bold(count) {
  return `<b class="qa-count">${Number(count) || 0}</b>`;
}
</script>

<template>
  <div class="qa-progress">
    <div
      class="qa-progress__track"
      role="img"
      :aria-label="
        sprintf(
          _n(
            '%1$d of %2$d case tested, %3$d failing',
            '%1$d of %2$d cases tested, %3$d failing',
            total,
            'mandragora-qa-test-manager'
          ),
          tested,
          total,
          failCount
        )
      "
    >
      <div
        v-for="segment in segments"
        :key="segment.key"
        class="qa-progress__segment"
        :class="`qa-progress__segment--${segment.key}`"
        :style="{width: `${segment.width}%`}"
      />
    </div>
    <div v-if="!compact" class="qa-progress__legend">
      <!-- The counts are integers wrapped in <b>, so v-html here carries no user input. -->
      <span
        class="qa-badge qa-badge--success"
        v-html="
          sprintf(
            _n('%s passed', '%s passed', counts.pass ?? 0, 'mandragora-qa-test-manager'),
            bold(counts.pass ?? 0)
          )
        "
      />
      <span
        :class="{'qa-badge--issue': failCount > 0, 'qa-badge--env': failCount === 0}"
        class="qa-badge"
        v-html="sprintf(_n('%s failed', '%s failed', failCount, 'mandragora-qa-test-manager'), bold(failCount))"
      />
      <span
        v-if="counts.blocked"
        class="qa-badge qa-badge--issue"
        v-html="
          sprintf(_n('%s blocked', '%s blocked', counts.blocked, 'mandragora-qa-test-manager'), bold(counts.blocked))
        "
      />
      <span
        v-if="counts.skipped"
        class="qa-badge qa-badge--env"
        v-html="
          sprintf(_n('%s skipped', '%s skipped', counts.skipped, 'mandragora-qa-test-manager'), bold(counts.skipped))
        "
      />
      <span
        class="qa-badge qa-badge--lock"
        v-html="
          sprintf(_n('%s remaining', '%s remaining', remaining, 'mandragora-qa-test-manager'), bold(remaining))
        "
      />
      <span v-if="issues > 0" class="qa-badge qa-badge--issue">
        <span class="qa-count">{{
          sprintf(_n('%d open issue', '%d open issues', issues, 'mandragora-qa-test-manager'), issues)
        }}</span>
      </span>
    </div>
  </div>
</template>
