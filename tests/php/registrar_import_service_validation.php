<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/web/src/Integration/RegistrarImportService.php';

$service = new RegistrarImportService();
$passed = 0;

function assertImport(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

function assertImportRejected(callable $action, string $message): void
{
    try {
        $action();
    } catch (InvalidArgumentException) {
        return;
    }
    throw new RuntimeException($message);
}

try {
    $csv = "external_record_id,application_number,course_code,course_title,approved_credits\n" .
        "EXT-001,APP-001,IT101,\"Introduction to \"\"Computing\"\"\",3.00\n";
    $rows = $service->parseCsv($csv);
    assertImport(count($rows) === 1, 'Valid CSV should parse exactly one data row.');
    assertImport($rows[0]['external_record_id'] === 'EXT-001', 'External record ID was not preserved.');
    assertImport($rows[0]['course_title'] === 'Introduction to "Computing"', 'Quoted CSV field was not decoded correctly.');
    $passed++;

    $bomCsv = "\xEF\xBB\xBF" . "external_record_id,application_number,course_code,course_title,approved_credits\nEXT-002,APP-002,IT102,Networks,3.5\n";
    assertImport($service->parseCsv($bomCsv)[0]['external_record_id'] === 'EXT-002', 'UTF-8 BOM should be accepted.');
    $passed++;

    assertImportRejected(fn() => $service->parseCsv(''), 'Empty input must be rejected.');
    assertImportRejected(
        fn() => $service->parseCsv("external_record_id,application_number,course_code,course_title\nEXT,APP,IT,Title\n"),
        'Unexpected or missing CSV headers must be rejected.'
    );
    assertImportRejected(
        fn() => $service->parseCsv("external_record_id,application_number,course_code,course_title,approved_credits\nEXT,APP,IT,Title,3,EXTRA\n"),
        'Rows with too many columns must be rejected.'
    );
    assertImportRejected(
        fn() => $service->parseCsv("external_record_id,application_number,course_code,course_title,approved_credits\n"),
        'Header-only CSV must be rejected.'
    );
    assertImportRejected(
        fn() => $service->parseCsv(str_repeat('x', RegistrarImportService::MAX_CSV_BYTES + 1)),
        'CSV exceeding the byte limit must be rejected.'
    );
    $passed += 5;

    fwrite(STDOUT, "PASS: registrar import parser validation ({$passed} assertions/groups).\n");
} catch (Throwable $error) {
    fwrite(STDERR, 'FAIL: registrar import parser validation — ' . $error->getMessage() . "\n");
    exit(1);
}
