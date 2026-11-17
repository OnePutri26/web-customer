<?php
declare(strict_types=1);

date_default_timezone_set('Asia/Jakarta');
require_once __DIR__ . '/auth_check.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../inc/complaint.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit('Metode permintaan tidak diizinkan.');
}

if (!adminVerifyCsrf($_POST['csrf'] ?? null)) {
    http_response_code(403);
    exit('Sesi formulir tidak valid. Muat ulang halaman lalu coba kembali.');
}

$complaintId = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT);
$statusInput = $_POST['status'] ?? '';
$assignedInput = $_POST['assigned_user_id'] ?? '';
$noteInput = $_POST['note'] ?? '';
$validTextInput = is_string($statusInput) && is_string($assignedInput) && is_string($noteInput);
$status = is_string($statusInput) ? $statusInput : '';
$assignedValue = is_string($assignedInput) ? trim($assignedInput) : '';
$note = is_string($noteInput) ? trim($noteInput) : '';
$noteLength = preg_match_all('/./us', $note);

if (
    !$validTextInput
    || !$complaintId || $complaintId < 1
    || !isset(YESNET_COMPLAINT_STATUSES[$status])
    || ($assignedValue !== '' && (!ctype_digit($assignedValue) || (int) $assignedValue < 1))
    || $noteLength === false || $noteLength > 2000
) {
    http_response_code(400);
    exit('Data perubahan laporan tidak valid.');
}

$assignedUserId = $assignedValue === '' ? null : (int) $assignedValue;
$actorId = (int) $_SESSION['user_id'];
$actorName = trim((string) ($_SESSION['nama'] ?? 'Administrator')) ?: 'Administrator';

try {
    $conn->begin_transaction();

    $stmt = $conn->prepare("
        SELECT status, assigned_user_id
        FROM complaint
        WHERE id = ?
        LIMIT 1
        FOR UPDATE
    ");
    $stmt->bind_param('i', $complaintId);
    $stmt->execute();
    $current = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$current) {
        throw new InvalidArgumentException('Laporan tidak ditemukan.');
    }

    if ($assignedUserId !== null) {
        $stmt = $conn->prepare("
            SELECT nama
            FROM users
            WHERE id = ?
              AND LOWER(role) IN ('teknisi', 'technician')
              AND LOWER(TRIM(status)) IN ('1', 'active', 'aktif')
            LIMIT 1
        ");
        $stmt->bind_param('i', $assignedUserId);
        $stmt->execute();
        $technician = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$technician) {
            throw new InvalidArgumentException('Teknisi yang dipilih tidak aktif atau tidak valid.');
        }
    } else {
        $technician = null;
    }

    $oldStatus = yesnetComplaintStatus((string) $current['status']);
    $oldAssignedId = $current['assigned_user_id'] === null ? null : (int) $current['assigned_user_id'];
    if ($assignedUserId === null) {
        $stmt = $conn->prepare('UPDATE complaint SET status = ?, assigned_user_id = NULL WHERE id = ?');
        $stmt->bind_param('si', $status, $complaintId);
    } else {
        $stmt = $conn->prepare('UPDATE complaint SET status = ?, assigned_user_id = ? WHERE id = ?');
        $stmt->bind_param('sii', $status, $assignedUserId, $complaintId);
    }
    $stmt->execute();
    $stmt->close();

    if ($oldStatus === 'pending' && ($status !== 'pending' || $oldAssignedId !== $assignedUserId || $note !== '')) {
        $stmt = $conn->prepare("
            SELECT 1 FROM complaint_histories
            WHERE complaint_id = ? AND event_type = 'accepted'
            LIMIT 1
        ");
        $stmt->bind_param('i', $complaintId);
        $stmt->execute();
        $accepted = $stmt->get_result()->num_rows > 0;
        $stmt->close();
        if (!$accepted) {
            yesnetComplaintAddHistory(
                $conn,
                $complaintId,
                $actorId,
                $actorName,
                'accepted',
                'Laporan diterima admin untuk ditangani.',
                $oldStatus,
                $status
            );
        }
    }

    if ($oldStatus !== $status) {
        yesnetComplaintAddHistory(
            $conn,
            $complaintId,
            $actorId,
            $actorName,
            $status === 'resolved' ? 'resolved' : 'status_changed',
            $status === 'resolved'
                ? 'Laporan diselesaikan.'
                : 'Status berubah dari ' . (YESNET_COMPLAINT_STATUSES[$oldStatus] ?? $oldStatus) . ' menjadi ' . YESNET_COMPLAINT_STATUSES[$status] . '.',
            $oldStatus,
            $status
        );
    }

    if ($oldAssignedId !== $assignedUserId) {
        $assignmentText = $technician
            ? 'Laporan ditugaskan kepada teknisi ' . $technician['nama'] . '.'
            : 'Penugasan teknisi laporan dihapus.';
        yesnetComplaintAddHistory(
            $conn,
            $complaintId,
            $actorId,
            $actorName,
            'assigned',
            $assignmentText
        );
    }

    if ($note !== '') {
        yesnetComplaintAddHistory(
            $conn,
            $complaintId,
            $actorId,
            $actorName,
            'note',
            $note
        );
    }

    $conn->commit();
    $_SESSION['complaint_admin_flash'] = ['type' => 'success', 'message' => 'Perubahan laporan berhasil disimpan.'];
    header('Location: complaint_detail.php?id=' . $complaintId, true, 303);
    exit;
} catch (InvalidArgumentException $e) {
    try {
        $conn->rollback();
    } catch (Throwable $rollbackError) {
        error_log('Complaint update rollback failed: ' . $rollbackError->getMessage());
    }
    http_response_code(400);
    exit($e->getMessage());
} catch (Throwable $e) {
    try {
        $conn->rollback();
    } catch (Throwable $rollbackError) {
        error_log('Complaint update rollback failed: ' . $rollbackError->getMessage());
    }
    error_log('Admin complaint update failed: ' . $e->getMessage());
    http_response_code(503);
    exit('Perubahan laporan belum dapat disimpan. Silakan coba kembali.');
}
