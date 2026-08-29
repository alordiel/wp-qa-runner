/**
 * Routing.
 *
 * Hash mode: history mode fights admin.php?page=qa-runner and would need rewrite rules for
 * no benefit inside wp-admin.
 */

import {createRouter, createWebHashHistory} from 'vue-router';

import {bootstrap} from '../api/client.js';
import CaseEditView from '../views/CaseEditView.vue';
import CaseLibraryView from '../views/CaseLibraryView.vue';
import CaseTestView from '../views/CaseTestView.vue';
import RunCreateView from '../views/RunCreateView.vue';
import RunDetailView from '../views/RunDetailView.vue';
import RunListView from '../views/RunListView.vue';
import SettingsView from '../views/SettingsView.vue';
import SuiteView from '../views/SuiteView.vue';

const routes = [
  {path: '/', name: 'runs', component: RunListView},
  {path: '/runs/new', name: 'run-create', component: RunCreateView},
  {path: '/runs/:id', name: 'run-detail', component: RunDetailView, props: true},
  {path: '/runs/:id/cases/:caseId', name: 'case-test', component: CaseTestView, props: true},
  {path: '/cases', name: 'cases', component: CaseLibraryView, meta: {manageCases: true}},
  {path: '/cases/new', name: 'case-create', component: CaseEditView, meta: {manageCases: true}},
  {
    path: '/cases/:id/edit',
    name: 'case-edit',
    component: CaseEditView,
    props: true,
    meta: {manageCases: true}
  },
  {path: '/suites', name: 'suites', component: SuiteView, meta: {manageCases: true}},
  {path: '/settings', name: 'settings', component: SettingsView, meta: {manageCases: true}},
  {path: '/:pathMatch(.*)*', redirect: '/'}
];

export const router = createRouter({
  history: createWebHashHistory(),
  routes
});

/**
 * Guards the management routes against testers.
 *
 * Redirecting with a notice beats rendering an empty screen: the tester learns why they
 * are not where they expected to be.
 */
router.beforeEach((to) => {
  if (to.meta.manageCases && !bootstrap.caps?.manageCases) {
    return {path: '/', query: {denied: '1'}};
  }

  return true;
});
