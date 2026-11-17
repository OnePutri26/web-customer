<?php
declare(strict_types=1);

const YESNET_COMPLAINT_STATUSES = [
    'pending' => 'Pending',
    'in_progress' => 'In Progress',
    'waiting_confirmation' => 'Waiting Confirmation',
    'resolved' => 'Resolved',
    'rejected' => 'Rejected',
];

const YESNET_COMPLAINT_CATEGORIES = [
    'internet_down' => 'Internet mati',
    'slow_connection' => 'Internet lambat',
    'unstable_connection' => 'Koneksi tidak stabil',
    'modem_ont' => 'Modem / ONT bermasalah',
    'wifi_not_detected' => 'WiFi tidak terdeteksi',
    'los_red' => 'Lampu LOS merah',
    'other' => 'Gangguan lainnya',
];

function yesnetComplaintStatus(string $status): string
{
    $status = strtolower(trim($status));

    return match ($status) {
        'open', 'new', 'baru', 'verified', 'assigned' => 'pending',
        'process', 'processing', 'proses', 'in progress' => 'in_progress',
        'waiting_customer', 'waiting confirmation' => 'waiting_confirmation',
        'closed', 'selesai' => 'resolved',
        default => $status === '' ? 'pending' : $status,
    };
}

function yesnetComplaintAddHistory(
    mysqli $conn,
    int $complaintId,
    ?int $actorId,
    string $actorName,
    string $eventType,
    string $description,
    ?string $oldStatus = null,
    ?string $newStatus = null
): void {
    $stmt = $conn->prepare("
        INSERT INTO complaint_histories
            (complaint_id, actor_user_id, actor_name, event_type, description, old_status, new_status)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->bind_param(
        'iisssss',
        $complaintId,
        $actorId,
        $actorName,
        $eventType,
        $description,
        $oldStatus,
        $newStatus
    );
    $stmt->execute();
    $stmt->close();
}

function yesnetComplaintReadUpload(array $file): ?array
{
    $errorValue = $file['error'] ?? UPLOAD_ERR_NO_FILE;
    if (!is_int($errorValue)) {
        throw new RuntimeException('Data foto bukti tidak valid.');
    }
    $error = $errorValue;
    if ($error === UPLOAD_ERR_NO_FILE) {
        return null;
    }

    $tmpName = $file['tmp_name'] ?? null;
    if ($error !== UPLOAD_ERR_OK || !is_string($tmpName) || !is_uploaded_file($tmpName)) {
        throw new RuntimeException('Foto bukti gagal diunggah. Silakan pilih ulang berkas.');
    }

    $sizeValue = $file['size'] ?? null;
    if (!is_int($sizeValue)) {
        throw new RuntimeException('Data ukuran foto tidak valid.');
    }
    $size = $sizeValue;
    if ($size <= 0 || $size > 5 * 1024 * 1024) {
        throw new RuntimeException('Ukuran foto maksimal 5 MB.');
    }

    $image = @getimagesize($tmpName);
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($tmpName);
    $allowed = ['image/jpeg', 'image/png', 'image/webp'];

    if (!$image || !is_string($mime) || !in_array($mime, $allowed, true) || $image['mime'] !== $mime) {
        throw new RuntimeException('Format foto harus JPG, PNG, atau WebP.');
    }
    if ((int) $image[0] > 10000 || (int) $image[1] > 10000 || (int) $image[0] * (int) $image[1] > 40000000) {
        throw new RuntimeException('Resolusi foto terlalu besar. Silakan pilih foto yang lebih kecil.');
    }

    $contents = file_get_contents($tmpName);
    if ($contents === false) {
        throw new RuntimeException('Foto bukti tidak dapat dibaca.');
    }

    return ['mime' => $mime, 'contents' => $contents];
}
