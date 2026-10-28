"""Offline tests of trigger authorization and evidence that can turn CI green."""

import importlib.util
import json
from pathlib import Path
import tempfile
import unittest

spec = importlib.util.spec_from_file_location(
    'incremental_ci', Path(__file__).resolve().parents[1] / 'scripts/email-verification-incremental-ci.py')
ci = importlib.util.module_from_spec(spec)
spec.loader.exec_module(ci)


class IncrementalCiSafetyTest(unittest.TestCase):
    def env(self):
        return dict(GITHUB_ACTIONS='true', RUNNER_OS='Linux', GITHUB_REPOSITORY=ci.REPOSITORY,
                    GITHUB_EVENT_NAME='push', GITHUB_REF=ci.BRANCH, GITHUB_RUN_ATTEMPT='1',
                    GITHUB_SHA='a' * 40, GITHUB_RUN_ID='123',
                    **{name: 'success' for name in ci.OUTCOMES})

    def event(self):
        return dict(ref=ci.BRANCH, after='a' * 40, repository=dict(full_name=ci.REPOSITORY),
                    deleted=False, forced=False, head_commit=dict(id='a' * 40, message=ci.SUBJECT))

    def production(self):
        return (Path(__file__).resolve().parents[1] / 'workflows/email-verification-incremental.yml').read_text()

    def test_exact_opt_in_testing_push_with_real_production_hold_is_allowed(self):
        ci.authorize(self.env(), self.event(), 'a' * 40, self.production())

    def test_other_events_branches_repositories_and_reruns_are_rejected(self):
        for key, value in (('GITHUB_ACTIONS', 'false'), ('RUNNER_OS', 'Windows'),
                           ('GITHUB_REPOSITORY', 'other/fork'), ('GITHUB_EVENT_NAME', 'pull_request'),
                           ('GITHUB_EVENT_NAME', 'pull_request_target'), ('GITHUB_EVENT_NAME', 'workflow_dispatch'),
                           ('GITHUB_REF', 'refs/heads/main'), ('GITHUB_REF', 'refs/tags/test'),
                           ('GITHUB_REF', ci.BRANCH + '-extra'), ('GITHUB_RUN_ATTEMPT', '2'),
                           ('GITHUB_SHA', 'main')):
            with self.subTest(key=key, value=value), self.assertRaises(ValueError):
                ci.authorize(dict(self.env(), **{key: value}), self.event(), 'a' * 40, self.production())

    def test_force_delete_mismatched_sha_and_ordinary_messages_are_rejected(self):
        for key, value in (('forced', True), ('forced', None), ('deleted', True),
                           ('ref', 'refs/heads/main'), ('after', 'b' * 40),
                           ('repository', {'full_name': 'other/fork'}),
                           ('head_commit', {'id': 'b' * 40, 'message': ci.SUBJECT}),
                           ('head_commit', {'id': 'a' * 40, 'message': 'ordinary commit'}),
                           ('head_commit', {'id': 'a' * 40, 'message': 'prefix ' + ci.SUBJECT}),
                           ('head_commit', {'id': 'a' * 40, 'message': ci.SUBJECT + '\nextra body'})):
            with self.subTest(key=key, value=value), self.assertRaises(ValueError):
                ci.authorize(self.env(), dict(self.event(), **{key: value}), 'a' * 40, self.production())

    def test_wrong_checkout_and_removed_production_hold_are_rejected(self):
        for head, workflow in (('b' * 40, self.production()),
                               ('a' * 40, self.production().replace('if: false &&', 'if: true &&'))):
            with self.subTest(head=head), self.assertRaises(ValueError):
                ci.authorize(self.env(), self.event(), head, workflow)

    def report(self):
        return dict(status='PASS', phase='complete',
                    checks=[dict(status='PASS', case=case, synthetic_only=True) for case in ci.CASES],
                    synthetic_only=True, production_access=False, production_approval=False,
                    github_approval_api=ci.APPROVAL_NOTE)

    def images(self):
        return dict(mysql='mysql@sha256:' + 'b' * 64, application='sha256:' + 'c' * 64)

    def write(self, temp, report=None, images=None):
        directory = temp / 'pwdwork-incremental-lab.synthetic-evidence'
        directory.mkdir()
        (directory / 'report.json').write_text(json.dumps(report if report is not None else self.report()))
        (directory / 'images.json').write_text(json.dumps(images if images is not None else self.images()))
        return directory

    def read_final(self, temp):
        return (temp / 'pwdwork-incremental-ci-evidence/report.json').read_text()

    def test_only_complete_32_case_report_with_images_and_successful_steps_passes(self):
        with tempfile.TemporaryDirectory() as directory:
            temp = Path(directory)
            self.write(temp)
            passed, summary = ci.finalize(temp, self.env())
            self.assertTrue(passed)
            final = json.loads(self.read_final(temp))
            self.assertEqual(final['passed_scenarios'], 32)
            self.assertEqual(final['status'], 'PASS')
            self.assertFalse(final['production_approval'])
            self.assertIn('32/32', summary)

    def test_missing_or_multiple_reports_never_pass(self):
        for multiple in (False, True):
            with self.subTest(multiple=multiple), tempfile.TemporaryDirectory() as directory:
                temp = Path(directory)
                if multiple:
                    self.write(temp)
                    (temp / 'pwdwork-incremental-lab.other-evidence').mkdir()
                passed, summary = ci.finalize(temp, self.env())
                self.assertFalse(passed)
                self.assertIn('FAIL', summary)

    def test_valid_partial_failure_retains_only_completed_cases_and_failed_stage(self):
        raw = dict(self.report(), status='FAIL', phase='wrong_uuid', checks=self.report()['checks'][:2])
        with tempfile.TemporaryDirectory() as directory:
            temp = Path(directory)
            self.write(temp, raw)
            passed, summary = ci.finalize(temp, self.env())
            self.assertFalse(passed)
            self.assertIn('| wrong_uuid | FAIL |', summary)
            self.assertEqual(json.loads(self.read_final(temp))['passed_scenarios'], 2)

    def test_duplicate_missing_or_unknown_cases_cannot_forge_a_complete_pass(self):
        cases = self.report()['checks']
        for changed in (cases[:-1], cases[:-1] + [cases[0]],
                        cases[:-1] + [dict(status='PASS', case='do-not-log-secret', synthetic_only=True)]):
            with self.subTest(count=len(changed)), self.assertRaises(ValueError):
                ci.validate_evidence(dict(self.report(), checks=changed), self.images())

    def test_malformed_and_secret_bearing_evidence_is_redacted_from_all_outputs(self):
        for raw in ('do-not-log-secret', json.dumps(dict(self.report(), password='do-not-log-secret')),
                    json.dumps(dict(self.report(), phase='do-not-log-secret')),
                    '{"status":"PASS","status":"do-not-log-secret"}'):
            with self.subTest(raw=raw[:20]), tempfile.TemporaryDirectory() as directory:
                temp = Path(directory)
                source = self.write(temp)
                (source / 'report.json').write_text(raw)
                passed, summary = ci.finalize(temp, self.env())
                self.assertFalse(passed)
                self.assertNotIn('do-not-log-secret', summary + self.read_final(temp))

    def test_wrong_image_identity_or_missing_identity_rejects_pass(self):
        for images in ({}, dict(self.images(), mysql='mysql:latest'),
                       dict(self.images(), application='do-not-log-secret')):
            with self.subTest(images=images), self.assertRaises(ValueError):
                ci.validate_evidence(self.report(), images)

    def test_any_failed_skipped_or_cancelled_workflow_step_prevents_green_evidence(self):
        for key in ci.OUTCOMES:
            for outcome in ('failure', 'skipped', 'cancelled', ''):
                with self.subTest(key=key, outcome=outcome), tempfile.TemporaryDirectory() as directory:
                    temp = Path(directory)
                    self.write(temp)
                    passed, _ = ci.finalize(temp, dict(self.env(), **{key: outcome}))
                    self.assertFalse(passed)

    def test_production_access_claims_are_rejected(self):
        for key in ('synthetic_only', 'production_access', 'production_approval'):
            with self.subTest(key=key), self.assertRaises(ValueError):
                ci.validate_evidence(dict(self.report(), **{key: not self.report()[key]}), self.images())


if __name__ == '__main__':
    unittest.main()
