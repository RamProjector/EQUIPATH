<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/web/src/Documents/PortfolioDocumentService.php';

$service = new PortfolioDocumentService();
$db = mysqli_init();
$dummyFile = [
    'name' => 'portfolio.pdf',
    'type' => 'application/pdf',
    'tmp_name' => '/nonexistent/test-upload.pdf',
    'error' => UPLOAD_ERR_OK,
    'size' => 1,
];

function expectUploadRejected(callable $action, string $message): void
{
    try {
        $action();
    } catch (DomainException | LengthException) {
        return;
    }
    throw new RuntimeException($message);
}

try {
    foreach ([
        '../portfolio.pdf',
        'folder\\portfolio.pdf',
        'portfolio.pdf.php',
        'portfolio.php',
        'portfolio.pdf:payload',
        "portfolio\n.pdf",
        str_repeat('a', 252) . '.pdf',
    ] as $badName) {
        $file = $dummyFile;
        $file['name'] = $badName;
        expectUploadRejected(
            fn() => $service->upload($db, 1, 1, 'portfolio', $file),
            'Unsafe filename was accepted: ' . json_encode($badName)
        );
    }

    putenv('EQUIPATH_MAX_UPLOAD_BYTES=100');
    $oversized = $dummyFile;
    $oversized['size'] = 101;
    expectUploadRejected(
        fn() => $service->upload($db, 1, 1, 'portfolio', $oversized),
        'Upload exceeding configured size limit was accepted.'
    );
    putenv('EQUIPATH_MAX_UPLOAD_BYTES');

    foreach (['../bad', 'UPPERCASE', 'valid-key-with-dash', ''] as $badType) {
        try {
            $service->upload($db, 1, 1, $badType, $dummyFile);
        } catch (InvalidArgumentException) {
            continue;
        } catch (Throwable $error) {
            throw new RuntimeException('Unexpected exception for invalid document type: ' . get_class($error));
        }
        throw new RuntimeException('Invalid document requirement key was accepted: ' . $badType);
    }

    fwrite(STDOUT, "PASS: document upload validation (filename traversal, double extension, control characters, filename length, configured size limit, requirement-key format).\n");
} catch (Throwable $error) {
    fwrite(STDERR, 'FAIL: document upload validation — ' . $error->getMessage() . "\n");
    exit(1);
}
