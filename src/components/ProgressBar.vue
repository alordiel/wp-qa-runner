<script setup>
/**
 * Run progress.
 *
 * The primary information on the run list, so it has to read at a glance: proportional
 * segments plus a legend, with any non-zero fail count in the destructive colour even when
 * the segment itself is a sliver.
 */

import {computed} from 'vue';
import {plural} from '../utils/format.js';

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
</script>

<template>
  <div class="qa-progress">
    <div
      class="qa-progress__track"
      role="img"
      :aria-label="`${tested} of ${total} cases tested, ${failCount} failing`"
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
      <span><b class="qa-count">{{ counts.pass ?? 0 }}</b> passed</span>
      <span :class="{'is-fail': failCount > 0}"><b class="qa-count">{{ failCount }}</b> failed</span>
      <span v-if="counts.blocked"><b class="qa-count">{{ counts.blocked }}</b> blocked</span>
      <span v-if="counts.skipped"><b class="qa-count">{{ counts.skipped }}</b> skipped</span>
      <span ><b class="qa-count">{{ remaining }}</b> remaining</span>
      <span v-if="issues > 0" class="qa-badge qa-badge--issue">
        <span class="qa-count">{{ issues + ' ' + plural(issues, 'open issue', 'open issues') }}</span>
      </span>
    </div>
  </div>
</template>
