<?php
declare(strict_types=1);

date_default_timezone_set('Asia/Jakarta');
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../inc/complaint.php';
requireRole('customer');

function complaintDetailEscape(mixed $value): string
{
    return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES, 'UTF-8');
}

$complaintId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
$userId = (int) $_SESSION['user_id'];
if (!$complaintId || $complaintId < 1) {
    http_response_code(404);
    exit('Laporan tidak ditemukan.');
}

$stmt = $conn->prepare("
    SELECT c.*
    FROM complaint c
    JOIN customers cu ON cu.id = c.id_customer
    WHERE c.id = ? AND cu.user_id = ?
    LIMIT 1
");
$stmt->bind_param('ii', $complaintId, $userId);
$stmt->execute();
$complaint = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$complaint) {
    http_response_code(404);
    exit('Laporan tidak ditemukan.');
}

$stmt = $conn->prepare("
    SELECT actor_name, event_type, description, old_status, new_status, created_at
    FROM complaint_histories
    WHERE complaint_id = ?
    ORDER BY created_at ASC, id ASC
");
$stmt->bind_param('i', $complaintId);
$stmt->execute();
$histories = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$stmt = $conn->prepare('SELECT 1 FROM complaint_attachments WHERE complaint_id = ? LIMIT 1');
$stmt->bind_param('i', $complaintId);
$stmt->execute();
$hasAttachment = $stmt->get_result()->num_rows > 0;
$stmt->close();

$status = yesnetComplaintStatus((string) $complaint['status']);
$statusLabel = YESNET_COMPLAINT_STATUSES[$status] ?? 'Pending';
$category = YESNET_COMPLAINT_CATEGORIES[$complaint['category']] ?? 'Gangguan lainnya';
$ticket = trim((string) $complaint['complaint_code']);
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Detail <?= complaintDetailEscape($ticket) ?> - YESNET</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="assets/css/theme.css" rel="stylesheet">
    <style>
        body { min-height: 100vh; padding: 32px 16px; }
        .complaint-shell { width: min(900px, 100%); margin: 0 auto; }
        .complaint-card { border: 1px solid var(--ui-border); border-radius: 18px; background: var(--ui-surface); box-shadow: var(--ui-shadow-sm); }
        .ticket-code { color: var(--ui-primary); font-size: .82rem; font-weight: 800; letter-spacing: .04em; }
        .status-pill { display: inline-flex; padding: 6px 12px; border-radius: 999px; background: var(--ui-primary-soft); color: var(--ui-primary-dark); font-size: .8rem; font-weight: 700; }
        .timeline { position: relative; margin: 20px 0 0 8px; padding-left: 26px; border-left: 2px solid #e7edf5; }
        .timeline-item { position: relative; padding: 0 0 24px; }
        .timeline-item:last-child { padding-bottom: 0; }
        .timeline-dot { position: absolute; top: 3px; left: -34px; width: 14px; height: 14px; border: 3px solid #fff; border-radius: 50%; background: var(--ui-primary); box-shadow: 0 0 0 1px #bcd1f6; }
        .timeline-time { color: var(--ui-muted); font-size: .78rem; }
        .evidence { max-width: 100%; max-height: 420px; border-radius: 12px; }
    </style>
</head>
<body>
<main class="complaint-shell">
    <a class="btn btn-outline-primary btn-sm mb-3" href="complaint.php"><i class="bi bi-arrow-left me-1"></i>Kembali ke laporan</a>
    <article class="complaint-card p-4 p-md-5 mb-3">
        <div class="d-flex flex-wrap justify-content-between align-items-start gap-3">
            <div>
                <span class="ticket-code"><?= complaintDetailEscape($ticket) ?></span>
                <h1 class="h3 mt-2 mb-1"><?= complaintDetailEscape($complaint['subject']) ?></h1>
                <div class="text-secondary small"><?= complaintDetailEscape($category) ?> · <?= complaintDetailEscape(date('d M Y, H:i', strtotime((string) $complaint['created_at']))) ?> WIB</div>
            </div>
            <span class="status-pill"><?= complaintDetailEscape($statusLabel) ?></span>
        </div>
        <hr class="my-4">
        <h2 class="h6 fw-bold">Deskripsi gangguan</h2>
        <p class="mb-0" style="white-space:pre-wrap"><?= complaintDetailEscape($complaint['description']) ?></p>
        <?php if ($hasAttachment): ?>
            <div class="mt-4">
                <h2 class="h6 fw-bold">Foto bukti</h2>
                <a href="complaint_attachment.php?id=<?= (int) $complaintId ?>" target="_blank" rel="noopener">
                    <img class="evidence mt-2" src="complaint_attachment.php?id=<?= (int) $complaintId ?>" alt="Foto bukti laporan">
                </a>
            </div>
        <?php endif; ?>
    </article>

    <section class="complaint-card p-4 p-md-5" aria-labelledby="history-title">
        <h2 class="h5 fw-bold mb-1" id="history-title">Riwayat penanganan</h2>
        <p class="text-secondary small mb-0">Setiap perkembangan laporan tercatat di sini.</p>
        <?php if ($histories === []): ?>
            <p class="text-secondary mt-4 mb-0">Belum ada aktivitas.</p>
        <?php else: ?>
            <div class="timeline">
                <?php foreach ($histories as $history): ?>
                    <article class="timeline-item">
                        <span class="timeline-dot" aria-hidden="true"></span>
                        <div class="fw-semibold"><?= complaintDetailEscape($history['description']) ?></div>
                        <div class="timeline-time mt-1">
                            <?= complaintDetailEscape(date('d M Y, H:i', strtotime((string) $history['created_at']))) ?> WIB
                            · <?= complaintDetailEscape($history['actor_name']) ?>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>
</main>
</body>
</html>
