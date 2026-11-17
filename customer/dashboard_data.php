<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';

requireRole('customer');

date_default_timezone_set('Asia/Jakarta');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('X-Content-Type-Options: nosniff');

const DASHBOARD_JSON_FLAGS = JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT;

function dashboardDataResponse(array $data, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($data, DASHBOARD_JSON_FLAGS);
    exit;
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

/** Prepared query singkat. SQL hanya boleh disusun dari nama kolom yang sudah divalidasi. */
function dashboardDataQuery(mysqli $conn, string $sql, string $types = '', array $params = []): array
{
    $stmt = $conn->prepare($sql);
    if ($types !== '') {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $rows = dashboardDataRows($stmt);
    $stmt->close();
    return $rows;
}

function dashboardDataTableExists(mysqli $conn, string $table): bool
{
    static $cache = [];
    if (!preg_match('/^[a-zA-Z0-9_]+$/', $table)) {
        return false;
    }
    if (!isset($cache[$table])) {
        $rows = dashboardDataQuery(
            $conn,
            'SELECT 1 FROM information_schema.tables
             WHERE table_schema = DATABASE() AND table_name = ? LIMIT 1',
            's',
            [$table]
        );
        $cache[$table] = $rows !== [];
    }
    return $cache[$table];
}

function dashboardDataColumnExists(mysqli $conn, string $table, string $column): bool
{
    static $cache = [];
    if (!preg_match('/^[a-zA-Z0-9_]+$/', $table) || !preg_match('/^[a-zA-Z0-9_]+$/', $column)) {
        return false;
    }
    $key = $table . '.' . $column;
    if (!isset($cache[$key])) {
        $rows = dashboardDataQuery(
            $conn,
            'SELECT 1 FROM information_schema.columns
             WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ? LIMIT 1',
            'ss',
            [$table, $column]
        );
        $cache[$key] = $rows !== [];
    }
    return $cache[$key];
}

function dashboardDataHasColumns(mysqli $conn, string $table, array $columns): bool
{
    if (!dashboardDataTableExists($conn, $table)) {
        return false;
    }
    foreach ($columns as $column) {
        if (!dashboardDataColumnExists($conn, $table, $column)) {
            return false;
        }
    }
    return true;
}

function dashboardDataNumber(mixed $value): ?float
{
    return is_numeric($value) ? (float) $value : null;
}

try {
    $userId = (int) ($_SESSION['user_id'] ?? 0);
    if ($userId <= 0) {
        dashboardDataResponse(['error' => 'Sesi pelanggan tidak valid.'], 401);
    }

    /* ---------------- CUSTOMER ---------------- */

    $customerColumns = ['id'];
    $areaColumn = null;
    foreach (['network_area_id', 'area_id'] as $candidate) {
        if (dashboardDataColumnExists($conn, 'customers', $candidate)) {
            $areaColumn = $candidate;
            $customerColumns[] = $candidate;
            break;
        }
    }

    $customerRows = dashboardDataQuery(
        $conn,
        'SELECT ' . implode(', ', $customerColumns) . ' FROM customers WHERE user_id = ? LIMIT 1',
        'i',
        [$userId]
    );

    if ($customerRows === []) {
        dashboardDataResponse(['error' => 'Data pelanggan tidak ditemukan.'], 404);
    }

    $customer = $customerRows[0];
    $customerId = (int) ($customer['id'] ?? 0);

    /* ---------------- TAGIHAN ---------------- */

    $invoice = [
        'available' => false,
        'has_open'  => false,
        'id'        => 0,
        'number'    => null,
        'amount'    => 0,
        'due_date'  => null,
    ];

    if (dashboardDataHasColumns($conn, 'tagihan', ['id_customer', 'status'])) {
        $invoice['available'] = true;

        $sql = "SELECT * FROM tagihan
                WHERE id_customer = ?
                  AND LOWER(TRIM(status)) IN (
                      'unpaid', 'pending', 'overdue', 'belum bayar', 'belum dibayar',
                      'belum_bayar', 'terlambat', 'jatuh tempo'
                  )";
        if (dashboardDataColumnExists($conn, 'tagihan', 'jatuh_tempo')) {
            $sql .= ' ORDER BY jatuh_tempo ASC';
        }
        $sql .= ' LIMIT 1';

        $invoiceRows = dashboardDataQuery($conn, $sql, 'i', [$customerId]);

        if ($invoiceRows !== []) {
            $row = $invoiceRows[0];
            $invoice['has_open'] = true;
            $invoice['id'] = (int) ($row['id'] ?? 0);

            foreach (['nomor_tagihan', 'invoice', 'kode_tagihan'] as $numberColumn) {
                if (!empty($row[$numberColumn])) {
                    $invoice['number'] = (string) $row[$numberColumn];
                    break;
                }
            }
            foreach (['jumlah', 'total', 'nominal', 'amount'] as $amountColumn) {
                if (isset($row[$amountColumn])) {
                    $invoice['amount'] = dashboardDataNumber($row[$amountColumn]) ?? 0;
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

    /* ---------------- TELEMETRI ---------------- */

    $telemetry = [
        'available'     => false,
        'is_live'       => false,
        'download_mbps' => null,
        'upload_mbps'   => null,
        'ping_ms'       => null,
        'updated_at'    => null,
        'readings'      => [],
    ];

    if (dashboardDataHasColumns($conn, 'usage_data', ['customer_id', 'download_mbps', 'upload_mbps', 'ping_ms', 'recorded_at'])) {
        $readings = dashboardDataQuery(
            $conn,
            'SELECT download_mbps, upload_mbps, ping_ms, recorded_at
             FROM usage_data WHERE customer_id = ?
             ORDER BY recorded_at DESC LIMIT 20',
            'i',
            [$customerId]
        );

        if ($readings !== []) {
            $latest = $readings[0];
            foreach (['download_mbps', 'upload_mbps', 'ping_ms'] as $metric) {
                $telemetry[$metric] = dashboardDataNumber($latest[$metric] ?? null);
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
                    'recorded_at'   => (string) ($reading['recorded_at'] ?? ''),
                    'download_mbps' => dashboardDataNumber($reading['download_mbps'] ?? null),
                    'upload_mbps'   => dashboardDataNumber($reading['upload_mbps'] ?? null),
                ];
            }
        }
    }

    /* ---------------- STATUS JARINGAN PER WILAYAH ---------------- */

    $network = [
        'available'            => false,
        'status'               => 'unknown',
        'area'                 => null,
        'title'                => 'Status wilayah belum tersedia',
        'description'          => 'Integrasi area pelanggan belum dikonfigurasi.',
        'estimated_resolution' => null,
        'history'              => [],
    ];

    if (
        $areaColumn !== null &&
        dashboardDataHasColumns($conn, 'network_areas', ['id', 'nama_area', 'status'])
    ) {
        $areaId = (int) ($customer[$areaColumn] ?? 0);

        if ($areaId > 0) {
            $areaRows = dashboardDataQuery(
                $conn,
                'SELECT nama_area, status FROM network_areas WHERE id = ? LIMIT 1',
                'i',
                [$areaId]
            );

            if ($areaRows !== []) {
                $area = $areaRows[0];
                $rawStatus = strtolower(trim((string) ($area['status'] ?? '')));

                $network['available'] = true;
                $network['area'] = (string) ($area['nama_area'] ?? '');
                $network['status'] = match ($rawStatus) {
                    'normal', 'aktif', 'active', 'online', 'sehat' => 'normal',
                    'maintenance', 'pemeliharaan', 'perawatan'     => 'maintenance',
                    'gangguan', 'down', 'offline', 'terganggu'     => 'outage',
                    default                                        => 'unknown',
                };
                $network['title'] = match ($network['status']) {
                    'normal'      => 'Jaringan area normal',
                    'maintenance' => 'Jaringan area dalam pemeliharaan',
                    'outage'      => 'Terdapat gangguan jaringan area',
                    default       => 'Status jaringan area belum tersedia',
                };
                $network['description'] = $network['status'] === 'normal'
                    ? 'Status berasal dari konfigurasi area jaringan YESNET.'
                    : 'Status berasal dari data area jaringan YESNET.';

                if (dashboardDataHasColumns($conn, 'network_incidents', ['area_id', 'judul', 'mulai'])) {
                    $incidentSelect = ['judul', 'mulai'];
                    foreach (['deskripsi', 'estimasi_selesai'] as $column) {
                        if (dashboardDataColumnExists($conn, 'network_incidents', $column)) {
                            $incidentSelect[] = $column;
                        }
                    }

                    $incidentRows = dashboardDataQuery(
                        $conn,
                        'SELECT ' . implode(', ', $incidentSelect) . '
                         FROM network_incidents WHERE area_id = ?
                         ORDER BY mulai DESC LIMIT 5',
                        'i',
                        [$areaId]
                    );

                    foreach ($incidentRows as $incident) {
                        $network['history'][] = [
                            'title'                => (string) ($incident['judul'] ?? ''),
                            'description'          => (string) ($incident['deskripsi'] ?? ''),
                            'started_at'           => (string) ($incident['mulai'] ?? ''),
                            'estimated_resolution' => $incident['estimasi_selesai'] ?? null,
                        ];
                    }

                    if ($incidentRows !== [] && $network['status'] !== 'normal') {
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

    /* ---------------- NOTIFIKASI ---------------- */

    $notifications = [];
    $unreadNotifications = 0;

    if (dashboardDataHasColumns($conn, 'notifications', ['id_user', 'judul', 'message', 'type', 'dibaca', 'created_at'])) {
        $unreadRows = dashboardDataQuery(
            $conn,
            'SELECT COUNT(*) AS total FROM notifications
             WHERE (id_user = ? OR id_user IS NULL) AND dibaca = 0',
            'i',
            [$userId]
        );
        $unreadNotifications = (int) ($unreadRows[0]['total'] ?? 0);

        $notifications = dashboardDataQuery(
            $conn,
            'SELECT id, judul, message, type, dibaca, created_at
             FROM notifications
             WHERE id_user = ? OR id_user IS NULL
             ORDER BY created_at DESC LIMIT 4',
            'i',
            [$userId]
        );
    }

    dashboardDataResponse([
        'data' => [
            'telemetry'            => $telemetry,
            'invoice'              => $invoice,
            'network'              => $network,
            'notifications'        => $notifications,
            'unread_notifications' => $unreadNotifications,
            'checked_at'           => date(DATE_ATOM),
        ],
    ]);
} catch (Throwable $e) {
    error_log('Customer dashboard polling failed: ' . $e->getMessage());
    dashboardDataResponse(['error' => 'Data dashboard gagal diperbarui.'], 500);
}