<?php
declare(strict_types=1);

function yesnetNotificationColumnExists(mysqli $conn, string $table, string $column): bool
{
    static $cache = [];
    $key = $table . '.' . $column;
    if (!isset($cache[$key])) {
        $stmt = $conn->prepare(
            'SELECT 1 FROM information_schema.columns
             WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ? LIMIT 1'
        );
        $stmt->bind_param('ss', $table, $column);
        $stmt->execute();
        $stmt->store_result();
        $cache[$key] = $stmt->num_rows > 0;
        $stmt->close();
    }
    return $cache[$key];
}

function yesnetNotificationTablesAvailable(mysqli $conn): bool
{
    static $available = null;
    if ($available === null) {
        $result = $conn->query("
            SELECT COUNT(*) AS total
            FROM information_schema.tables
            WHERE table_schema = DATABASE()
              AND table_name IN (
                  'yesnet_network_incidents',
                  'yesnet_customer_notifications',
                  'yesnet_customer_notification_reads'
              )
        ");
        $available = (int) $result->fetch_assoc()['total'] === 3
            && yesnetNotificationColumnExists($conn, 'yesnet_network_incidents', 'customer_id');
    }
    return $available;
}

function yesnetLegacyNotificationAvailable(mysqli $conn): bool
{
    foreach (['id', 'id_user', 'judul', 'message', 'type', 'dibaca', 'created_at'] as $column) {
        if (!yesnetNotificationColumnExists($conn, 'notifications', $column)) {
            return false;
        }
    }

    return true;
}

function yesnetLegacyNotificationList(mysqli $conn, int $userId): array
{
    if (!yesnetLegacyNotificationAvailable($conn)) {
        return [];
    }

    $stmt = $conn->prepare(
        'SELECT id, id_user, judul, message, type,
                CASE WHEN id_user IS NULL THEN 1 ELSE dibaca END AS dibaca, created_at
         FROM notifications
         WHERE id_user = ? OR id_user IS NULL
         ORDER BY created_at DESC, id DESC'
    );
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    foreach ($rows as &$row) {
        $row['source'] = 'legacy';
        $row['id'] = (int) $row['id'];
        $row['dibaca'] = (int) $row['dibaca'];
    }
    unset($row);

    return $rows;
}

function yesnetLegacyNotificationMarkRead(mysqli $conn, int $userId, int $notificationId): bool
{
    if (!yesnetLegacyNotificationAvailable($conn) || $notificationId <= 0) {
        return false;
    }

    $stmt = $conn->prepare(
        'UPDATE notifications SET dibaca = 1 WHERE id = ? AND id_user = ?'
    );
    $stmt->bind_param('ii', $notificationId, $userId);
    $stmt->execute();
    $changed = $stmt->affected_rows > 0;
    $stmt->close();

    return $changed;
}

function yesnetLegacyNotificationMarkAllRead(mysqli $conn, int $userId): void
{
    if (!yesnetLegacyNotificationAvailable($conn)) {
        return;
    }

    $stmt = $conn->prepare(
        'UPDATE notifications SET dibaca = 1 WHERE id_user = ?'
    );
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $stmt->close();
}

function yesnetNotificationCreate(
    mysqli $conn,
    int $userId,
    int $customerId,
    string $title,
    string $message,
    string $type,
    string $dedupeKey,
    ?string $actionUrl = null
): void {
    $stmt = $conn->prepare(
        'INSERT IGNORE INTO yesnet_customer_notifications
            (user_id, customer_id, judul, message, type, action_url, dedupe_key)
         VALUES (?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->bind_param('iisssss', $userId, $customerId, $title, $message, $type, $actionUrl, $dedupeKey);
    $stmt->execute();
    $stmt->close();
}

function yesnetNotificationSyncPayments(mysqli $conn, int $userId, int $customerId): void
{
    if (!yesnetNotificationTablesAvailable($conn)) {
        return;
    }
    if (!yesnetNotificationColumnExists($conn, 'tagihan', 'id_customer')
        || !yesnetNotificationColumnExists($conn, 'tagihan', 'id')
        || !yesnetNotificationColumnExists($conn, 'tagihan', 'status')) {
        return;
    }

    $dueColumn = yesnetNotificationColumnExists($conn, 'tagihan', 'jatuh_tempo')
        ? 'jatuh_tempo'
        : (yesnetNotificationColumnExists($conn, 'tagihan', 'tanggal_jatuh_tempo')
            ? 'tanggal_jatuh_tempo'
            : null);
    $amountColumn = null;
    foreach (['jumlah', 'total', 'nominal', 'amount'] as $candidate) {
        if (yesnetNotificationColumnExists($conn, 'tagihan', $candidate)) {
            $amountColumn = $candidate;
            break;
        }
    }

    $columns = ['id', 'status'];
    if ($dueColumn !== null) {
        $columns[] = $dueColumn;
    }
    if ($amountColumn !== null) {
        $columns[] = $amountColumn;
    }

    $paidInvoiceIds = [];
    if (
        yesnetNotificationColumnExists($conn, 'pembayaran', 'id_customer')
        && yesnetNotificationColumnExists($conn, 'pembayaran', 'id_tagihan')
        && yesnetNotificationColumnExists($conn, 'pembayaran', 'status')
    ) {
        $paidResult = $conn->prepare(
            "SELECT DISTINCT id_tagihan FROM pembayaran
             WHERE id_customer = ?
               AND LOWER(TRIM(status)) IN ('paid', 'lunas', 'success', 'berhasil', 'settlement', 'capture')"
        );
        $paidResult->bind_param('i', $customerId);
        $paidResult->execute();
        foreach ($paidResult->get_result()->fetch_all(MYSQLI_ASSOC) as $paidRow) {
            $paidInvoiceIds[(int) $paidRow['id_tagihan']] = true;
        }
        $paidResult->close();
    }

    $stmt = $conn->prepare(
        'SELECT ' . implode(', ', array_map(static fn (string $column): string => '`' . $column . '`', $columns)) . '
         FROM tagihan
         WHERE id_customer = ?
           AND LOWER(TRIM(status)) IN (
               \'unpaid\', \'pending\', \'overdue\', \'belum bayar\', \'belum dibayar\',
               \'belum_bayar\', \'terlambat\', \'jatuh tempo\'
           )
         ORDER BY id DESC LIMIT 100'
    );
    $stmt->bind_param('i', $customerId);
    $stmt->execute();
    $invoices = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    foreach ($invoices as $invoice) {
        $invoiceId = (int) $invoice['id'];
        if (isset($paidInvoiceIds[$invoiceId])) {
            continue;
        }
        $status = strtolower(trim((string) $invoice['status']));
        if (in_array($status, ['paid', 'lunas', 'success', 'settlement'], true)) {
            continue;
        }

        $dueDate = $dueColumn !== null ? substr((string) ($invoice[$dueColumn] ?? ''), 0, 10) : '';
        $amount = $amountColumn !== null && is_numeric($invoice[$amountColumn] ?? null)
            ? 'Rp ' . number_format((float) $invoice[$amountColumn], 0, ',', '.')
            : 'Nominal tagihan';
        $paymentUrl = '../pembayaran.php?tagihan=' . $invoiceId;

        if ($dueDate !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dueDate)) {
            $today = new DateTimeImmutable('today');
            $due = DateTimeImmutable::createFromFormat('!Y-m-d', $dueDate);
            if ($due instanceof DateTimeImmutable) {
                $daysUntilDue = (int) $today->diff($due)->format('%r%a');
                if ($daysUntilDue < 0 || in_array($status, ['overdue', 'terlambat', 'jatuh tempo'], true)) {
                    yesnetNotificationCreate(
                        $conn,
                        $userId,
                        $customerId,
                        'Tagihan melewati jatuh tempo',
                        $amount . ' telah melewati tanggal jatuh tempo. Segera selesaikan pembayaran Anda.',
                        'pembayaran',
                        'invoice:' . $invoiceId . ':overdue:' . $dueDate,
                        $paymentUrl
                    );
                } elseif ($daysUntilDue <= 7) {
                    yesnetNotificationCreate(
                        $conn,
                        $userId,
                        $customerId,
                        $daysUntilDue === 0 ? 'Tagihan jatuh tempo hari ini' : 'Pengingat pembayaran',
                        $amount . ' jatuh tempo ' . date('d M Y', $due->getTimestamp()) . '.',
                        'pembayaran',
                        'invoice:' . $invoiceId . ':reminder:' . $dueDate,
                        $paymentUrl
                    );
                }
            }
        } elseif (in_array($status, ['overdue', 'terlambat', 'jatuh tempo'], true)) {
            yesnetNotificationCreate(
                $conn,
                $userId,
                $customerId,
                'Tagihan melewati jatuh tempo',
                $amount . ' belum dibayar. Segera selesaikan pembayaran Anda.',
                'pembayaran',
                'invoice:' . $invoiceId . ':overdue',
                $paymentUrl
            );
        }
    }

    if (!yesnetNotificationColumnExists($conn, 'pembayaran', 'id_customer')
        || !yesnetNotificationColumnExists($conn, 'pembayaran', 'id')
        || !yesnetNotificationColumnExists($conn, 'pembayaran', 'status')) {
        return;
    }

    $paymentSelect = ['id', 'status'];
    $paymentAmountColumn = null;
    foreach (['jumlah', 'nominal', 'total', 'amount'] as $candidate) {
        if (yesnetNotificationColumnExists($conn, 'pembayaran', $candidate)) {
            $paymentAmountColumn = $candidate;
            $paymentSelect[] = $candidate;
            break;
        }
    }
    $paymentStmt = $conn->prepare(
        'SELECT ' . implode(', ', array_map(static fn (string $column): string => '`' . $column . '`', $paymentSelect)) . '
         FROM pembayaran WHERE id_customer = ? ORDER BY id DESC LIMIT 20'
    );
    $paymentStmt->bind_param('i', $customerId);
    $paymentStmt->execute();
    $payments = $paymentStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $paymentStmt->close();

    foreach ($payments as $payment) {
        $status = strtolower(trim((string) $payment['status']));
        $isPaid = in_array($status, ['paid', 'lunas', 'success', 'berhasil', 'settlement', 'capture'], true);
        $isPending = in_array($status, ['pending', 'menunggu', 'waiting', 'menunggu verifikasi'], true);
        if (!$isPaid && !$isPending) {
            continue;
        }
        $amount = $paymentAmountColumn !== null && is_numeric($payment[$paymentAmountColumn] ?? null)
            ? 'Rp ' . number_format((float) $payment[$paymentAmountColumn], 0, ',', '.')
            : 'Pembayaran';
        $label = $isPaid ? 'Pembayaran berhasil' : 'Pembayaran menunggu verifikasi';
        yesnetNotificationCreate(
            $conn,
            $userId,
            $customerId,
            $label,
            $isPaid
                ? $amount . ' telah diterima. Terima kasih atas pembayaran Anda.'
                : $amount . ' sudah tercatat dan sedang menunggu verifikasi.',
            'pembayaran',
            'payment:' . (int) $payment['id'] . ':' . $status,
            'billing.php'
        );
    }
}

function yesnetNotificationSyncIncidents(mysqli $conn, int $userId, int $customerId): void
{
    if (!yesnetNotificationTablesAvailable($conn)) {
        return;
    }

    $areaColumn = yesnetNotificationColumnExists($conn, 'customers', 'network_area_id')
        ? 'network_area_id'
        : (yesnetNotificationColumnExists($conn, 'customers', 'area_id') ? 'area_id' : null);
    $areaId = 0;
    if ($areaColumn !== null) {
        $customerStmt = $conn->prepare('SELECT `' . $areaColumn . '` AS area_id FROM customers WHERE id = ? LIMIT 1');
        $customerStmt->bind_param('i', $customerId);
        $customerStmt->execute();
        $areaId = (int) ($customerStmt->get_result()->fetch_assoc()['area_id'] ?? 0);
        $customerStmt->close();
    }

    if ($areaId > 0) {
        $stmt = $conn->prepare(
            'SELECT id, customer_id, judul, message, type, mulai, estimasi_selesai, version
             FROM yesnet_network_incidents
             WHERE is_active = 1 AND mulai <= NOW()
               AND (customer_id = ? OR (customer_id IS NULL AND (area_id IS NULL OR area_id = ?)))
             ORDER BY mulai DESC, id DESC'
        );
        $stmt->bind_param('ii', $customerId, $areaId);
    } else {
        $stmt = $conn->prepare(
            'SELECT id, customer_id, judul, message, type, mulai, estimasi_selesai, version
             FROM yesnet_network_incidents
             WHERE is_active = 1 AND mulai <= NOW()
               AND (customer_id = ? OR (customer_id IS NULL AND area_id IS NULL))
             ORDER BY mulai DESC, id DESC'
        );
        $stmt->bind_param('i', $customerId);
    }
    $stmt->execute();
    $incidents = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    foreach ($incidents as $incident) {
        $notificationMessage = (string) $incident['message']
            . "\nWaktu mulai: " . date('d M Y, H:i', strtotime((string) $incident['mulai'])) . ' WIB';
        if (!empty($incident['estimasi_selesai'])) {
            $notificationMessage .= "\nEstimasi pemulihan: "
                . date('d M Y, H:i', strtotime((string) $incident['estimasi_selesai'])) . ' WIB';
        }
        yesnetNotificationCreate(
            $conn,
            $userId,
            $customerId,
            (string) $incident['judul'],
            $notificationMessage,
            (string) $incident['type'],
            'incident:' . (int) $incident['id'] . ':version:' . (int) $incident['version'],
            'notifikasi.php'
        );
    }
}

function yesnetNotificationList(mysqli $conn, int $userId, int $limit = 50): array
{
    if (!yesnetNotificationTablesAvailable($conn)) {
        return [];
    }
    $limit = max(1, min(100, $limit));
    $stmt = $conn->prepare(
        'SELECT n.id, n.judul, n.message, n.type, n.action_url, n.created_at,
                IF(r.notification_id IS NULL, 0, 1) AS dibaca
         FROM yesnet_customer_notifications n
         LEFT JOIN yesnet_customer_notification_reads r
           ON r.notification_id = n.id AND r.user_id = n.user_id
         WHERE n.user_id = ?
         ORDER BY n.created_at DESC, n.id DESC
         LIMIT ' . $limit
    );
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    foreach ($rows as &$row) {
        $row['source'] = 'service';
        $row['id'] = (int) $row['id'];
        $row['dibaca'] = (int) $row['dibaca'];
    }
    unset($row);
    return $rows;
}

function yesnetNotificationUnreadCount(mysqli $conn, int $userId): int
{
    if (!yesnetNotificationTablesAvailable($conn)) {
        return 0;
    }
    $stmt = $conn->prepare(
        'SELECT COUNT(*) AS total
         FROM yesnet_customer_notifications n
         LEFT JOIN yesnet_customer_notification_reads r
           ON r.notification_id = n.id AND r.user_id = n.user_id
         WHERE n.user_id = ? AND r.notification_id IS NULL'
    );
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $count = (int) $stmt->get_result()->fetch_assoc()['total'];
    $stmt->close();
    return $count;
}

function yesnetNotificationMarkRead(mysqli $conn, int $userId, int $notificationId): bool
{
    if (!yesnetNotificationTablesAvailable($conn) || $notificationId <= 0) {
        return false;
    }
    $stmt = $conn->prepare(
        'INSERT IGNORE INTO yesnet_customer_notification_reads (notification_id, user_id)
         SELECT id, user_id FROM yesnet_customer_notifications
         WHERE id = ? AND user_id = ?'
    );
    $stmt->bind_param('ii', $notificationId, $userId);
    $stmt->execute();
    $changed = $stmt->affected_rows > 0;
    $stmt->close();
    return $changed;
}

function yesnetNotificationMarkAllRead(mysqli $conn, int $userId): void
{
    if (!yesnetNotificationTablesAvailable($conn)) {
        return;
    }
    $stmt = $conn->prepare(
        'INSERT IGNORE INTO yesnet_customer_notification_reads (notification_id, user_id)
         SELECT id, user_id FROM yesnet_customer_notifications WHERE user_id = ?'
    );
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $stmt->close();
}
