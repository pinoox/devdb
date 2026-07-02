<?php

use Pinoox\Component\Database\DevDB\DevDbCompatibilityReport;

it('reports DevDB compatibility areas with clear statuses', function () {
    $report = (new DevDbCompatibilityReport())->mysql();

    expect($report['target'])->toBe('mysql')
        ->and($report['summary']['supported'])->toBeGreaterThan(0)
        ->and($report['summary']['partial'])->toBeGreaterThan(0)
        ->and(array_column($report['features'], 'status'))->toContain('supported', 'partial', 'metadata-only', 'unsupported')
        ->and($report['recommendation'])->toContain('local development');
});
