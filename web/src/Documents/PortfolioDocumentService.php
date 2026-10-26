<?php
declare(strict_types=1);

/**
 * Stores applicant evidence outside the public web root.
 *
 * Only PDF is accepted until ADFC confirms the supported evidence formats.
 * This service does not scan PDFs for malware; production deployment must add
 * an approved scanning/review control before documents are opened by staff.
 */
final class PortfolioDocumentService
{
    public const DEFAULT_MAX_BYTES = 10485760; // 10 MiB

    /**
     * @param array{name:string,type:string,tmp_name:string,error:int,size:int} $file
     * @return array{document_id:int,document_type:string,version_number:int,original_filename:string,mime_type:string,file_size_bytes:int,sha256:string,uploaded_at:string}
     */
    public function upload(mysqli $db, int $userId, int $applicationId, string $documentType, array $file): array
    {
        if ($userId < 1 || $applicationId < 1 ||
            !preg_match('/^[a-z][a-z0-9_]{0,59}$/D', $documentType)) {
            throw new InvalidArgumentException('Invalid upload identifiers.');
        }

        $tmpName = $file['tmp_name'] ?? '';
        $originalName = $file['name'] ?? '';
        $errorCode = $file['error'] ?? UPLOAD_ERR_NO_FILE;
        $reportedSize = $file['size'] ?? 0;

        if ($errorCode !== UPLOAD_ERR_OK || !is_string($tmpName) || $tmpName === '' ||
            !is_string($originalName) || !is_int($reportedSize) || $reportedSize < 1) {
            throw new DomainException('The uploaded file is missing or incomplete.');
        }

        $originalName = $this->validateOriginalFilename($originalName);
        $maxBytes = self::configuredMaxBytes();
        if ($reportedSize > $maxBytes) {
            throw new LengthException('The uploaded file exceeds the allowed size.');
        }

        if (!is_file($tmpName) || !is_readable($tmpName)) {
            throw new DomainException('The uploaded file cannot be read.');
        }
        $actualSize = filesize($tmpName);
        if (!is_int($actualSize) || $actualSize < 1 || $actualSize > $maxBytes || $actualSize !== $reportedSize) {
            throw new DomainException('The uploaded file size is invalid.');
        }

        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mimeType = $finfo->file($tmpName);
        if ($mimeType !== 'application/pdf') {
            throw new DomainException('Only valid PDF documents are accepted.');
        }

        $handle = fopen($tmpName, 'rb');
        if ($handle === false) {
            throw new DomainException('The uploaded file cannot be read.');
        }
        try {
            $header = fread($handle, 1024);
        } finally {
            fclose($handle);
        }
        if (!is_string($header) || strpos($header, '%PDF-') === false) {
            throw new DomainException('The uploaded file is not a PDF document.');
        }

        $sha256 = hash_file('sha256', $tmpName);
        if (!is_string($sha256)) {
            throw new RuntimeException('Unable to calculate document digest.');
        }

        $storageRoot = self::privateStorageRoot();
        $storageKey = bin2hex(random_bytes(32));
        $destination = $storageRoot . DIRECTORY_SEPARATOR . $storageKey;
        $storedFile = false;
        $db->begin_transaction();

        try {
            // Lock the parent application to serialize version numbering and
            // prevent uploads after the application leaves an editable state.
            $application = $db->prepare(
                'SELECT applicant_user_id, program_id, application_status
                 FROM applications WHERE application_id = ? FOR UPDATE'
            );
            $application->bind_param('i', $applicationId);
            $application->execute();
            $applicationRow = $application->get_result()->fetch_assoc();
            $application->close();

            if (!is_array($applicationRow) ||
                (int) $applicationRow['applicant_user_id'] !== $userId) {
                throw new AuthorizationDeniedException('Document upload is not permitted.');
            }
            if (!in_array($applicationRow['application_status'], ['draft', 'needs_correction'], true)) {
                throw new DomainException('Documents can only be uploaded to editable applications.');
            }

            $requirement = $db->prepare(
                'SELECT max_file_size_bytes FROM program_requirements
                 WHERE program_id = ? AND requirement_key = ? AND is_active = TRUE
                 LIMIT 1'
            );
            $programId = (int) $applicationRow['program_id'];
            $requirement->bind_param('is', $programId, $documentType);
            $requirement->execute();
            $requirementRow = $requirement->get_result()->fetch_assoc();
            $requirement->close();
            if (!is_array($requirementRow)) {
                throw new DomainException('The document requirement is unavailable for this program.');
            }

            $requirementMax = $requirementRow['max_file_size_bytes'];
            if ($requirementMax !== null && $actualSize > (int) $requirementMax) {
                throw new LengthException('The uploaded file exceeds the requirement size limit.');
            }

            $nextVersion = $db->prepare(
                'SELECT COALESCE(MAX(version_number), 0) + 1 AS next_version
                 FROM application_documents WHERE application_id = ? AND document_type = ?'
            );
            $nextVersion->bind_param('is', $applicationId, $documentType);
            $nextVersion->execute();
            $versionNumber = (int) $nextVersion->get_result()->fetch_assoc()['next_version'];
            $nextVersion->close();
            if ($versionNumber < 1) {
                throw new RuntimeException('Unable to allocate a document version.');
            }

            if (!move_uploaded_file($tmpName, $destination)) {
                throw new RuntimeException('Unable to store uploaded document.');
            }
            $storedFile = true;
            @chmod($destination, 0600);

            $insert = $db->prepare(
                "INSERT INTO application_documents
                    (application_id, uploaded_by, document_type, version_number, storage_key,
                     original_filename, detected_mime_type, file_size_bytes, sha256, review_status)
                 VALUES (?, ?, ?, ?, ?, ?, 'application/pdf', ?, ?, 'pending')"
            );
            $insert->bind_param(
                'iisissis',
                $applicationId,
                $userId,
                $documentType,
                $versionNumber,
                $storageKey,
                $originalName,
                $actualSize,
                $sha256
            );
            $insert->execute();
            $documentId = (int) $db->insert_id;
            $insert->close();

            $actionKey = 'portfolio_document.uploaded';
            $entityType = 'application_document';
            $outcome = 'success';
            $metadata = json_encode([
                'document_type' => $documentType,
                'version_number' => $versionNumber,
                'file_size_bytes' => $actualSize,
                'mime_type' => 'application/pdf',
                'sha256' => $sha256,
            ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            $audit = $db->prepare(
                'INSERT INTO audit_logs (actor_user_id, action_key, entity_type, entity_id, outcome, metadata)
                 VALUES (?, ?, ?, ?, ?, ?)'
            );
            $audit->bind_param('ississ', $userId, $actionKey, $entityType, $documentId, $outcome, $metadata);
            $audit->execute();
            $audit->close();

            $db->commit();
        } catch (Throwable $error) {
            $db->rollback();
            if ($storedFile && is_file($destination)) {
                @unlink($destination);
            }
            throw $error;
        }

        return [
            'document_id' => $documentId,
            'document_type' => $documentType,
            'version_number' => $versionNumber,
            'original_filename' => $originalName,
            'mime_type' => 'application/pdf',
            'file_size_bytes' => $actualSize,
            'sha256' => $sha256,
            'uploaded_at' => gmdate('c'),
        ];
    }

    private function validateOriginalFilename(string $name): string
    {
        if ($name === '' || strlen($name) > 255 ||
            preg_match('/[\x00-\x1F\x7F]/', $name) ||
            str_contains($name, ':') || str_contains($name, '/') || str_contains($name, '\\') ||
            !preg_match('/\.pdf$/iD', $name)) {
            throw new DomainException('The filename must be a simple PDF filename.');
        }

        return $name;
    }

    private static function configuredMaxBytes(): int
    {
        $configured = getenv('EQUIPATH_MAX_UPLOAD_BYTES');
        if ($configured === false || $configured === '') {
            return self::DEFAULT_MAX_BYTES;
        }
        if (!ctype_digit($configured) || (int) $configured < 1 || (int) $configured > self::DEFAULT_MAX_BYTES) {
            throw new RuntimeException('Upload size configuration is invalid.');
        }

        return (int) $configured;
    }

    private static function privateStorageRoot(): string
    {
        $configured = getenv('EQUIPATH_PRIVATE_STORAGE_PATH');
        if (!is_string($configured) || trim($configured) === '') {
            throw new RuntimeException('Private document storage is not configured.');
        }

        if (!is_dir($configured) && !mkdir($configured, 0700, true) && !is_dir($configured)) {
            throw new RuntimeException('Unable to initialize private document storage.');
        }
        @chmod($configured, 0700);
        $storageRoot = realpath($configured);
        if ($storageRoot === false || !is_writable($storageRoot)) {
            throw new RuntimeException('Private document storage is not writable.');
        }

        $webRoot = $_SERVER['DOCUMENT_ROOT'] ?? '';
        if (is_string($webRoot) && $webRoot !== '') {
            $resolvedWebRoot = realpath($webRoot);
            if ($resolvedWebRoot !== false) {
                $prefix = rtrim($resolvedWebRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
                if (strcasecmp($storageRoot, $resolvedWebRoot) === 0 ||
                    strncasecmp($storageRoot . DIRECTORY_SEPARATOR, $prefix, strlen($prefix)) === 0) {
                    throw new RuntimeException('Document storage must be outside the public web root.');
                }
            }
        }

        return $storageRoot;
    }
}
