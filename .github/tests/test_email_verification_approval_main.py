"""Exercise the real gate entry point with documented API-shaped offline fixtures.

No GitHub token or network access; these are not live deployment approvals.
"""
import contextlib
import importlib.util
import io
import json
import os
from pathlib import Path
import unittest
from unittest import mock

spec = importlib.util.spec_from_file_location(
    'approval_main_gate', Path(__file__).resolve().parents[1] / 'scripts/email-verification-approval.py')
gate = importlib.util.module_from_spec(spec)
spec.loader.exec_module(gate)


class ApprovalMainSafetyTest(unittest.TestCase):
    def environment(self, operation='PREVIEW'):
        return dict(GITHUB_REPOSITORY=gate.REPOSITORY, GITHUB_EVENT_NAME='workflow_dispatch',
                    GITHUB_REF='refs/heads/main', GITHUB_RUN_ATTEMPT='1', OPERATION=operation,
                    GITHUB_SHA='a' * 40, REVIEWED_SHA='a' * 40, GITHUB_RUN_ID='900002',
                    GITHUB_ACTOR='dispatcher', GITHUB_TRIGGERING_ACTOR='dispatcher',
                    CONFIRMATION='APPLY email ownership pwdwork_prod',
                    EV_APPROVED_PREVIEW=json.dumps(dict(run_id='900001', sha='a' * 40)))

    def approvals(self, operation):
        return [dict(state='approved', comment='Synthetic review fixture only',
                     environments=[dict(id=101, name='pwdwork-email-migration-' + operation.lower())],
                     user=dict(id=202, login='reviewer', type='User'))]

    def preview_run(self):
        return dict(id=900001, repository=dict(full_name=gate.REPOSITORY), path=gate.WORKFLOW,
                    head_branch='main', head_sha='a' * 40, event='workflow_dispatch',
                    run_attempt=1, conclusion='success', status='completed',
                    display_title='Email migration PREVIEW')

    def invoke(self, env, responses):
        output = io.StringIO()
        # Empty environment prevents even accidental inheritance of credentials.
        with mock.patch.dict(os.environ, env, clear=True), \
                mock.patch.object(gate, 'get', side_effect=responses) as api, \
                mock.patch.object(gate.urllib.request, 'urlopen', side_effect=AssertionError('network forbidden')), \
                contextlib.redirect_stdout(output):
            result = gate.main()
        return result, output.getvalue(), api.call_args_list

    def test_preview_requires_real_review_response_before_success(self):
        result, output, calls = self.invoke(self.environment(), [self.approvals('PREVIEW')])
        self.assertEqual(result, 0)
        self.assertTrue(output.startswith('PASS:'))
        self.assertEqual(calls, [mock.call('/actions/runs/900002/approvals')])

    def test_apply_requires_review_and_completed_matching_preview(self):
        result, _, calls = self.invoke(self.environment('APPLY'),
                                       [self.approvals('APPLY'), self.preview_run()])
        self.assertEqual(result, 0)
        self.assertEqual(calls, [mock.call('/actions/runs/900002/approvals'),
                                 mock.call('/actions/runs/900001')])

    def test_private_plan_without_review_protection_stops_both_operations(self):
        for operation in ('PREVIEW', 'APPLY'):
            with self.subTest(operation=operation):
                result, output, calls = self.invoke(self.environment(operation), [[]])
                self.assertEqual(result, 1)
                self.assertTrue(output.startswith('FAIL:'))
                self.assertEqual(len(calls), 1)

    def test_api_errors_and_malformed_responses_fail_closed_without_details(self):
        for response in (TimeoutError('do-not-log-token'), PermissionError('do-not-log-token'),
                         ValueError('do-not-log-token'), None, {}, {'message': 'do-not-log-token'}):
            with self.subTest(response=type(response).__name__):
                result, output, _ = self.invoke(self.environment(), [response])
                self.assertEqual(result, 1)
                self.assertNotIn('do-not-log', output)

    def test_wrong_context_rejected_before_any_api_request(self):
        for key, value in (('GITHUB_REPOSITORY', 'other/private'), ('GITHUB_EVENT_NAME', 'push'),
                           ('GITHUB_REF', 'refs/heads/testing'), ('GITHUB_RUN_ATTEMPT', '2'),
                           ('OPERATION', 'migrate:fresh'), ('GITHUB_SHA', 'main'),
                           ('REVIEWED_SHA', 'b' * 40), ('GITHUB_RUN_ID', '../approvals')):
            with self.subTest(key=key):
                result, _, calls = self.invoke(dict(self.environment(), **{key: value}), [])
                self.assertEqual(result, 1)
                self.assertEqual(calls, [])

    def test_dispatcher_or_triggering_actor_cannot_self_approve(self):
        for actor in ('GITHUB_ACTOR', 'GITHUB_TRIGGERING_ACTOR'):
            with self.subTest(actor=actor):
                result, _, _ = self.invoke(dict(self.environment(), **{actor: 'REVIEWER'}),
                                           [self.approvals('PREVIEW')])
                self.assertEqual(result, 1)

    def test_apply_confirmation_cannot_be_replaced_by_dispatch_checkbox(self):
        result, _, calls = self.invoke(dict(self.environment('APPLY'), CONFIRMATION='true'),
                                       [self.approvals('APPLY')])
        self.assertEqual(result, 1)
        self.assertEqual(len(calls), 1)

    def test_apply_cannot_reuse_its_own_run_or_inject_an_api_path(self):
        for run_id in ('900002', '../approvals', '', 900001):
            with self.subTest(run_id=run_id):
                result, _, calls = self.invoke(
                    dict(self.environment('APPLY'), EV_APPROVED_PREVIEW=json.dumps(dict(run_id=run_id))),
                    [self.approvals('APPLY')])
                self.assertEqual(result, 1)
                self.assertEqual(len(calls), 1)

    def test_unsuccessful_preview_provenance_stops_apply(self):
        result, _, _ = self.invoke(self.environment('APPLY'),
                                   [self.approvals('APPLY'), dict(self.preview_run(), conclusion='failure')])
        self.assertEqual(result, 1)


if __name__ == '__main__':
    unittest.main()
