"""Branch-only disposable CI authorization and allowlisted evidence; no API calls."""

import json
import os
from pathlib import Path
import re
import subprocess
import sys

REPOSITORY = 'cjahhh/pwdwork'
BRANCH = 'refs/heads/security/email-verification-mysql-rehearsal'
SUBJECT = '[run-email-verification-incremental-rehearsal]'
APPROVAL_NOTE = 'mocked offline; live environment not tested'
CASES = (
    'preview_readonly', 'apply_success', 'preview_unapproved', 'apply_unapproved',
    'wrong_database', 'wrong_host', 'wrong_port', 'wrong_uuid', 'wrong_ca',
    'wrong_tls_hostname', 'changed_migration', 'extra_migration', 'changed_image_migration',
    'partial_one', 'partial_two', 'unrecorded', 'already_applied', 'history_missing',
    'history_unknown', 'history_duplicate', 'backup_missing', 'backup_hash', 'backup_expired',
    'preview_missing', 'preview_hash', 'preview_expired', 'preview_wrong_sha',
    'preview_wrong_schema', 'schema_drift', 'advisory_lock', 'enrollment_enabled', 'rerun',
)
PHASES = set(CASES) | {
    'preparation', 'images', 'synthetic_credentials_and_tls', 'isolated_mysql',
    'existing_baseline_and_fixture', 'synthetic_backup', 'complete',
}
OUTCOMES = ('AUTHORIZATION_OUTCOME', 'OFFLINE_OUTCOME', 'SYNTAX_OUTCOME', 'HARNESS_OUTCOME')
OPERATIONS = {
    'unknown', 'prepare', 'image_pull', 'image_build', 'tls_fixture', 'mysql_create', 'mysql_start',
    'mysql_ready', 'database_reset', 'database_restore', 'synthetic_backup', 'case_evidence',
    'container_create', 'container_network', 'container_start', 'container_exit', 'helper_exit',
    'container_boundary', 'network_boundary', 'load_runner', 'fixture_credentials',
    'connect_target', 'connect_baseline', 'baseline_snapshot', 'restored_snapshot', 'restore_compare', 'baseline_state',
    'original_snapshot', 'server_identity', 'evidence_fixture', 'prepare_scenario', 'before_snapshot',
    'runner_start', 'runner_output', 'runner_receipt', 'preview_result', 'apply_result',
    'postflight_state', 'preservation', 'repeat_result', 'expected_rejection', 'preview_rejection',
    'baseline_preservation',
}
CATEGORIES = {'mysql_permissions', 'mysql_schema', 'mysql_lock', 'mysql_connectivity', 'tls',
              'database', 'invalid_json', 'validation', 'php_runtime', 'unexpected',
              'runner_protocol', 'docker', 'mysql_command', 'command', 'helper'}
RUNNER_PHASES = {'authorization', 'reviewed_source', 'backup_proof', 'tls_connection', 'schema_history',
                 'original_snapshot', 'laravel_runtime', 'compiled_ddl', 'preview_evidence',
                 'final_preflight', 'single_migration', 'postflight'}
MYSQL_CODES = {1044, 1045, 1049, 1054, 1060, 1142, 1146, 1205, 2002, 2003, 2006, 2013, 2026}
TABLE_NAMES = {'users', 'password_reset_tokens', 'sessions', 'cache', 'cache_locks', 'jobs', 'job_batches',
               'failed_jobs', 'personal_access_tokens', 'accommodations', 'applicant_profiles', 'employers',
               'job_listings', 'applicant_accommodation', 'job_listing_accommodation', 'applications',
               'saved_jobs', 'user_notifications', 'user_reports', 'admin_audit_logs', 'accommodation_requests',
               'accommodation_request_messages', 'accommodation_request_events', 'resumes',
               'resume_application_shares', 'migrations'}
COLUMN_NAMES = {'accommodation_request_events': {'id', 'accommodation_request_id', 'actor_id', 'actor_role',
                'action_key', 'action_hash', 'action', 'before_status', 'after_status', 'explanation', 'snapshot',
                'version', 'created_at', 'updated_at'}}
COLUMN_CHECKS = {'tokens_equal', 'parser_metadata_before', 'parser_metadata_after', 'column_order',
                 'metadata_equal', 'ordinal', 'data_type', 'default', 'nullability', 'extra',
                 'character_set', 'collation', 'generation', 'charset_spelling_only', 'all_columns_charset_spelling'}
TOKEN_KINDS = {'end', 'identifier', 'literal', 'comment', 'number', 'character_set', 'collation', 'data_type',
               'nullability', 'default', 'generated', 'auto_increment', 'visibility', 'symbol', 'attribute'}
COMPARISON_CHECKS = {'table_inventory', 'table_order', 'snapshot_structure', 'definition', 'row_count',
                     'data_fingerprint', 'migration_history', 'primary_key', 'column_definitions',
                     'column_defaults', 'indexes', 'foreign_keys', 'table_options',
                     'auto_increment_metadata', 'definition_line_set', 'metadata_read',
                     'definition_parse', 'definition_tokens', 'definition_header', 'definition_clause_set',
                     'definition_columns', 'definition_primary_key', 'definition_indexes',
                     'definition_foreign_keys', 'definition_checks', 'definition_other_clauses',
                     'definition_table_options', 'definition_auto_increment_option',
                     'definition_fk_default_actions', 'definition_snapshot_before', 'definition_snapshot_after'}


def demand(condition):
    if not condition:
        raise ValueError('disposable_ci_check_failed')


def authorize(env, event, head, production_workflow):
    demand(env.get('GITHUB_ACTIONS') == 'true' and env.get('RUNNER_OS') == 'Linux')
    demand(env.get('GITHUB_REPOSITORY') == REPOSITORY)
    demand(env.get('GITHUB_EVENT_NAME') == 'push' and env.get('GITHUB_REF') == BRANCH)
    demand(env.get('GITHUB_RUN_ATTEMPT') == '1')
    sha = env.get('GITHUB_SHA', '')
    demand(re.fullmatch(r'[a-f0-9]{40}', sha) and head == sha)
    demand(event.get('ref') == BRANCH and event.get('after') == sha)
    demand(event.get('repository', {}).get('full_name') == REPOSITORY)
    demand(event.get('deleted') is False and event.get('forced') is False)
    demand(event.get('head_commit', {}).get('id') == sha)
    demand(event.get('head_commit', {}).get('message') == SUBJECT)
    # A regression of the reviewed release hold must stop the test before building.
    demand(re.search(r'^    if: false && github\.repository == \'cjahhh/pwdwork\' && '
                     r'github\.ref == \'refs/heads/main\'\s*$', production_workflow, re.M))


def load_json(path):
    demand(path.is_file() and not path.is_symlink() and path.stat().st_size < 128_000)

    def unique(pairs):
        result = {}
        for key, value in pairs:
            demand(key not in result)
            result[key] = value
        return result

    return json.loads(path.read_text(encoding='utf-8'), object_pairs_hook=unique)


def validate_comparison(value):
    expected = {'table', 'checks', 'before_rows', 'after_rows'}
    demand(isinstance(value, dict) and set(value) in (expected, expected | {'column'}))
    demand(value['table'] is None or value['table'] in TABLE_NAMES)
    checks = value['checks']
    demand(isinstance(checks, dict) and bool(checks) and set(checks) <= COMPARISON_CHECKS)
    demand(all(type(outcome) is bool for outcome in checks.values()) and not all(checks.values()))
    for key in ('before_rows', 'after_rows'):
        demand(value[key] is None or (type(value[key]) is int and 0 <= value[key] <= 2**53 - 1))
    if 'column' in value:
        demand(checks.get('definition') is False and checks.get('definition_columns') is False
               and checks.get('definition_parse') is True)
        column = value['column']
        demand(isinstance(column, dict) and set(column) == {'ordinal', 'before_name', 'after_name',
               'before_token_kind', 'after_token_kind', 'checks'})
        demand(type(column['ordinal']) is int and 1 <= column['ordinal'] <= 4096)
        for key in ('before_name', 'after_name'):
            demand(column[key] is None or column[key] in COLUMN_NAMES.get(value['table'], set()))
        for key in ('before_token_kind', 'after_token_kind'):
            demand(column[key] in TOKEN_KINDS)
        demand(isinstance(column['checks'], dict) and set(column['checks']) == COLUMN_CHECKS
               and all(type(outcome) is bool for outcome in column['checks'].values())
               and column['checks']['tokens_equal'] is False)
    return dict(value)


def validate_diagnostic(value):
    expected = {'operation', 'category', 'runner_phase', 'mysql_code'}
    demand(isinstance(value, dict) and set(value) in (expected, expected | {'comparison'}))
    demand(value['operation'] in OPERATIONS and value['category'] in CATEGORIES)
    demand(value['runner_phase'] is None or value['runner_phase'] in RUNNER_PHASES)
    demand(value['mysql_code'] is None or (type(value['mysql_code']) is int and value['mysql_code'] in MYSQL_CODES))
    if 'comparison' in value:
        validate_comparison(value['comparison'])
    return dict(value)


def capture_diagnostic(path, operation, category):
    """Read only fixed-vocabulary helper diagnostics; never emit raw output."""
    fallback = dict(operation=operation if operation in OPERATIONS else 'unknown',
                    category=category if category in CATEGORIES else 'unexpected',
                    runner_phase=None, mysql_code=None)
    try:
        helper = load_json(path)
        demand(set(helper) == {'status', 'phase', 'diagnostic'})
        demand(helper['status'] == 'FAIL' and helper['phase'] == 'disposable_runner_assertion')
        return validate_diagnostic(helper['diagnostic'])
    except Exception:
        return fallback


def validate_evidence(raw, images):
    demand(isinstance(raw, dict) and isinstance(images, dict))
    expected = {'status', 'phase', 'checks', 'synthetic_only', 'production_access',
                'production_approval', 'github_approval_api'}
    demand(set(raw) in (expected, expected | {'diagnostic'}))
    if 'diagnostic' in raw:
        demand(raw['status'] == 'FAIL')
        validate_diagnostic(raw['diagnostic'])
    demand(raw['status'] in ('PASS', 'FAIL') and raw['phase'] in PHASES)
    demand(raw['synthetic_only'] is True and raw['production_access'] is False
           and raw['production_approval'] is False and raw['github_approval_api'] == APPROVAL_NOTE)
    demand(isinstance(raw['checks'], list) and len(raw['checks']) <= len(CASES))
    checks = {}
    for check in raw['checks']:
        demand(set(check) == {'status', 'case', 'synthetic_only'})
        demand(check['case'] in CASES and check['case'] not in checks)
        demand(check['status'] == 'PASS' and check['synthetic_only'] is True)
        checks[check['case']] = 'PASS'
    if images:
        demand(set(images) == {'mysql', 'application'})
        demand(re.fullmatch(r'(?:docker.io/library/)?mysql@sha256:[a-f0-9]{64}', images['mysql']))
        demand(re.fullmatch(r'sha256:[a-f0-9]{64}', images['application']))
    if raw['status'] == 'PASS':
        demand(raw['phase'] == 'complete' and set(checks) == set(CASES) and bool(images))
    return checks


def finalize(temp, env):
    """Never copy raw input to artifacts or diagnostics, including on parse errors."""
    checks, images, phase, diagnostic = {}, {}, 'ci_evidence', None
    outcomes = {key.removesuffix('_OUTCOME').lower(): env.get(key) == 'success' for key in OUTCOMES}
    passed = False
    try:
        candidates = list(temp.glob('pwdwork-incremental-lab.*-evidence'))
        demand(len(candidates) == 1 and not candidates[0].is_symlink())
        directory = candidates[0]
        raw = load_json(directory / 'report.json')
        identities = load_json(directory / 'images.json') if (directory / 'images.json').exists() else {}
        checks = validate_evidence(raw, identities)
        images, phase = identities, raw['phase']
        diagnostic = validate_diagnostic(raw['diagnostic']) if 'diagnostic' in raw else None
        passed = raw['status'] == 'PASS' and all(outcomes.values())
    except Exception:
        # No unvalidated stage, image, exception, path or user-supplied data is emitted.
        checks, images, phase, diagnostic = {}, {}, 'ci_evidence', None
    sha = env.get('GITHUB_SHA', '')
    run_id = env.get('GITHUB_RUN_ID', '')
    identity_valid = bool(re.fullmatch(r'[a-f0-9]{40}', sha) and re.fullmatch(r'[1-9][0-9]*', run_id))
    passed = passed and identity_valid
    report = dict(format=1, status='PASS' if passed else 'FAIL', phase=phase,
                  commit=sha if re.fullmatch(r'[a-f0-9]{40}', sha) else None,
                  run_id=run_id if re.fullmatch(r'[1-9][0-9]*', run_id) else None,
                  repository=REPOSITORY, branch=BRANCH, required_scenarios=len(CASES),
                  passed_scenarios=len(checks), workflow_checks=outcomes,
                  checks=checks, images=images, synthetic_only=True, production_access=False,
                  production_approval=False, github_approval_api=APPROVAL_NOTE)
    if diagnostic is not None:
        report['diagnostic'] = diagnostic
    destination = temp / 'pwdwork-incremental-ci-evidence'
    demand(not destination.is_symlink())
    destination.mkdir(exist_ok=True)
    for name in ('report.json', 'summary.md'):
        demand(not (destination / name).is_symlink())
    (destination / 'report.json').write_text(json.dumps(report, indent=2) + '\n', encoding='utf-8')
    lines = ['# Disposable incremental migration runner', '',
             f'**{report["status"]}**: {len(checks)}/{len(CASES)} scenarios passed; phase: `{phase}`.',
             f'Commit: `{report["commit"]}`; run: `{report["run_id"]}`.', '',
             'Synthetic MySQL 8.4.11 only. No production access or rollout approval.',
             'GitHub approval API is mocked offline; live approval enforcement remains unverified.', '',
             '| Scenario | Result |', '| --- | --- |']
    if diagnostic is not None:
        lines[8:8] = [f'Diagnostic: operation `{diagnostic["operation"]}`; category `{diagnostic["category"]}`; '
                      f'runner phase `{diagnostic["runner_phase"]}`; native code `{diagnostic["mysql_code"]}`.', '']
    lines += [f'| {case} | {checks.get(case, "FAIL" if case == phase else "NOT RUN")} |' for case in CASES]
    if diagnostic is not None and 'comparison' in diagnostic:
        comparison = diagnostic['comparison']
        lines += ['', f'Comparison table: `{comparison["table"]}`; rows before: `{comparison["before_rows"]}`; '
                  f'rows after: `{comparison["after_rows"]}`.', '',
                  '| Comparison category | Equal |', '| --- | --- |']
        lines += [f'| {name} | {str(equal).lower()} |' for name, equal in comparison['checks'].items()]
        if 'column' in comparison:
            column = comparison['column']
            lines += ['', f'First differing column ordinal: `{column["ordinal"]}`; '
                      f'before: `{column["before_name"]}`; after: `{column["after_name"]}`.',
                      f'First differing token classes: `{column["before_token_kind"]}` / `{column["after_token_kind"]}`.',
                      '', '| Column diagnostic | Outcome |', '| --- | --- |']
            lines += [f'| {name} | {str(outcome).lower()} |' for name, outcome in column['checks'].items()]
    summary = '\n'.join(lines) + '\n'
    (destination / 'summary.md').write_text(summary, encoding='utf-8')
    return passed, summary


def main(arguments):
    try:
        if arguments == ['authorize']:
            root = Path(__file__).resolve().parents[2]
            event = load_json(Path(os.environ['GITHUB_EVENT_PATH']))
            head = subprocess.run(['git', 'rev-parse', 'HEAD'], check=True, capture_output=True,
                                  text=True, cwd=root).stdout.strip()
            authorize(os.environ, event, head,
                      (root / '.github/workflows/email-verification-incremental.yml').read_text())
            print('PASS: exact opt-in testing push; production workflow held')
            return 0
        demand(arguments == ['finalize'])
        passed, summary = finalize(Path(os.environ['RUNNER_TEMP']), os.environ)
        with open(os.environ['GITHUB_STEP_SUMMARY'], 'a', encoding='utf-8') as output:
            output.write(summary)
        print('PASS: all 32 disposable scenarios and CI checks' if passed else
              'FAIL: incomplete or invalid disposable evidence; no rollout approval')
        return 0 if passed else 1
    except Exception:
        print('FAIL: disposable CI authorization/evidence check; details withheld')
        return 1


if __name__ == '__main__':
    sys.exit(main(sys.argv[1:]))
