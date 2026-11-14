<?php

function sendPrivateUpload(string $objectKey, string $downloadName, string $folder): void
{
    $allowedFolders = ['employee_docs', 'ot_attachments'];
    if (!in_array($folder, $allowedFolders, true) ||
        !preg_match('#^' . preg_quote($folder, '#') . '/[A-Za-z0-9._/-]+$#D', $objectKey) ||
        strpos($objectKey, '..') !== false) {
        http_response_code(404);
        exit('ไม่พบไฟล์');
    }

    $storageRoot = realpath(ROOT_PATH . '/uploads/' . $folder);
    $filePath = realpath(ROOT_PATH . '/uploads/' . str_replace('/', DIRECTORY_SEPARATOR, $objectKey));
    if ($storageRoot === false || $filePath === false ||
        strpos($filePath, $storageRoot . DIRECTORY_SEPARATOR) !== 0 ||
        !is_file($filePath) || !is_readable($filePath)) {
        http_response_code(404);
        exit('ไม่พบไฟล์');
    }

    $mimeType = (new finfo(FILEINFO_MIME_TYPE))->file($filePath);
    if (!is_string($mimeType) || !in_array($mimeType, ['application/pdf', 'image/jpeg', 'image/png'], true)) {
        http_response_code(404);
        exit('ไม่พบไฟล์');
    }

    $downloadName = basename(str_replace('\\', '/', $downloadName));
    header('Content-Type: ' . $mimeType);
    header('Content-Length: ' . filesize($filePath));
    header('Content-Disposition: attachment; filename="download"; filename*=UTF-8\'\'' . rawurlencode($downloadName));
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, no-store');
    readfile($filePath);
    exit;
}
