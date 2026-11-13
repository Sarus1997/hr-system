<?php
class CloudStorage
{
    private static $bucket = 'nexus-hr-cloud-storage';
    private static $useS3  = false; // เปลี่ยนเป็น true เมื่อติดตั้ง AWS S3 SDK

    public static function upload($tmpFilePath, $originalName, $folder = 'documents')
    {
        $ext       = pathinfo($originalName, PATHINFO_EXTENSION);
        $objectKey = $folder . '/' . date('Y/m/') . uniqid('cloud_') . '.' . $ext;

        if (self::$useS3) {
            // เชื่อมต่อ AWS S3 / MinIO ใน Production
        }

        $localDir = ROOT_PATH . '/uploads/' . dirname($objectKey);
        if (!is_dir($localDir) && !mkdir($localDir, 0750, true) && !is_dir($localDir)) {
            throw new RuntimeException('ไม่สามารถเตรียมพื้นที่จัดเก็บเอกสารได้');
        }

        if (!move_uploaded_file($tmpFilePath, ROOT_PATH . '/uploads/' . $objectKey)) {
            throw new RuntimeException('ไม่สามารถบันทึกเอกสารได้');
        }

        return [
            'bucket' => self::$bucket,
            'key'    => $objectKey,
            'url'    => BASE_URL . '/uploads/' . $objectKey,
        ];
    }
}
