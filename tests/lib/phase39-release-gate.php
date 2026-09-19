<?php
/**
 * Phase 3.9 — offline automated release gate (consumes Phase 3.8 evaluation output).
 */
declare(strict_types=1);

const PHASE39_GATE_VERSION = '3.9.0';

/** @return list<string> */
function phase39_gate_statuses(): array
{
    return ['PASS', 'FAIL', 'BLOCKED'];
}

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function phase39_validate_gate_input(array $input): array
{
    $errors = [];
    if (!isset($input['evaluation_report']) || !is_array($input['evaluation_report'])) {
        $errors[] = 'missing evaluation_report';
    } else {
        $er = $input['evaluation_report'];
        if (!isset($er['scenario_results']) || !is_array($er['scenario_results'])) {
            $errors[] = 'evaluation_report.scenario_results missing';
        }
    }
    if (!isset($input['regression']) || !is_array($input['regression'])) {
        $errors[] = 'missing regression suite block';
    }
    foreach (['agent_core_mind', 'phase36', 'phase37', 'phase38_harness'] as $key) {
        if (!isset($input['regression'][$key]) || !is_array($input['regression'][$key])) {
            $errors[] = 'regression.' . $key . ' missing';
        }
    }

    return $errors;
}

/**
 * @param array<string, mixed> $input
 * @param array<string, mixed> $policy
 * @return array<string, mixed>
 */
function phase39_run_release_gate(array $input, array $policy = []): array
{
    $releaseCandidate = (string) ($input['release_candidate'] ?? '');
    $failures = [];
    $warnings = [];
    $criteria = [];

    $inputErrors = phase39_validate_gate_input($input);
    if ($inputErrors !== []) {
        return phase39_gate_result('BLOCKED', $releaseCandidate, $criteria, [
            [
                'code'     => 'INVALID_GATE_INPUT',
                'severity' => 'BLOCKED',
                'message'  => implode('; ', $inputErrors),
            ],
        ], $warnings, $input, $policy);
    }

    $evalReport = $input['evaluation_report'];
    $baselineCmp = is_array($input['baseline_comparison'] ?? null) ? $input['baseline_comparison'] : null;
    $scenarioReq = is_array($policy['scenario_requirements'] ?? null) ? $policy['scenario_requirements'] : [];
    $suiteReq = is_array($policy['suite_requirements'] ?? null) ? $policy['suite_requirements'] : [];
    $regressionExceptions = is_array($policy['regression_exceptions'] ?? null) ? $policy['regression_exceptions'] : [];
    $baselineRequired = !empty($policy['baseline_required']);

    // A–D: suite regressions
    foreach ($suiteReq as $suiteKey => $req) {
        if (!is_array($req)) {
            continue;
        }
        $block = $input['regression'][$suiteKey] ?? [];
        $passed = (int) ($block['passed'] ?? 0);
        $failed = (int) ($block['failed'] ?? 0);
        $required = (int) ($req['required_pass'] ?? 0);
        $label = (string) ($req['label'] ?? $suiteKey);
        $observed = $passed . '/' . ($passed + $failed);
        $ok = $failed === 0 && $passed >= $required;
        $criteria[] = ['id' => $suiteKey, 'status' => $ok ? 'PASS' : 'FAIL', 'observed' => $observed];
        if (!$ok) {
            $failures[] = [
                'code'     => 'REGRESSION_SUITE_FAILED',
                'severity' => 'FAIL',
                'message'  => $label . ' below required ' . $required . ' (observed ' . $observed . ')',
            ];
        }
    }

    // E: critical failures (any in evaluation report)
    $critCount = (int) ($evalReport['critical_failure_count'] ?? 0);
    $criteria[] = ['id' => 'critical_failures', 'status' => $critCount === 0 ? 'PASS' : 'FAIL', 'observed' => (string) $critCount];
    if ($critCount > 0) {
        foreach (is_array($evalReport['scenario_results'] ?? null) ? $evalReport['scenario_results'] : [] as $row) {
            foreach (is_array($row['critical_failures'] ?? null) ? $row['critical_failures'] : [] as $cf) {
                $failures[] = [
                    'code'        => 'CRITICAL_FAILURE',
                    'severity'    => 'FAIL',
                    'scenario_id' => (string) ($row['scenario_id'] ?? ''),
                    'message'     => 'Critical failure: ' . $cf,
                ];
            }
        }
    }

    // Required vs optional scenarios + assertions
    $reqScenarios = 0;
    $reqPass = 0;
    $reqFail = 0;
    $reqBlocked = 0;
    $reqNotVerified = 0;

    foreach (is_array($evalReport['scenario_results'] ?? null) ? $evalReport['scenario_results'] : [] as $row) {
        $sid = (string) ($row['scenario_id'] ?? '');
        $st = (string) ($row['status'] ?? 'NOT_VERIFIED');
        $reqMeta = is_array($scenarioReq[$sid] ?? null) ? $scenarioReq[$sid] : [];
        $requiredScenario = !array_key_exists($sid, $scenarioReq) || !empty($reqMeta['required_for_release']);

        foreach (is_array($row['assertions'] ?? null) ? $row['assertions'] : [] as $as) {
            $aid = (string) ($as['assertion_id'] ?? '');
            $ast = (string) ($as['status'] ?? '');
            $assertRequired = $requiredScenario && empty($as['optional_for_release']);
            if (!$assertRequired) {
                if ($ast === 'FAIL') {
                    $warnings[] = [
                        'code'        => 'OPTIONAL_ASSERTION_FAILURE',
                        'scenario_id' => $sid,
                        'assertion_id'=> $aid,
                        'message'     => 'Optional assertion failed',
                    ];
                } elseif ($ast === 'NOT_VERIFIED') {
                    $warnings[] = [
                        'code'        => 'OPTIONAL_ASSERTION_NOT_VERIFIED',
                        'scenario_id' => $sid,
                        'assertion_id'=> $aid,
                        'message'     => 'Optional assertion not verified',
                    ];
                }
                continue;
            }
            if ($ast === 'FAIL') {
                $failures[] = [
                    'code'        => 'REQUIRED_ASSERTION_FAILURE',
                    'severity'    => 'FAIL',
                    'scenario_id' => $sid,
                    'assertion_id'=> $aid,
                    'message'     => (string) ($as['reason'] ?? 'required assertion failed'),
                ];
            } elseif (in_array($ast, ['NOT_VERIFIED', 'BLOCKED'], true)) {
                $failures[] = [
                    'code'        => $ast === 'BLOCKED' ? 'REQUIRED_SCENARIO_BLOCKED' : 'REQUIRED_EVIDENCE_NOT_VERIFIED',
                    'severity'    => 'BLOCKED',
                    'scenario_id' => $sid,
                    'assertion_id'=> $aid,
                    'message'     => (string) ($as['reason'] ?? 'required evidence not verified'),
                ];
            }
        }

        if (!$requiredScenario) {
            if ($st === 'FAIL') {
                $warnings[] = [
                    'code'        => 'OPTIONAL_SCENARIO_FAILURE',
                    'scenario_id' => $sid,
                    'message'     => 'Optional scenario failed (does not fail gate alone)',
                ];
            }
            continue;
        }

        $reqScenarios++;
        if ($st === 'PASS') {
            $reqPass++;
        } elseif ($st === 'FAIL') {
            $reqFail++;
            $failures[] = [
                'code'        => 'REQUIRED_SCENARIO_FAILURE',
                'severity'    => 'FAIL',
                'scenario_id' => $sid,
                'message'     => 'Required scenario status FAIL',
            ];
        } elseif ($st === 'BLOCKED') {
            $reqBlocked++;
            $failures[] = [
                'code'        => 'REQUIRED_SCENARIO_BLOCKED',
                'severity'    => 'BLOCKED',
                'scenario_id' => $sid,
                'message'     => 'Required scenario BLOCKED',
            ];
        } elseif ($st === 'NOT_VERIFIED') {
            $reqNotVerified++;
            $failures[] = [
                'code'        => 'REQUIRED_EVIDENCE_NOT_VERIFIED',
                'severity'    => 'BLOCKED',
                'scenario_id' => $sid,
                'message'     => 'Required scenario NOT_VERIFIED',
            ];
        }
    }

    // F: baseline regressions
    if ($baselineRequired && $baselineCmp === null) {
        $failures[] = [
            'code'     => 'REGRESSION_BASELINE_UNAVAILABLE',
            'severity' => 'BLOCKED',
            'message'  => 'Baseline comparison required but missing',
        ];
    }
    if ($baselineCmp !== null) {
        foreach (is_array($baselineCmp['regressions'] ?? null) ? $baselineCmp['regressions'] : [] as $reg) {
            $sid = (string) ($reg['scenario_id'] ?? '');
            if (in_array($sid, $regressionExceptions, true)) {
                $warnings[] = [
                    'code'        => 'REGRESSION_EXCEPTION',
                    'scenario_id' => $sid,
                    'message'     => 'Acknowledged regression exception',
                ];
                continue;
            }
            $failures[] = [
                'code'        => 'REGRESSION',
                'severity'    => 'FAIL',
                'scenario_id' => $sid,
                'message'     => 'REGRESSION_DETECTED: ' . ($reg['from'] ?? '') . ' → ' . ($reg['to'] ?? ''),
            ];
        }
    }

    $summary = [
        'required_scenarios' => $reqScenarios,
        'required_passed'    => $reqPass,
        'required_failed'    => $reqFail,
        'required_blocked'   => $reqBlocked,
        'required_not_verified' => $reqNotVerified,
        'critical_failures'  => $critCount,
        'regressions'        => (int) ($baselineCmp['regression_count'] ?? 0),
        'evaluation_pass'    => (int) ($evalReport['pass_count'] ?? 0),
        'evaluation_fail'    => (int) ($evalReport['fail_count'] ?? 0),
    ];

    $status = phase39_resolve_gate_status($failures);

    return phase39_gate_result($status, $releaseCandidate, $criteria, $failures, $warnings, $input, $policy, $summary, $baselineCmp);
}

/**
 * @param list<array<string, mixed>> $failures
 */
function phase39_resolve_gate_status(array $failures): string
{
    if ($failures === []) {
        return 'PASS';
    }
    foreach ($failures as $f) {
        if (($f['severity'] ?? '') === 'BLOCKED') {
            return 'BLOCKED';
        }
    }

    return 'FAIL';
}

/**
 * @param list<array<string, mixed>> $failures
 * @param list<array<string, mixed>> $warnings
 */
function phase39_gate_result(
    string $status,
    string $releaseCandidate,
    array $criteria,
    array $failures,
    array $warnings,
    array $input,
    array $policy,
    ?array $summary = null,
    ?array $baselineCmp = null
): array {
    $evalReport = is_array($input['evaluation_report'] ?? null) ? $input['evaluation_report'] : [];

    return [
        'gate'               => 'phase39',
        'gate_version'       => PHASE39_GATE_VERSION,
        'status'             => $status,
        'release_candidate'  => $releaseCandidate,
        'criteria'           => $criteria,
        'failures'           => array_values(array_filter($failures, static fn ($f) => ($f['severity'] ?? 'FAIL') === 'FAIL')),
        'blocked_reasons'    => array_values(array_filter($failures, static fn ($f) => ($f['severity'] ?? '') === 'BLOCKED')),
        'warnings'           => $warnings,
        'evaluation_summary' => [
            'run_id'                 => $evalReport['run_id'] ?? null,
            'scenario_count'         => $evalReport['scenario_count'] ?? null,
            'pass_count'             => $evalReport['pass_count'] ?? null,
            'fail_count'             => $evalReport['fail_count'] ?? null,
            'blocked_count'          => $evalReport['blocked_count'] ?? null,
            'not_verified_count'     => $evalReport['not_verified_count'] ?? null,
            'critical_failure_count' => $evalReport['critical_failure_count'] ?? null,
        ],
        'baseline_comparison' => $baselineCmp,
        'summary'              => $summary ?? [],
    ];
}

/**
 * @param array<string, mixed> $gateResult
 */
function phase39_format_gate_report(array $gateResult): string
{
    $lines = [
        'PHASE 3.9 RELEASE GATE',
        '',
        'Release candidate:',
        (string) ($gateResult['release_candidate'] ?? ''),
        '',
        'Status:',
        (string) ($gateResult['status'] ?? ''),
        '',
    ];
    foreach ($gateResult['criteria'] ?? [] as $c) {
        if (!is_array($c)) {
            continue;
        }
        $lines[] = (string) ($c['id'] ?? 'criterion') . ': ' . (string) ($c['observed'] ?? '') . ' ' . (string) ($c['status'] ?? '');
    }
    $sum = is_array($gateResult['summary'] ?? null) ? $gateResult['summary'] : [];
    $lines[] = '';
    $lines[] = 'Critical failures: ' . (string) ($sum['critical_failures'] ?? 0);
    $lines[] = 'Regressions: ' . (string) ($sum['regressions'] ?? 0);
    $lines[] = 'Warnings: ' . count(is_array($gateResult['warnings'] ?? null) ? $gateResult['warnings'] : []);
    $lines[] = '';
    $lines[] = 'Gate:';
    $lines[] = (string) ($gateResult['status'] ?? '');
    if (($gateResult['failures'] ?? []) !== []) {
        $lines[] = '';
        $lines[] = 'Reasons:';
        foreach ($gateResult['failures'] as $f) {
            $lines[] = '- ' . ($f['code'] ?? '') . ': ' . ($f['message'] ?? '');
        }
    }
    if (($gateResult['blocked_reasons'] ?? []) !== []) {
        $lines[] = '';
        $lines[] = 'Blocked:';
        foreach ($gateResult['blocked_reasons'] as $f) {
            $lines[] = '- ' . ($f['code'] ?? '') . ': ' . ($f['message'] ?? '');
        }
    }

    return implode("\n", $lines);
}

/** @return array<string, mixed> */
function phase39_default_regression_pass(): array
{
    return [
        'agent_core_mind'   => ['passed' => 795, 'failed' => 0],
        'phase36'           => ['passed' => 45, 'failed' => 0],
        'phase37'           => ['passed' => 43, 'failed' => 0],
        'phase38_harness'   => ['passed' => 20, 'failed' => 0],
    ];
}
