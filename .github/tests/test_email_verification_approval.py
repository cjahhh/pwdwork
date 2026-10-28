import importlib.util
from pathlib import Path
import unittest

spec = importlib.util.spec_from_file_location(
    'approval_gate', Path(__file__).resolve().parents[1] / 'scripts/email-verification-approval.py')
gate = importlib.util.module_from_spec(spec)
spec.loader.exec_module(gate)


class ApprovalSafetyTest(unittest.TestCase):
    def review(self, **changes):
        return dict(state='approved', environments=[{'name': 'pwdwork-email-migration-apply'}],
                    user={'login': 'independent-reviewer', 'type': 'User'}, **changes)

    def test_independent_human_approval(self):
        gate.approval([self.review()], 'pwdwork-email-migration-apply', ['dispatcher', 'dispatcher'])

    def test_missing_protection_or_bypass_is_rejected(self):
        with self.assertRaises(ValueError):
            gate.approval([], 'pwdwork-email-migration-apply', ['dispatcher'])

    def test_preview_approval_cannot_authorize_apply(self):
        with self.assertRaises(ValueError):
            gate.approval([self.review()], 'pwdwork-email-migration-preview', ['dispatcher'])

    def test_self_approval_is_case_insensitive(self):
        with self.assertRaises(ValueError):
            gate.approval([self.review()], 'pwdwork-email-migration-apply', ['INDEPENDENT-REVIEWER'])

    def test_bots_rejection_and_ambiguous_histories(self):
        for reviews in ([dict(self.review(), state='rejected')],
                        [dict(self.review(), user={'login': 'bot', 'type': 'Bot'})],
                        [self.review(), self.review()]):
            with self.subTest(reviews=reviews), self.assertRaises(ValueError):
                gate.approval(reviews, 'pwdwork-email-migration-apply', ['dispatcher'])

    def run_record(self):
        return {'id': 123, 'repository': {'full_name': 'cjahhh/pwdwork'},
                'path': gate.WORKFLOW, 'head_branch': 'main', 'head_sha': 'a' * 40,
                'event': 'workflow_dispatch', 'run_attempt': 1, 'conclusion': 'success',
                'status': 'completed', 'display_title': 'Email migration PREVIEW'}

    def test_successful_exact_preview_run(self):
        gate.provenance(self.run_record(), {'run_id': '123', 'sha': 'a' * 40}, 'a' * 40)
        gate.provenance(dict(self.run_record(), path=gate.WORKFLOW + '@main'),
                        {'run_id': '123', 'sha': 'a' * 40}, 'a' * 40)

    def test_other_releases_events_workflows_or_unsuccessful_runs_fail(self):
        for key, value in [('id', 124), ('repository', {'full_name': 'attacker/fork'}),
                           ('path', 'other.yml'), ('path', gate.WORKFLOW + '@testing'), ('head_branch', 'testing'),
                           ('head_sha', 'b' * 40), ('event', 'push'), ('run_attempt', 2),
                           ('conclusion', 'failure'), ('status', 'in_progress'),
                           ('display_title', 'Email migration APPLY')]:
            with self.subTest(key=key), self.assertRaises(ValueError):
                gate.provenance(dict(self.run_record(), **{key: value}),
                                {'run_id': '123', 'sha': 'a' * 40}, 'a' * 40)


if __name__ == '__main__':
    unittest.main()
