<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';

requireRole('customer');

date_default_timezone_set('Asia/Jakarta');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('X-Content-Type-Options: nosniff');

function dashboardDataResponse(array $data, int $status = 200): never
{
    http_response_code($status);
    echo json_encode(
        $data,
        JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT
    );
    exit;
}

function dashboardDataTableExists(mysqli $conn, string $table): bool
{
    if (!preg_match('/^[a-zA-Z0-9_]+$/', $table)) {
        return false;
    }

    $stmt = $conn->prepare(
        'SELECT 1 FROM information_schema.tables
         WHERE table_schema = DATABASE() AND table_name = ?
         LIMIT 1'
    );
    $stmt->bind_param('s', $table);
    $stmt->execute();
    $stmt->store_result();
    $exists = $stmt->num_rows > 0;
    $stmt->close();
    return $exists;
}

function dashboardDataColumnExists(mysqli $conn, string $table, string $column): bool
{
    if (
        !preg_match('/^[a-zA-Z0-9_]+$/', $table) ||
        !preg_match('/^[a-zA-Z0-9_]+$/', $column)
    ) {
        return false;
    }

    $stmt = $conn->prepare(
        'SELECT 1 FROM information_schema.columns
         WHERE table_schema = DATABASE()
           AND table_name = ?
           AND column_name = ?
         LIMIT 1'
    );
    $stmt->bind_param('ss', $table, $column);
    $stmt->execute();
    $stmt->store_result();
    $exists = $stmt->num_rows > 0;
    $stmt->close();
    return $exists;
}

function dashboardDataRows(mysqli_stmt $stmt): array
{
    if (method_exists($stmt, 'get_result')) {
        $result = $stmt->get_result();
        if ($result instanceof mysqli_result) {
            $rows = $result->fetch_all(MYSQLI_ASSOC);
            $result->free();
            return $rows;
        }
    }

    $metadata = $stmt->result_metadata();
    if (!$metadata) {
        return [];
    }

    $row = [];
    $bind = [];
    foreach ($metadata->fetch_fields() as $field) {
        $row[$field->name] = null;
        $bind[] = &$row[$field->name];
    }

    call_user_func_array([$stmt, 'bind_result'], $bind);
    $rows = [];
    while ($stmt->fetch()) {
        $copy = [];
        foreach ($row as $key => $value) {
            $copy[$key] = $value;
        }
        $rows[] = $copy;
    }

    $metadata->free();

    return $rows;
}

try {
    $userId = (int) ($_SESSION['user_id'] ?? 0);
    if ($userId <= 0) {
        dashboardDataResponse(['error' => 'Sesi pelanggan tidak valid.'], 401);
    }

    $customerColumns = ['id'];
    $areaColumn = null;
    foreach (['network_area_id', 'area_id'] as $candidate) {
        if (dashboardDataColumnExists($conn, 'customers', $candidate)) {
            $areaColumn = $candidate;
            $customerColumns[] = $candidate;
            break;
        }
    }

    $customerStmt = $conn->prepare(
        'SELECT ' . implode(', ', $customerColumns) . '
         FROM customers
         WHERE user_id = ?
         LIMIT 1'
    );
    $customerStmt->bind_param('i', $userId);
    $customerStmt->execute();
    $customerRows = dashboardDataRows($customerStmt);
    $customerStmt->close();

    if ($customerRows === []) {
        dashboardDataResponse(['error' => 'Data pelanggan tidak ditemukan.'], 404);
    }

    $customer = $customerRows[0];
    $customerId = (int) ($customer['id'] ?? 0);

    $invoice = [
        'available' => false,
        'has_open' => false,
        'amount' => 0,
        'due_date' => null,
    ];
    if (
        dashboardDataTableExists($conn, 'tagihan') &&
        dashboardDataColumnExists($conn, 'tagihan', 'id_customer')
    ) {
        $invoice['available'] = true;
        $statusCondition = '';
        if (dashboardDataColumnExists($conn, 'tagihan', 'status')) {
            $statusCondition = " AND LOWER(status) IN
                ('unpaid', 'pending', 'belum bayar', 'belum dibayar')";
        }

        $order = dashboardDataColumnExists($conn, 'tagihan', 'jatuh_tempo')
            ? ' ORDER BY jatuh_tempo ASC'
            : '';
        $invoiceStmt = $conn->prepare(
            'SELECT * FROM tagihan WHERE id_customer = ?'
            . $statusCondition . $order . ' LIMIT 1'
        );
        $invoiceStmt->bind_param('i', $customerId);
        $invoiceStmt->execute();
        $invoiceRows = dashboardDataRows($invoiceStmt);
        $invoiceStmt->close();

        if ($invoiceRows !== []) {
            $row = $invoiceRows[0];
            $invoice['has_open'] = true;
            foreach (['jumlah', 'total', 'nominal', 'amount'] as $amountColumn) {
                if (isset($row[$amountColumn])) {
                    $invoice['amount'] = is_numeric($row[$amountColumn])
                        ? (float) $row[$amountColumn]
                        : 0;
                    break;
                }
            }
            foreach (['jatuh_tempo', 'tanggal_jatuh_tempo'] as $dueColumn) {
                if (!empty($row[$dueColumn])) {
                    $invoice['due_date'] = (string) $row[$dueColumn];
                    break;
                }
            }
        }
    }

    $telemetry = [
        'available' => false,
        'is_live' => false,
        'download_mbps' => null,
        'upload_mbps' => null,
        'ping_ms' => null,
        'updated_at' => null,
        'readings' => [],
    ];

    $telemetryColumns = [
        'customer_id',
        'download_mbps',
        'upload_mbps',
        'ping_ms',
        'recorded_at',
    ];
    $hasTelemetry = dashboardDataTableExists($conn, 'usage_data');
    foreach ($telemetryColumns as $column) {
        $hasTelemetry = $hasTelemetry
            && dashboardDataColumnExists($conn, 'usage_data', $column);
    }

    if ($hasTelemetry) {
        $telemetryStmt = $conn->prepare(
            'SELECT download_mbps, upload_mbps, ping_ms, recorded_at
             FROM usage_data
             WHERE customer_id = ?
             ORDER BY recorded_at DESC
             LIMIT 20'
        );
        $telemetryStmt->bind_param('i', $customerId);
        $telemetryStmt->execute();
        $readings = dashboardDataRows($telemetryStmt);
        $telemetryStmt->close();

        if ($readings !== []) {
            $latest = $readings[0];
            foreach (['download_mbps', 'upload_mbps', 'ping_ms'] as $metric) {
                $telemetry[$metric] = is_numeric($latest[$metric] ?? null)
                    ? (float) $latest[$metric]
                    : null;
            }

            $telemetry['updated_at'] = (string) ($latest['recorded_at'] ?? '');
            $updatedAt = strtotime($telemetry['updated_at']);
            $telemetry['is_live'] = $updatedAt !== false
                && $updatedAt <= time()
                && $updatedAt >= time() - 300;
            $telemetry['available'] = $telemetry['download_mbps'] !== null
                || $telemetry['upload_mbps'] !== null
                || $telemetry['ping_ms'] !== null;

            foreach (array_reverse($readings) as $reading) {
                $telemetry['readings'][] = [
                    'recorded_at' => (string) ($reading['recorded_at'] ?? ''),
                    'download_mbps' => is_numeric($reading['download_mbps'] ?? null)
                        ? (float) $reading['download_mbps']
                        : null,
                    'upload_mbps' => is_numeric($reading['upload_mbps'] ?? null)
                        ? (float) $reading['upload_mbps']
                        : null,
                ];
            }
        }
    }

    $network = [
        'available' => false,
        'status' => 'unknown',
        'area' => null,
        'title' => 'Status wilayah belum tersedia',
        'description' => 'Integrasi area pelanggan belum dikonfigurasi.',
        'estimated_resolution' => null,
        'history' => [],
    ];

    if (
        $areaColumn !== null &&
        dashboardDataTableExists($conn, 'network_areas') &&
        dashboardDataColumnExists($conn, 'network_areas', 'id') &&
        dashboardDataColumnExists($conn, 'network_areas', 'nama_area') &&
        dashboardDataColumnExists($conn, 'network_areas', 'status')
    ) {
        $areaId = (int) ($customer[$areaColumn] ?? 0);
        if ($areaId > 0) {
            $areaStmt = $conn->prepare(
                'SELECT nama_area, status FROM network_areas WHERE id = ? LIMIT 1'
            );
            $areaStmt->bind_param('i', $areaId);
            $areaStmt->execute();
            $areaRows = dashboardDataRows($areaStmt);
            $areaStmt->close();

            if ($areaRows !== []) {
                $area = $areaRows[0];
                $rawStatus = strtolower(trim((string) ($area['status'] ?? '')));
                $network['available'] = true;
                $network['area'] = (string) ($area['nama_area'] ?? '');
                $network['status'] = match ($rawStatus) {
                    'normal', 'aktif', 'active', 'online', 'sehat' => 'normal',
                    'maintenance', 'pemeliharaan', 'perawatan' => 'maintenance',
                    'gangguan', 'down', 'offline', 'terganggu' => 'outage',
                    default => 'unknown',
                };
                $network['title'] = match ($network['status']) {
                    'normal' => 'Jaringan area normal',
                    'maintenance' => 'Jaringan area dalam pemeliharaan',
                    'outage' => 'Terdapat gangguan jaringan area',
                    default => 'Status jaringan area belum tersedia',
                };
                $network['description'] = $network['status'] === 'normal'
                    ? 'Status berasal dari konfigurasi area jaringan YESNET.'
                    : 'Status berasal dari data area jaringan YESNET.';

                if (
                    dashboardDataTableExists($conn, 'network_incidents') &&
                    dashboardDataColumnExists($conn, 'network_incidents', 'area_id') &&
                    dashboardDataColumnExists($conn, 'network_incidents', 'judul') &&
                    dashboardDataColumnExists($conn, 'network_incidents', 'mulai')
                ) {
                    $incidentSelect = ['judul', 'mulai'];
                    foreach (['deskripsi', 'estimasi_selesai'] as $column) {
                        if (dashboardDataColumnExists($conn, 'network_incidents', $column)) {
                            $incidentSelect[] = $column;
                        }
                    }

                    $incidentStmt = $conn->prepare(
                        'SELECT ' . implode(', ', $incidentSelect) . '
                         FROM network_incidents
                         WHERE area_id = ?
                         ORDER BY mulai DESC
                         LIMIT 5'
                    );
                    $incidentStmt->bind_param('i', $areaId);
                    $incidentStmt->execute();
                    $incidentRows = dashboardDataRows($incidentStmt);
                    $incidentStmt->close();

                    if ($incidentRows !== []) {
                        foreach ($incidentRows as $incident) {
                            $network['history'][] = [
                                'title' => (string) ($incident['judul'] ?? ''),
                                'description' => (string) ($incident['deskripsi'] ?? ''),
                                'started_at' => (string) ($incident['mulai'] ?? ''),
                                'estimated_resolution' => $incident['estimasi_selesai'] ?? null,
                            ];
                        }

                        if ($network['status'] !== 'normal') {
                            $latestIncident = $incidentRows[0];
                            $network['title'] = (string) ($latestIncident['judul'] ?? $network['title']);
                            $network['description'] = trim((string) ($latestIncident['deskripsi'] ?? ''))
                                ?: $network['description'];
                            $network['estimated_resolution'] = $latestIncident['estimasi_selesai'] ?? null;
                        }
                    }
                }
            }
        }
    }

    $notifications = [];
    $unreadNotifications = 0;
    $hasNotifications = dashboardDataTableExists($conn, 'notifications');
    foreach (['id_user', 'judul', 'message', 'type', 'dibaca', 'created_at'] as $column) {
        $hasNotifications = $hasNotifications
            && dashboardDataColumnExists($conn, 'notifications', $column);
    }

    if ($hasNotifications) {
        $unreadStmt = $conn->prepare(
            'SELECT COUNT(*) AS total
             FROM notifications
             WHERE (id_user = ? OR id_user IS NULL)
               AND dibaca = 0'
        );
        $unreadStmt->bind_param('i', $userId);
        $unreadStmt->execute();
        $unreadRows = dashboardDataRows($unreadStmt);
        $unreadStmt->close();
        $unreadNotifications = (int) ($unreadRows[0]['total'] ?? 0);

        $notificationsStmt = $conn->prepare(
            'SELECT id, judul, message, type, dibaca, created_at
             FROM notifications
             WHERE id_user = ? OR id_user IS NULL
             ORDER BY created_at DESC
             LIMIT 4'
        );
        $notificationsStmt->bind_param('i', $userId);
        $notificationsStmt->execute();
        $notifications = dashboardDataRows($notificationsStmt);
        $notificationsStmt->close();
    }

    dashboardDataResponse([
        'data' => [
            'telemetry' => $telemetry,
            'invoice' => $invoice,
            'network' => $network,
            'notifications' => $notifications,
            'unread_notifications' => $unreadNotifications,
            'checked_at' => date(DATE_ATOM),
        ],
    ]);
} catch (Throwable $e) {
    error_log('Customer dashboard polling failed: ' . $e->getMessage());
    dashboardDataResponse(['error' => 'Data dashboard gagal diperbarui.'], 500);
}
