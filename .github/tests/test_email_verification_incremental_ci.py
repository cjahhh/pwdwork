"""Offline tests of trigger authorization and evidence that can turn CI green."""

import importlib.util
import json
import re
import subprocess
import sys
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

    def diagnostic(self):
        return dict(operation='preview_result', category='tls', runner_phase='tls_connection', mysql_code=2026)

    def test_first_scenario_failure_diagnostic_reaches_final_report_and_summary(self):
        with tempfile.TemporaryDirectory() as directory:
            temp = Path(directory)
            raw = dict(self.report(), status='FAIL', phase='preview_readonly', checks=[], diagnostic=self.diagnostic())
            self.write(temp, raw)
            passed, summary = ci.finalize(temp, dict(self.env(), HARNESS_OUTCOME='failure'))
            self.assertFalse(passed)
            final = json.loads(self.read_final(temp))
            self.assertEqual(final['diagnostic'], self.diagnostic())
            self.assertEqual(final['phase'], 'preview_readonly')
            self.assertEqual(final['passed_scenarios'], 0)
            self.assertIn('`tls_connection`', summary)

    def test_helper_diagnostic_is_captured_before_private_output_is_deleted(self):
        with tempfile.TemporaryDirectory() as directory:
            source = Path(directory) / 'private-result.json'
            source.write_text(json.dumps(dict(status='FAIL', phase='disposable_runner_assertion', diagnostic=self.diagnostic())))
            self.assertEqual(ci.capture_diagnostic(source, 'helper_exit', 'helper'), self.diagnostic())

    def test_malformed_private_helper_output_uses_fixed_shell_fallback(self):
        fallback = dict(operation='container_start', category='docker', runner_phase=None, mysql_code=None)
        for content in ('synthetic-secret-private-stderr', '{bad json',
                        json.dumps(dict(status='FAIL', phase='disposable_runner_assertion',
                                        diagnostic=dict(self.diagnostic(), category='synthetic-secret')))):
            with self.subTest(content=content[:10]), tempfile.TemporaryDirectory() as directory:
                source = Path(directory) / 'private-result.json'
                source.write_text(content)
                self.assertEqual(ci.capture_diagnostic(source, 'container_start', 'docker'), fallback)

    def test_diagnostic_fields_are_strictly_allowlisted_and_never_make_pass(self):
        for key, value in (('operation', 'synthetic-secret'), ('category', 'synthetic-secret'),
                           ('runner_phase', 'synthetic-secret'), ('mysql_code', 99999),
                           ('mysql_code', '2026'), ('mysql_code', 2026.0), ('private_sql', 'synthetic-secret')):
            with self.subTest(key=key, value=value), self.assertRaises(ValueError):
                ci.validate_diagnostic(dict(self.diagnostic(), **{key: value}))
        with self.assertRaises(ValueError):
            ci.validate_evidence(dict(self.report(), diagnostic=self.diagnostic()), self.images())

    def test_actual_cleanup_heredoc_keeps_failed_helper_diagnostics(self):
        root = Path(__file__).resolve().parents[2]
        shell = (root / '.github/scripts/email-verification-incremental-rehearsal.sh').read_text()
        bodies = re.findall(r"(?ms)^[ \t]*python3[^\n]*<<'PY'\n(.*?)^PY\s*$", shell)
        self.assertEqual(len(bodies), 4)
        for body in bodies:
            compile(body, '<embedded Python>', 'exec')
        with tempfile.TemporaryDirectory() as directory:
            temp = Path(directory)
            evidence = self.write(temp)
            helper = temp / 'private-result.json'
            helper.write_text(json.dumps(dict(status='FAIL', phase='disposable_runner_assertion', diagnostic=self.diagnostic())))
            result = subprocess.run([sys.executable, '-B', '-c', bodies[0], str(evidence), 'preview_readonly', '1',
                                     str(helper), 'helper_exit', 'helper'], cwd=root, capture_output=True, text=True)
            self.assertEqual(result.returncode, 1)
            self.assertEqual(result.stderr, '')
            raw = json.loads((evidence / 'report.json').read_text())
            self.assertEqual(raw['diagnostic'], self.diagnostic())
            self.assertEqual(raw['checks'], [])
            passed, _ = ci.finalize(temp, dict(self.env(), HARNESS_OUTCOME='failure'))
            self.assertFalse(passed)
            self.assertEqual(json.loads(self.read_final(temp))['diagnostic'], self.diagnostic())
            # Complete successful evidence must not inherit a stale helper failure.
            for case in ci.CASES:
                (evidence / ('case-' + case + '.json')).write_text(json.dumps(
                    dict(status='PASS', case=case, synthetic_only=True)))
            result = subprocess.run([sys.executable, '-B', '-c', bodies[0], str(evidence), 'complete', '0',
                                     str(helper), 'case_evidence', 'helper'], cwd=root, capture_output=True, text=True)
            self.assertEqual(result.returncode, 0)
            self.assertEqual(result.stderr, '')
            raw = json.loads((evidence / 'report.json').read_text())
            self.assertEqual(raw['status'], 'PASS')
            self.assertNotIn('diagnostic', raw)
            passed, _ = ci.finalize(temp, self.env())
            self.assertTrue(passed)

    def comparison(self):
        return dict(table='users', before_rows=5, after_rows=5,
                    checks=dict(definition=False, row_count=True, data_fingerprint=True,
                                column_definitions=True, column_defaults=True, indexes=True,
                                foreign_keys=True, auto_increment_metadata=False,
                                definition_parse=True, definition_tokens=False, definition_header=True,
                                definition_clause_set=True, definition_columns=True, definition_primary_key=True,
                                definition_indexes=True, definition_foreign_keys=True, definition_checks=True,
                                definition_other_clauses=True, definition_table_options=True,
                                definition_auto_increment_option=True, definition_fk_default_actions=True))

    def test_restore_comparison_survives_actual_cleanup_capture_and_final_summary(self):
        root = Path(__file__).resolve().parents[2]
        shell = (root / '.github/scripts/email-verification-incremental-rehearsal.sh').read_text()
        body = re.findall(r"(?ms)^[ \t]*python3[^\n]*<<'PY'\n(.*?)^PY\s*$", shell)[0]
        detail = dict(operation='restore_compare', category='validation', runner_phase=None,
                      mysql_code=None, comparison=self.comparison())
        with tempfile.TemporaryDirectory() as directory:
            temp = Path(directory)
            evidence = self.write(temp)
            helper = temp / 'private-result.json'
            helper.write_text(json.dumps(dict(status='FAIL', phase='disposable_runner_assertion', diagnostic=detail)))
            result = subprocess.run([sys.executable, '-B', '-c', body, str(evidence), 'preview_readonly', '1',
                                     str(helper), 'helper_exit', 'helper'], cwd=root, capture_output=True, text=True)
            self.assertEqual(result.returncode, 1)
            self.assertEqual(result.stderr, '')
            passed, summary = ci.finalize(temp, dict(self.env(), HARNESS_OUTCOME='failure'))
            self.assertFalse(passed)
            final = json.loads(self.read_final(temp))
            self.assertEqual(final['diagnostic'], detail)
            self.assertEqual(final['passed_scenarios'], 0)
            self.assertIn('Comparison table: `users`', summary)
            self.assertIn('| auto_increment_metadata | false |', summary)
            self.assertIn('| data_fingerprint | true |', summary)
            self.assertIn('| definition_clause_set | true |', summary)
            self.assertIn('| definition_tokens | false |', summary)

    def test_diagnostic_equivalence_does_not_promote_a_raw_definition_failure_to_pass(self):
        checks = {key: True for key in ci.COMPARISON_CHECKS}
        checks['definition'] = False
        comparison = dict(table='accommodation_request_events', before_rows=0, after_rows=0, checks=checks)
        detail = dict(operation='restore_compare', category='validation', runner_phase=None,
                      mysql_code=None, comparison=comparison)
        with tempfile.TemporaryDirectory() as directory:
            temp = Path(directory)
            self.write(temp, dict(self.report(), status='FAIL', phase='preview_readonly', checks=[], diagnostic=detail))
            passed, summary = ci.finalize(temp, dict(self.env(), HARNESS_OUTCOME='failure'))
            self.assertFalse(passed)
            final = json.loads(self.read_final(temp))
            self.assertEqual(final['diagnostic'], detail)
            self.assertEqual(final['passed_scenarios'], 0)
            self.assertEqual(final['status'], 'FAIL')
            self.assertIn('| definition | false |', summary)

    def test_comparison_schema_rejects_private_names_values_hashes_and_non_boolean_outcomes(self):
        for changed in (dict(self.comparison(), table='synthetic-private-table'),
                        dict(self.comparison(), before_rows='synthetic-private-value'),
                        dict(self.comparison(), before_rows=True), dict(self.comparison(), after_rows=-1),
                        dict(self.comparison(), raw_ddl='synthetic-private-definition'),
                        dict(self.comparison(), checks={'definition': 'synthetic-private-hash'}),
                        dict(self.comparison(), checks={'definition_tokens': 'synthetic-private-sql'}),
                        dict(self.comparison(), checks={'definition_parse': 0}),
                        dict(self.comparison(), checks={'synthetic-private-field': False}),
                        dict(self.comparison(), checks={'definition': True})):
            with self.subTest(changed=changed), self.assertRaises(ValueError):
                ci.validate_comparison(changed)

    def test_invalid_nested_comparison_is_redacted_from_artifact_and_summary(self):
        detail = dict(operation='restore_compare', category='validation', runner_phase=None, mysql_code=None,
                      comparison=dict(self.comparison(), raw_default='synthetic-private-password'))
        with tempfile.TemporaryDirectory() as directory:
            temp = Path(directory)
            self.write(temp, dict(self.report(), status='FAIL', phase='preview_readonly', checks=[], diagnostic=detail))
            passed, summary = ci.finalize(temp, dict(self.env(), HARNESS_OUTCOME='failure'))
            self.assertFalse(passed)
            final = self.read_final(temp)
            self.assertNotIn('synthetic-private', summary + final)
            self.assertNotIn('comparison', json.loads(final).get('diagnostic', {}))

    def test_all_reviewed_table_names_and_safe_comparison_categories_are_accepted(self):
        for name in ci.TABLE_NAMES:
            with self.subTest(table=name):
                ci.validate_comparison(dict(self.comparison(), table=name))
        ci.validate_comparison(dict(table=None, before_rows=None, after_rows=None,
                                    checks={key: False for key in ci.COMPARISON_CHECKS}))


if __name__ == '__main__':
    unittest.main()
