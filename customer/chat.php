<?php

session_start();

require_once "../config/auth.php";

requireLogin();

require_once "../config/database.php";

requireRole('customer');

date_default_timezone_set('Asia/Jakarta');

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);


/*
|--------------------------------------------------------------------------
| HELPER
|--------------------------------------------------------------------------
*/

function e($value): string
{
    return htmlspecialchars(
        (string) $value,
        ENT_QUOTES,
        'UTF-8'
    );
}


function tableExists(
    mysqli $conn,
    string $table
): bool {

    $table = $conn->real_escape_string($table);

    $result = $conn->query(
        "SHOW TABLES LIKE '{$table}'"
    );

    return $result &&
           $result->num_rows > 0;
}


function columnExists(
    mysqli $conn,
    string $table,
    string $column
): bool {

    $table = $conn->real_escape_string($table);
    $column = $conn->real_escape_string($column);

    $result = $conn->query(
        "SHOW COLUMNS FROM `{$table}` LIKE '{$column}'"
    );

    return $result &&
           $result->num_rows > 0;
}


/**
 * Mengambil seluruh hasil prepared statement.
 * Mendukung hosting dengan maupun tanpa mysqlnd.
 */
function statementFetchAll(mysqli_stmt $stmt): array
{
    if (method_exists($stmt, 'get_result')) {
        $result = $stmt->get_result();

        if ($result instanceof mysqli_result) {
            return $result->fetch_all(MYSQLI_ASSOC);
        }
    }

    $metadata = $stmt->result_metadata();

    if (!$metadata) {
        return [];
    }

    $fields = $metadata->fetch_fields();
    $row = [];
    $bind = [];

    foreach ($fields as $field) {
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


function normalizeSubscriptionStatus($status): string
{
    $status = strtolower(trim((string) $status));

    if (in_array($status, ['active', 'aktif'], true)) {
        return 'active';
    }

    if (in_array($status, ['pending', 'proses', 'menunggu_pemasangan', 'menunggu pemasangan'], true)) {
        return 'pending';
    }

    if (in_array($status, ['suspended', 'ditangguhkan'], true)) {
        return 'suspended';
    }

    if (in_array($status, ['terminated', 'berakhir', 'nonaktif'], true)) {
        return 'terminated';
    }

    return 'belum_berlangganan';
}


function jsonResponse(array $payload, int $status = 200): void
{
    http_response_code($status);

    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store');

    echo json_encode($payload, JSON_UNESCAPED_UNICODE);

    exit;
}


function formatWaktuChat($datetime): string
{
    $time = strtotime((string) $datetime);

    return $time
        ? date('d M Y, H:i', $time)
        : '';
}


/**
 * Label pengirim.
 * sender_id = 0 pada sender_type 'cs' berarti balasan otomatis sistem.
 */
function senderLabel(string $senderType, int $senderId): string
{
    if ($senderType === 'customer') {
        return 'Anda';
    }

    return $senderId === 0
        ? 'Panduan FAQ'
        : 'Customer Service';
}


/*
|--------------------------------------------------------------------------
| FUNGSI BALASAN OTOMATIS (FAQ)
|--------------------------------------------------------------------------
*/

function findAutoReply(mysqli $conn, string $message, string $topic): string
{
    $fallback = 'Maaf, FAQ yang tersedia belum memuat jawaban untuk pertanyaan ini. '
              . 'Silakan lanjutkan melalui Customer Service agar kami dapat membantu dengan tepat.';

    if (
        !tableExists($conn, 'faq_auto_replies') ||
        !columnExists($conn, 'faq_auto_replies', 'keywords') ||
        !columnExists($conn, 'faq_auto_replies', 'answer') ||
        !columnExists($conn, 'faq_auto_replies', 'topic') ||
        !columnExists($conn, 'faq_auto_replies', 'is_active')
    ) {
        return $fallback;
    }

    try {
        $stmt = $conn->prepare("
            SELECT keywords, answer
            FROM faq_auto_replies
            WHERE is_active = 1
              AND topic IN (?, 'general')
        ");
        $stmt->bind_param("s", $topic);
        $stmt->execute();
        $rows = statementFetchAll($stmt);
        $stmt->close();

        $bestAnswer = null;
        $bestScore  = 0;

        foreach ($rows as $row) {
            $score = 0;

            foreach (explode(',', (string) $row['keywords']) as $keyword) {
                $keyword = trim($keyword);

                if ($keyword !== '' && mb_stripos($message, $keyword) !== false) {
                    $score++;
                }
            }

            if ($score > $bestScore) {
                $bestScore  = $score;
                $bestAnswer = (string) $row['answer'];
            }
        }

        return $bestAnswer ?? $fallback;

    } catch (Throwable $e) {
        error_log('Chat YESNET auto reply failed: ' . $e->getMessage());

        return $fallback;
    }
}


/*
|--------------------------------------------------------------------------
| FUNGSI ROOM CHAT (tabel chat_rooms)
|--------------------------------------------------------------------------
*/

function findRoomId(mysqli $conn, int $customerId): ?int
{
    $stmt = $conn->prepare("
        SELECT id
        FROM chat_rooms
        WHERE id_customer = ?
          AND status <> 'closed'
        ORDER BY id DESC
        LIMIT 1
    ");
    $stmt->bind_param("i", $customerId);
    $stmt->execute();
    $rows = statementFetchAll($stmt);
    $stmt->close();

    return isset($rows[0]['id'])
        ? (int) $rows[0]['id']
        : null;
}


function getOrCreateRoomId(mysqli $conn, int $customerId): int
{
    $roomId = findRoomId($conn, $customerId);

    if ($roomId !== null) {
        return $roomId;
    }

    $stmt = $conn->prepare("
        INSERT INTO chat_rooms (id_customer, status, created_at)
        VALUES (?, 'open', NOW())
    ");
    $stmt->bind_param("i", $customerId);
    $stmt->execute();
    $newId = (int) $conn->insert_id;
    $stmt->close();

    return $newId;
}


/**
 * True bila CS manusia sudah mengambil alih room ini dari dashboard admin
 * (kolom chat_rooms.ai_mode = 0). Jika kolom belum ada, dianggap false
 * sehingga perilaku lama tetap berjalan.
 */
function roomHandledByCs(mysqli $conn, ?int $roomId): bool
{
    if ($roomId === null || !columnExists($conn, 'chat_rooms', 'ai_mode')) {
        return false;
    }

    $stmt = $conn->prepare("
        SELECT ai_mode
        FROM chat_rooms
        WHERE id = ?
        LIMIT 1
    ");
    $stmt->bind_param("i", $roomId);
    $stmt->execute();
    $rows = statementFetchAll($stmt);
    $stmt->close();

    return isset($rows[0]['ai_mode'])
        && (int) $rows[0]['ai_mode'] === 0;
}


/*
|--------------------------------------------------------------------------
| CEK LOGIN
|--------------------------------------------------------------------------
*/

$userId = (int) (
    $_SESSION['user_id'] ?? 0
);

if ($userId <= 0) {
    header("Location: ../login.php");
    exit;
}


/*
|--------------------------------------------------------------------------
| CSRF
|--------------------------------------------------------------------------
*/

if (empty($_SESSION['csrf_chat'])) {
    $_SESSION['csrf_chat'] = bin2hex(random_bytes(32));
}

$csrfToken = $_SESSION['csrf_chat'];


/*
|--------------------------------------------------------------------------
| DEFAULT DATA
|--------------------------------------------------------------------------
*/

$customer = null;
$customerId = 0;

$nama =
    $_SESSION['nama']
    ??
    $_SESSION['username']
    ??
    'Customer';

$statusLangganan = 'belum_berlangganan';
$totalGangguan = 0;
$unreadNotifications = 0;


/*
|--------------------------------------------------------------------------
| DATA CUSTOMER
|--------------------------------------------------------------------------
*/

if (!tableExists($conn, 'customers')) {
    http_response_code(500);
    die("Tabel customers tidak ditemukan.");
}

if (!columnExists($conn, 'customers', 'user_id')) {
    error_log("Chat YESNET: kolom customers.user_id tidak ditemukan.");
    http_response_code(500);
    die("Struktur tabel customers belum sesuai. Kolom user_id tidak ditemukan.");
}

try {
    $stmt = $conn->prepare("
        SELECT *
        FROM customers
        WHERE user_id = ?
        LIMIT 1
    ");
    $stmt->bind_param("i", $userId);
    $stmt->execute();

    $customerRows = statementFetchAll($stmt);
    $stmt->close();

    if (empty($customerRows)) {
        http_response_code(404);
        die("Data customer tidak ditemukan untuk akun ini.");
    }

    $customer = $customerRows[0];
} catch (Throwable $e) {
    error_log(
        "Chat YESNET customer query error: " .
        $e->getMessage() .
        " | file: " . $e->getFile() .
        " | line: " . $e->getLine()
    );

    http_response_code(500);
    die("Data halaman gagal dimuat. Periksa struktur tabel customers atau error log hosting.");
}

$customerId = (int) ($customer['id'] ?? 0);

$nama = trim(
    (string) (
        $customer['nama']
        ??
        $customer['name']
        ??
        $_SESSION['nama']
        ??
        $_SESSION['username']
        ??
        'Customer'
    )
);

if ($nama === '') {
    $nama = 'Customer';
}

$statusLangganan = normalizeSubscriptionStatus(
    $customer['status_langganan'] ?? ''
);

$avatar = strtoupper(
    substr(
        trim($nama) ?: 'C',
        0,
        1
    )
);


/*
|--------------------------------------------------------------------------
| TOPIK
|--------------------------------------------------------------------------
*/

$topicLabels = [
    'general'      => 'Pertanyaan Umum',
    'wifi'         => 'Bantuan WiFi',
    'billing'      => 'Tagihan',
    'complaint'    => 'Gangguan Internet',
    'installation' => 'Instalasi'
];

$topicIcons = [
    'general'      => 'bi-chat-dots',
    'wifi'         => 'bi-wifi',
    'billing'      => 'bi-credit-card',
    'complaint'    => 'bi-tools',
    'installation' => 'bi-calendar-check'
];

$topicShort = [
    'general'      => 'Umum',
    'wifi'         => 'WiFi',
    'billing'      => 'Tagihan',
    'complaint'    => 'Gangguan',
    'installation' => 'Instalasi'
];

$topic = (string) ($_GET['topic'] ?? 'general');

if (!isset($topicLabels[$topic])) {
    $topic = 'general';
}

$topicLabel = $topicLabels[$topic];
$topicIcon = $topicIcons[$topic];


/*
|--------------------------------------------------------------------------
| TABEL CHAT SIAP?
|--------------------------------------------------------------------------
*/

$chatReady =
    tableExists($conn, 'chat_rooms') &&
    tableExists($conn, 'chat_messages') &&
    columnExists($conn, 'chat_rooms', 'id_customer') &&
    columnExists($conn, 'chat_rooms', 'status') &&
    columnExists($conn, 'chat_messages', 'id_room') &&
    columnExists($conn, 'chat_messages', 'sender_type') &&
    columnExists($conn, 'chat_messages', 'sender_id') &&
    columnExists($conn, 'chat_messages', 'message') &&
    columnExists($conn, 'chat_messages', 'topic');

$hasLastMessageAt = $chatReady
    && columnExists($conn, 'chat_rooms', 'last_message_at');


/*
|--------------------------------------------------------------------------
| GET: POLLING PESAN BARU (JSON)
|--------------------------------------------------------------------------
*/

if (
    $_SERVER['REQUEST_METHOD'] === 'GET' &&
    isset($_GET['poll'])
) {
    if (!$chatReady) {
        jsonResponse(['messages' => [], 'handled_by_cs' => false]);
    }

    $afterId = max(0, (int) ($_GET['after'] ?? 0));
    $pollMessages = [];
    $pollHandledByCs = false;

    try {
        $roomId = findRoomId($conn, $customerId);

        if ($roomId !== null) {
            $pollHandledByCs = roomHandledByCs($conn, $roomId);

            $stmt = $conn->prepare("
                SELECT id, sender_type, sender_id, message, created_at
                FROM chat_messages
                WHERE id_room = ?
                  AND topic = ?
                  AND id > ?
                ORDER BY id ASC
                LIMIT 50
            ");
            $stmt->bind_param("isi", $roomId, $topic, $afterId);
            $stmt->execute();
            $rows = statementFetchAll($stmt);
            $stmt->close();

            foreach ($rows as $row) {
                $pollMessages[] = [
                    'id'         => (int) $row['id'],
                    'sender'     => (string) $row['sender_type'],
                    'label'      => senderLabel(
                        (string) $row['sender_type'],
                        (int) $row['sender_id']
                    ),
                    'is_auto'    => (string) $row['sender_type'] !== 'customer'
                                    && (int) $row['sender_id'] === 0,
                    'message'    => (string) $row['message'],
                    'time_label' => formatWaktuChat($row['created_at'])
                ];
            }
        }
    } catch (Throwable $e) {
        error_log('Chat YESNET poll failed: ' . $e->getMessage());
        jsonResponse(['messages' => [], 'error' => 'Gagal memuat pesan.'], 500);
    }

    jsonResponse([
        'messages'      => $pollMessages,
        'handled_by_cs' => $pollHandledByCs
    ]);
}


/*
|--------------------------------------------------------------------------
| POST: KIRIM PESAN
|--------------------------------------------------------------------------
*/

$messageError = '';
$draftMessage = '';
$isAjaxRequest = strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? ''))
    === 'xmlhttprequest';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $postMessage = trim((string) ($_POST['message'] ?? ''));
    $postTopic   = (string) ($_POST['topic'] ?? 'general');
    $postToken   = (string) ($_POST['csrf_token'] ?? '');

    if (!isset($topicLabels[$postTopic])) {
        $postTopic = 'general';
    }

    $topic = $postTopic;
    $topicLabel = $topicLabels[$topic];
    $topicIcon = $topicIcons[$topic];

    if (!hash_equals($csrfToken, $postToken)) {

        $messageError = 'Sesi tidak valid. Muat ulang halaman lalu coba lagi.';
        $draftMessage = $postMessage;

    } elseif (!$chatReady) {

        error_log('Chat YESNET: tabel chat_rooms/chat_messages belum sesuai.');
        $messageError = 'Fitur chat belum siap digunakan. Silakan hubungi kami melalui kontak resmi.';
        $draftMessage = $postMessage;

    } elseif ($postMessage === '') {

        $messageError = 'Pesan tidak boleh kosong.';

    } elseif (mb_strlen($postMessage) > 2000) {

        $messageError = 'Pesan maksimal 2000 karakter.';
        $draftMessage = $postMessage;

    } else {

        try {

            $roomId = getOrCreateRoomId($conn, $customerId);

            // Batasi kecepatan kirim: maksimal 10 pesan per menit.
            $stmt = $conn->prepare("
                SELECT COUNT(*) AS total
                FROM chat_messages
                WHERE id_room = ?
                  AND sender_type = 'customer'
                  AND created_at >= (NOW() - INTERVAL 1 MINUTE)
            ");
            $stmt->bind_param("i", $roomId);
            $stmt->execute();
            $rows = statementFetchAll($stmt);
            $stmt->close();

            if ((int) ($rows[0]['total'] ?? 0) >= 10) {

                $messageError = 'Terlalu banyak pesan dalam waktu singkat. Tunggu sebentar lalu coba lagi.';
                $draftMessage = $postMessage;

            } else {

                // Jika CS sudah mengambil alih, jangan kirim balasan otomatis.
                $handledByCs = roomHandledByCs($conn, $roomId);

                $autoReply = $handledByCs
                    ? null
                    : findAutoReply($conn, $postMessage, $postTopic);

                $autoReplyMessageId = 0;

                $conn->begin_transaction();

                try {
                    // 1. Pesan customer
                    $stmt = $conn->prepare("
                        INSERT INTO chat_messages
                            (id_room, sender_type, sender_id, message, topic, created_at)
                        VALUES (?, 'customer', ?, ?, ?, NOW())
                    ");
                    $stmt->bind_param("iiss", $roomId, $customerId, $postMessage, $postTopic);
                    $stmt->execute();
                    $customerMessageId = (int) $conn->insert_id;
                    $stmt->close();

                    // 2. Balasan otomatis (sender_id 0 = sistem)
                    if ($autoReply !== null) {
                        $stmt = $conn->prepare("
                            INSERT INTO chat_messages
                                (id_room, sender_type, sender_id, message, topic, created_at)
                            VALUES (?, 'cs', 0, ?, ?, NOW())
                        ");
                        $stmt->bind_param("iss", $roomId, $autoReply, $postTopic);
                        $stmt->execute();
                        $autoReplyMessageId = (int) $conn->insert_id;
                        $stmt->close();
                    }

                    // 3. Waktu pesan terakhir di room
                    if ($hasLastMessageAt) {
                        $stmt = $conn->prepare("
                            UPDATE chat_rooms
                            SET last_message_at = NOW()
                            WHERE id = ?
                        ");
                        $stmt->bind_param("i", $roomId);
                        $stmt->execute();
                        $stmt->close();
                    }

                    $conn->commit();
                } catch (Throwable $inner) {
                    $conn->rollback();
                    throw $inner;
                }

                if ($isAjaxRequest) {
                    $messageTime = formatWaktuChat(date('Y-m-d H:i:s'));

                    $responseMessages = [
                        [
                            'id' => $customerMessageId,
                            'sender' => 'customer',
                            'label' => 'Anda',
                            'is_auto' => false,
                            'message' => $postMessage,
                            'time_label' => $messageTime
                        ]
                    ];

                    if ($autoReply !== null) {
                        $responseMessages[] = [
                            'id' => $autoReplyMessageId,
                            'sender' => 'cs',
                            'label' => senderLabel('cs', 0),
                            'is_auto' => true,
                            'message' => $autoReply,
                            'time_label' => $messageTime
                        ];
                    }

                    jsonResponse([
                        'messages' => $responseMessages,
                        'handled_by_cs' => $handledByCs
                    ]);
                }

                header("Location: chat.php?topic=" . urlencode($postTopic));
                exit;
            }

        } catch (Throwable $e) {

            error_log('Chat YESNET send failed: ' . $e->getMessage());
            $messageError = 'Pesan belum dapat dikirim. Silakan coba lagi.';
            $draftMessage = $postMessage;
        }
    }
}

if ($isAjaxRequest && $messageError !== '') {
    jsonResponse(['error' => $messageError], 422);
}


/*
|--------------------------------------------------------------------------
| RIWAYAT PESAN
|--------------------------------------------------------------------------
*/

$messages = [];
$lastMessageId = 0;
$currentRoomId = null;
$handledByCs = false;

if ($chatReady) {
    try {
        $currentRoomId = findRoomId($conn, $customerId);

        if ($currentRoomId !== null) {
            $handledByCs = roomHandledByCs($conn, $currentRoomId);

            $stmt = $conn->prepare("
                SELECT id, sender_type, sender_id, message, created_at
                FROM chat_messages
                WHERE id_room = ?
                  AND topic = ?
                ORDER BY created_at ASC, id ASC
                LIMIT 200
            ");
            $stmt->bind_param("is", $currentRoomId, $topic);
            $stmt->execute();
            $messages = statementFetchAll($stmt);
            $stmt->close();
        }
    } catch (Throwable $e) {
        error_log('Chat YESNET history query failed: ' . $e->getMessage());
        $messages = [];
    }
}

foreach ($messages as $row) {
    $lastMessageId = max($lastMessageId, (int) $row['id']);
}

$hasMessages = !empty($messages);

$quickQuestions = [

    'wifi' => [
        'WiFi saya tidak bisa digunakan',
        'Bagaimana cara reset WiFi?',
        'Internet saya lambat'
    ],

    'billing' => [
        'Berapa tagihan saya?',
        'Bagaimana cara pembayaran?',
        'Pembayaran saya belum masuk'
    ],

    'complaint' => [
        'Internet saya mati',
        'Koneksi sering putus',
        'Saya ingin melaporkan gangguan'
    ],

    'installation' => [
        'Kapan jadwal instalasi saya?',
        'Saya ingin ubah jadwal instalasi',
        'Bagaimana proses instalasi?'
    ],

    'general' => [
        'Saya ingin bertanya tentang layanan',
        'Bagaimana cara menghubungi CS?',
        'Saya membutuhkan bantuan'
    ]
];

$currentQuickQuestions = $quickQuestions[$topic] ?? $quickQuestions['general'];


/*
|--------------------------------------------------------------------------
| BADGE SIDEBAR: LAPORAN GANGGUAN AKTIF
|--------------------------------------------------------------------------
*/

if (
    $customerId > 0 &&
    tableExists($conn, 'complaint') &&
    columnExists($conn, 'complaint', 'id_customer')
) {
    try {
        $sqlComplaint = "
            SELECT COUNT(*) AS total
            FROM complaint
            WHERE id_customer = ?
        ";

        if (columnExists($conn, 'complaint', 'status')) {
            $sqlComplaint .= "
                AND LOWER(status) NOT IN ('closed', 'resolved')
            ";
        }

        $stmt = $conn->prepare($sqlComplaint);
        $stmt->bind_param("i", $customerId);
        $stmt->execute();
        $rows = statementFetchAll($stmt);
        $totalGangguan = (int) ($rows[0]['total'] ?? 0);
        $stmt->close();
    } catch (Throwable $e) {
        error_log('Chat YESNET complaint count failed: ' . $e->getMessage());
    }
}


/*
|--------------------------------------------------------------------------
| NOTIFIKASI BELUM DIBACA
|--------------------------------------------------------------------------
*/

if (
    tableExists($conn, 'notifications') &&
    columnExists($conn, 'notifications', 'id_user') &&
    columnExists($conn, 'notifications', 'dibaca')
) {
    try {
        $stmt = $conn->prepare("
            SELECT COUNT(*) AS total
            FROM notifications
            WHERE (id_user = ? OR id_user IS NULL)
              AND dibaca = 0
        ");
        $stmt->bind_param("i", $userId);
        $stmt->execute();
        $rows = statementFetchAll($stmt);
        $unreadNotifications = (int) ($rows[0]['total'] ?? 0);
        $stmt->close();
    } catch (Throwable $e) {
        error_log('Chat YESNET notification count failed: ' . $e->getMessage());
    }
}


$currentPage = basename($_SERVER['PHP_SELF']);

?>

<!DOCTYPE html>

<html lang="id">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <meta
        name="theme-color"
        content="#0f4cdb"
    >

    <title>
        Bantuan / Live Chat - YESNET
    </title>


    <!-- Bootstrap -->

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
        rel="stylesheet"
    >


    <!-- Bootstrap Icons -->

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css"
        rel="stylesheet"
    >


    <!-- Dashboard CSS (shell bersama) -->

    <link
        rel="stylesheet"
        href="assets/css/dashboard.css"
    >


    <!-- Halaman Chat -->

    <link
        rel="stylesheet"
        href="assets/css/chat.css"
    >

</head>


<body class="dashboard-page chat-page">

<div class="customer-layout">


    <!-- =====================================================
         MOBILE OVERLAY
    ====================================================== -->

    <div
        class="sidebar-overlay"
        id="sidebarOverlay"
    ></div>


    <!-- =====================================================
         SIDEBAR
    ====================================================== -->

    <aside
        class="sidebar"
        id="sidebar"
    >

        <div class="sidebar-brand">

            <div class="brand-logo">
                <i class="bi bi-wifi"></i>
            </div>

            <div class="brand-text">
                <strong>YESNET</strong>
                <span>Customer Portal</span>
            </div>

            <button
                type="button"
                class="sidebar-close"
                id="sidebarClose"
            >
                <i class="bi bi-x-lg"></i>
            </button>

        </div>


        <div class="sidebar-scroll">

            <div class="menu-section">

                <div class="menu-label">UTAMA</div>

                <a
                    href="dashboard.php"
                    class="menu-item <?= $currentPage === 'dashboard.php' ? 'active' : ''; ?>"
                >
                    <span class="menu-icon"><i class="bi bi-grid-fill"></i></span>
                    <span class="menu-text">Dashboard</span>
                </a>

                <a
                    href="usage.php"
                    class="menu-item <?= $currentPage === 'usage.php' ? 'active' : ''; ?>"
                >
                    <span class="menu-icon"><i class="bi bi-router-fill"></i></span>
                    <span class="menu-text">Internet Saya</span>
                </a>

                <a
                    href="billing.php"
                    class="menu-item <?= $currentPage === 'billing.php' ? 'active' : ''; ?>"
                >
                    <span class="menu-icon"><i class="bi bi-receipt-cutoff"></i></span>
                    <span class="menu-text">Tagihan Saya</span>
                </a>

                <a
                    href="billing.php#riwayat-pembayaran"
                    class="menu-item"
                >
                    <span class="menu-icon"><i class="bi bi-clock-history"></i></span>
                    <span class="menu-text">Riwayat Pembayaran</span>
                </a>

                <a
                    href="speedtest.php"
                    class="menu-item <?= $currentPage === 'speedtest.php' ? 'active' : ''; ?>"
                >
                    <span class="menu-icon"><i class="bi bi-speedometer2"></i></span>
                    <span class="menu-text">Speed Test</span>
                </a>

                <a
                    href="complaint.php"
                    class="menu-item <?= $currentPage === 'complaint.php' ? 'active' : ''; ?>"
                >
                    <span class="menu-icon"><i class="bi bi-tools"></i></span>
                    <span class="menu-text">Laporan Gangguan</span>
                    <?php if ($totalGangguan > 0): ?>
                        <span class="menu-badge"><?= $totalGangguan; ?></span>
                    <?php endif; ?>
                </a>

                <a
                    href="network_status.php"
                    class="menu-item <?= $currentPage === 'network_status.php' ? 'active' : ''; ?>"
                >
                    <span class="menu-icon"><i class="bi bi-globe2"></i></span>
                    <span class="menu-text">Status Jaringan</span>
                </a>

                <a
                    href="upgrade.php"
                    class="menu-item <?= $currentPage === 'upgrade.php' ? 'active' : ''; ?>"
                >
                    <span class="menu-icon"><i class="bi bi-arrow-up-circle-fill"></i></span>
                    <span class="menu-text">Upgrade Paket</span>
                </a>

                <a
                    href="chat.php"
                    class="menu-item <?= $currentPage === 'chat.php' ? 'active' : ''; ?>"
                >
                    <span class="menu-icon"><i class="bi bi-chat-dots-fill"></i></span>
                    <span class="menu-text">Bantuan / Live Chat</span>
                </a>

            </div>


            <div class="menu-section">

                <div class="menu-label">LAYANAN</div>

                <?php if ($statusLangganan === 'belum_berlangganan'): ?>

                    <a href="../paket.php?mode=direct" class="menu-item">
                        <span class="menu-icon"><i class="bi bi-box-seam-fill"></i></span>
                        <span class="menu-text">Pilih Paket</span>
                    </a>

                <?php elseif ($statusLangganan === 'pending'): ?>

                    <a href="installation.php" class="menu-item">
                        <span class="menu-icon"><i class="bi bi-hammer"></i></span>
                        <span class="menu-text">Status Pemasangan</span>
                    </a>

                <?php elseif ($statusLangganan === 'active'): ?>

                    <a href="upgrade.php" class="menu-item">
                        <span class="menu-icon"><i class="bi bi-arrow-up-circle-fill"></i></span>
                        <span class="menu-text">Upgrade Paket</span>
                    </a>

                    <a href="service_request.php" class="menu-item">
                        <span class="menu-icon"><i class="bi bi-plus-circle-fill"></i></span>
                        <span class="menu-text">Layanan Tambahan</span>
                    </a>

                <?php endif; ?>

            </div>


            <div class="menu-section">

                <div class="menu-label">AKUN</div>

                <a href="profile.php" class="menu-item">
                    <span class="menu-icon"><i class="bi bi-person-circle"></i></span>
                    <span class="menu-text">Profile Saya</span>
                </a>

                <a href="notifikasi.php" class="menu-item">
                    <span class="menu-icon"><i class="bi bi-bell-fill"></i></span>
                    <span class="menu-text">Notifikasi</span>
                </a>

                <a href="../logout.php" class="menu-item logout-item">
                    <span class="menu-icon"><i class="bi bi-box-arrow-right"></i></span>
                    <span class="menu-text">Logout</span>
                </a>

            </div>

        </div>


        <!-- SIDEBAR USER -->

        <div class="sidebar-user">

            <div class="sidebar-user-avatar">
                <?= e($avatar); ?>
            </div>

            <div class="sidebar-user-info">
                <strong><?= e($nama); ?></strong>
                <span>ID pelanggan #<?= (int) $customerId; ?></span>
            </div>

            <i class="bi bi-three-dots"></i>

        </div>

    </aside>


    <!-- =====================================================
         MAIN CONTENT
    ====================================================== -->

    <main class="main-content">


        <!-- TOPBAR -->

        <header class="topbar">

            <div class="topbar-left">

                <button
                    type="button"
                    class="mobile-menu-button"
                    id="mobileMenuButton"
                >
                    <i class="bi bi-list"></i>
                </button>

                <div>

                    <div class="breadcrumb-text">
                        Customer Portal
                        <span>/</span>
                        Bantuan
                    </div>

                    <h1>Bantuan / Live Chat</h1>

                </div>

            </div>


            <div class="topbar-right">

                <button
                    type="button"
                    class="theme-toggle"
                    id="themeToggle"
                    aria-label="Aktifkan tema gelap"
                    aria-pressed="false"
                    title="Ganti tema"
                >
                    <i class="bi bi-moon-stars" id="themeToggleIcon"></i>
                </button>

                <a
                    href="notifikasi.php"
                    class="notification-button"
                    title="Notifikasi"
                >
                    <i class="bi bi-bell"></i>

                    <span
                        class="notification-count"
                        <?= $unreadNotifications > 0 ? '' : 'hidden'; ?>
                    ><?= $unreadNotifications > 99 ? '99+' : $unreadNotifications; ?></span>
                </a>

                <div class="top-profile">

                    <div class="top-profile-avatar">
                        <?= e($avatar); ?>
                    </div>

                    <div class="top-profile-info">
                        <strong><?= e($nama); ?></strong>
                        <span>ID pelanggan #<?= (int) $customerId; ?></span>
                    </div>

                    <i class="bi bi-chevron-down"></i>

                </div>

            </div>

        </header>


        <!-- CONTENT -->

        <div class="dashboard-content chat-content">


            <!-- =================================================
                 PANEL CHAT
            ================================================== -->

            <section class="dashboard-card chat-panel">

                <div class="chat-header">

                    <div class="chat-header-left">

                        <div class="chat-header-icon">
                            <i class="bi <?= e($topicIcon); ?>"></i>
                        </div>

                        <div>
                            <span class="card-kicker">TOPIK CHAT</span>
                            <h3><?= e($topicLabel); ?></h3>
                        </div>

                    </div>

                    <span class="chat-mode-badge">
                        <i class="bi bi-journal-text"></i>
                        FAQ & Customer Service
                    </span>

                </div>


                <!-- PILIH TOPIK -->

                <nav class="chat-topics" aria-label="Pilih topik bantuan">

                    <?php foreach ($topicShort as $key => $label): ?>

                        <a
                            href="chat.php?topic=<?= e($key); ?>"
                            class="chat-topic <?= $topic === $key ? 'active' : ''; ?>"
                        >
                            <i class="bi <?= e($topicIcons[$key]); ?>"></i>
                            <?= e($label); ?>
                        </a>

                    <?php endforeach; ?>

                </nav>


                <!-- INFO: CS SEDANG MENANGANI -->

                <div
                    class="chat-handler-note"
                    id="chatHandlerNote"
                    role="status"
                    <?= $handledByCs ? '' : 'hidden'; ?>
                >
                    <i class="bi bi-headset"></i>
                    <span>Customer Service sedang menangani percakapan ini. Balasan akan muncul di sini.</span>
                </div>


                <!-- PESAN -->

                <div class="chat-body" id="chatBody">

                    <div class="chat-messages" id="chatMessages" aria-live="polite">

                        <?php if (!$chatReady): ?>

                            <div class="chat-empty" role="status">
                                <i class="bi bi-exclamation-triangle"></i>
                                <strong>Fitur chat belum siap</strong>
                                <span>
                                    Tabel chat belum tersedia di sistem.
                                    Silakan hubungi kami melalui kontak resmi.
                                </span>
                            </div>

                        <?php elseif (!$hasMessages): ?>

                            <div class="message-row cs-message is-placeholder" id="chatPlaceholder">

                                <div class="message-avatar">
                                    <i class="bi bi-journal-text"></i>
                                </div>

                                <div class="message-content">

                                    <div class="message-name">Panduan FAQ</div>

                                    <div class="message-bubble">Halo <?= e($nama); ?>! Ada yang bisa kami bantu terkait layanan internet kamu? Pilih topik atau tulis pesan untuk Customer Service.</div>

                                </div>

                            </div>

                        <?php else: ?>

                            <?php foreach ($messages as $msg): ?>

                                <?php
                                $isCustomer = (string) $msg['sender_type'] === 'customer';
                                $isAuto = !$isCustomer && (int) $msg['sender_id'] === 0;
                                ?>

                                <div
                                    class="message-row <?= $isCustomer ? 'customer-message' : 'cs-message'; ?>"
                                    id="chat-message-<?= (int) $msg['id']; ?>"
                                    data-id="<?= (int) $msg['id']; ?>"
                                >

                                    <?php if (!$isCustomer): ?>
                                        <div class="message-avatar">
                                            <i class="bi <?= $isAuto ? 'bi-journal-text' : 'bi-headset'; ?>"></i>
                                        </div>
                                    <?php endif; ?>

                                    <div class="message-content">

                                        <div class="message-name">
                                            <?= e(senderLabel((string) $msg['sender_type'], (int) $msg['sender_id'])); ?>
                                        </div>

                                        <div class="message-bubble"><?= e($msg['message']); ?></div>

                                        <div class="message-time">
                                            <?= e(formatWaktuChat($msg['created_at'])); ?>
                                        </div>

                                    </div>

                                    <?php if ($isCustomer): ?>
                                        <div class="message-avatar customer-avatar">
                                            <?= e($avatar); ?>
                                        </div>
                                    <?php endif; ?>

                                </div>

                            <?php endforeach; ?>

                        <?php endif; ?>

                    </div>

                </div>


                <!-- PERTANYAAN CEPAT -->

                <?php if ($chatReady): ?>

                    <div class="chat-quick">

                        <span>Pertanyaan cepat</span>

                        <div class="chat-quick-list">

                            <?php foreach ($currentQuickQuestions as $question): ?>

                                <button
                                    type="button"
                                    class="quick-question"
                                    data-question="<?= e($question); ?>"
                                >
                                    <?= e($question); ?>
                                </button>

                            <?php endforeach; ?>

                        </div>

                    </div>

                <?php endif; ?>


                <!-- FORM -->

                <div class="chat-footer">

                    <?php if ($messageError !== ''): ?>

                        <div class="chat-error" role="alert">
                            <i class="bi bi-exclamation-circle"></i>
                            <?= e($messageError); ?>
                        </div>

                    <?php endif; ?>

                    <form
                        method="POST"
                        action="chat.php?topic=<?= e($topic); ?>"
                        class="message-form"
                        id="messageForm"
                    >

                        <input
                            type="hidden"
                            name="topic"
                            value="<?= e($topic); ?>"
                        >

                        <input
                            type="hidden"
                            name="csrf_token"
                            value="<?= e($csrfToken); ?>"
                        >

                        <div class="message-input-wrapper">

                            <textarea
                                name="message"
                                id="messageInput"
                                rows="1"
                                placeholder="Tulis pesan untuk Customer Service..."
                                maxlength="2000"
                                <?= $chatReady ? '' : 'disabled'; ?>
                            ><?= e($draftMessage); ?></textarea>

                            <span class="character-count" id="characterCount">
                                0/2000
                            </span>

                        </div>

                        <button
                            type="submit"
                            class="send-button"
                            id="sendButton"
                            title="Kirim pesan"
                            aria-label="Kirim pesan"
                            <?= $chatReady ? '' : 'disabled'; ?>
                        >
                            <i class="bi bi-send-fill"></i>
                        </button>

                    </form>

                    <div class="chat-footer-info">

                        <span>
                            <i class="bi bi-shield-check"></i>
                            Pesan tersimpan di akun kamu
                        </span>

                        <span>Enter untuk kirim · Shift+Enter baris baru</span>

                    </div>

                </div>

            </section>


            <!-- =================================================
                 PANEL INFO
            ================================================== -->

            <aside class="chat-side">

                <div class="dashboard-card chat-side-card">

                    <div class="card-heading compact">

                        <div>
                            <span class="card-kicker">TOPIK</span>
                            <h3>Bantuan Cepat</h3>
                        </div>

                    </div>

                    <div class="chat-help-list">

                        <?php foreach (['wifi', 'billing', 'complaint', 'installation'] as $key): ?>

                            <a href="chat.php?topic=<?= e($key); ?>">
                                <i class="bi <?= e($topicIcons[$key]); ?>"></i>
                                <span><?= e($topicLabels[$key]); ?></span>
                                <i class="bi bi-chevron-right"></i>
                            </a>

                        <?php endforeach; ?>

                    </div>

                </div>


                <div class="dashboard-card chat-side-card">

                    <div class="card-heading compact">

                        <div>
                            <span class="card-kicker">INFORMASI</span>
                            <h3>Cara Kerja Chat</h3>
                        </div>

                    </div>

                    <div class="chat-info-list">

                        <div>
                            <i class="bi bi-journal-text"></i>
                            <span>
                                <strong>Panduan FAQ</strong>
                                Jawaban otomatis hanya merujuk FAQ yang tersedia. Pertanyaan di luar FAQ perlu ditindaklanjuti oleh Customer Service.
                            </span>
                        </div>

                        <div>
                            <i class="bi bi-headset"></i>
                            <span>
                                <strong>Tim Customer Service</strong>
                                Pesan kamu tersimpan dan dapat dibalas oleh CS. Halaman ini memeriksa balasan baru secara berkala.
                            </span>
                        </div>

                        <div>
                            <i class="bi bi-tools"></i>
                            <span>
                                <strong>Gangguan teknis?</strong>
                                Gunakan <a href="complaint.php">Laporan Gangguan</a> agar tercatat dan ditindaklanjuti.
                            </span>
                        </div>

                    </div>

                </div>

            </aside>

        </div>

    </main>

</div>


<script>

document.addEventListener('DOMContentLoaded', function () {

    /*
    |--------------------------------------------------------------------------
    | MOBILE SIDEBAR
    |--------------------------------------------------------------------------
    */

    const sidebar = document.getElementById('sidebar');
    const overlay = document.getElementById('sidebarOverlay');
    const mobileButton = document.getElementById('mobileMenuButton');
    const closeButton = document.getElementById('sidebarClose');

    function openSidebar() {
        if (!sidebar) {
            return;
        }

        sidebar.classList.add('show');

        if (overlay) {
            overlay.classList.add('show');
        }

        document.body.classList.add('sidebar-open');
    }

    function closeSidebar() {
        if (!sidebar) {
            return;
        }

        sidebar.classList.remove('show');

        if (overlay) {
            overlay.classList.remove('show');
        }

        document.body.classList.remove('sidebar-open');
    }

    if (mobileButton) {
        mobileButton.addEventListener('click', openSidebar);
    }

    if (closeButton) {
        closeButton.addEventListener('click', closeSidebar);
    }

    if (overlay) {
        overlay.addEventListener('click', closeSidebar);
    }


    /*
    |--------------------------------------------------------------------------
    | THEME
    |--------------------------------------------------------------------------
    */

    const themeToggle = document.getElementById('themeToggle');
    const themeToggleIcon = document.getElementById('themeToggleIcon');

    function applyTheme(theme) {
        const isDark = theme === 'dark';
        document.body.dataset.theme = isDark ? 'dark' : 'light';

        if (themeToggle) {
            themeToggle.setAttribute('aria-pressed', isDark ? 'true' : 'false');
            themeToggle.setAttribute(
                'aria-label',
                isDark ? 'Aktifkan tema terang' : 'Aktifkan tema gelap'
            );
        }

        if (themeToggleIcon) {
            themeToggleIcon.className = isDark
                ? 'bi bi-sun'
                : 'bi bi-moon-stars';
        }
    }

    try {
        applyTheme(localStorage.getItem('yesnet-customer-theme') || 'light');
    } catch (error) {
        console.warn('Preferensi tema tidak dapat dibaca.', error);
        applyTheme('light');
    }

    if (themeToggle) {
        themeToggle.addEventListener('click', function () {
            const nextTheme = document.body.dataset.theme === 'dark'
                ? 'light'
                : 'dark';
            applyTheme(nextTheme);

            try {
                localStorage.setItem('yesnet-customer-theme', nextTheme);
            } catch (error) {
                console.warn('Preferensi tema tidak dapat disimpan.', error);
            }
        });
    }


    /*
    |--------------------------------------------------------------------------
    | CHAT
    |--------------------------------------------------------------------------
    */

    const chatBody = document.getElementById('chatBody');
    const chatMessages = document.getElementById('chatMessages');
    const chatHandlerNote = document.getElementById('chatHandlerNote');
    const messageInput = document.getElementById('messageInput');
    const characterCount = document.getElementById('characterCount');
    const messageForm = document.getElementById('messageForm');
    const sendButton = document.getElementById('sendButton');

    const currentTopic = <?= json_encode($topic, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT); ?>;
    const chatReady = <?= $chatReady ? 'true' : 'false'; ?>;
    let lastMessageId = <?= (int) $lastMessageId; ?>;

    function setHandlerNote(isHandled) {
        if (chatHandlerNote) {
            chatHandlerNote.hidden = !isHandled;
        }
    }

    function scrollToBottom() {
        if (chatBody) {
            chatBody.scrollTop = chatBody.scrollHeight;
        }
    }

    function updateCharacterCount() {
        if (messageInput && characterCount) {
            characterCount.textContent = messageInput.value.length + '/2000';
        }
    }

    function autoResize() {
        if (!messageInput) {
            return;
        }

        messageInput.style.height = 'auto';
        messageInput.style.height = Math.min(messageInput.scrollHeight, 120) + 'px';
    }

    scrollToBottom();
    updateCharacterCount();
    autoResize();

    // Pertanyaan cepat mengisi kolom pesan.
    document.querySelectorAll('.quick-question').forEach(function (button) {
        button.addEventListener('click', function () {
            if (!messageInput) {
                return;
            }

            messageInput.value = button.dataset.question || '';
            messageInput.focus();
            updateCharacterCount();
            autoResize();
        });
    });

    if (messageInput) {
        messageInput.addEventListener('input', function () {
            updateCharacterCount();
            autoResize();
        });

        // Enter = kirim, Shift+Enter = baris baru.
        messageInput.addEventListener('keydown', function (event) {
            if (event.key === 'Enter' && !event.shiftKey && !event.isComposing) {
                event.preventDefault();

                if (messageInput.value.trim() !== '' && messageForm) {
                    messageForm.requestSubmit();
                }
            }
        });
    }

    function showSendError(message) {
        let errorMessage = document.querySelector('.chat-error');

        if (!errorMessage) {
            errorMessage = document.createElement('div');
            errorMessage.className = 'chat-error';
            errorMessage.setAttribute('role', 'alert');
            messageForm.parentNode.insertBefore(errorMessage, messageForm);
        }

        while (errorMessage.firstChild) {
            errorMessage.removeChild(errorMessage.firstChild);
        }

        const icon = document.createElement('i');
        icon.className = 'bi bi-exclamation-circle';
        const text = document.createElement('span');
        text.textContent = message;
        errorMessage.append(icon, text);
        errorMessage.hidden = false;
    }

    // Kirim melalui Fetch; halaman tetap dapat dipakai bila JavaScript tidak aktif.
    if (messageForm) {
        messageForm.addEventListener('submit', async function (event) {
            event.preventDefault();

            if (
                !messageInput ||
                messageInput.value.trim() === '' ||
                (sendButton && sendButton.disabled)
            ) {
                return;
            }

            const formData = new FormData(messageForm);

            const previousError = document.querySelector('.chat-error');

            if (previousError) {
                previousError.remove();
            }

            if (sendButton) {
                sendButton.disabled = true;
            }

            messageInput.disabled = true;
            messageForm.setAttribute('aria-busy', 'true');

            try {
                const response = await fetch(messageForm.action, {
                    method: 'POST',
                    body: formData,
                    headers: {
                        Accept: 'application/json',
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    cache: 'no-store',
                    credentials: 'same-origin'
                });
                const payload = await response.json();

                if (!response.ok) {
                    throw new Error(payload.error || 'Pesan gagal dikirim. Silakan coba lagi.');
                }

                const placeholder = document.getElementById('chatPlaceholder');

                if (placeholder) {
                    placeholder.remove();
                }

                (Array.isArray(payload.messages) ? payload.messages : []).forEach(function (item) {
                    appendMessage(item, item.sender === 'customer' ? 'Terkirim' : '');

                    if (Number(item.id) > lastMessageId) {
                        lastMessageId = Number(item.id);
                    }
                });

                setHandlerNote(Boolean(payload.handled_by_cs));

                messageInput.value = '';
                updateCharacterCount();
                autoResize();
                scrollToBottom();
            } catch (error) {
                showSendError(error.message || 'Pesan gagal dikirim. Silakan coba lagi.');
            } finally {
                messageInput.disabled = false;
                messageForm.removeAttribute('aria-busy');

                if (sendButton) {
                    sendButton.disabled = false;
                }

                messageInput.focus();
            }
        });
    }


    /*
    |--------------------------------------------------------------------------
    | POLLING PESAN BARU
    |--------------------------------------------------------------------------
    */

    function appendMessage(item, deliveryStatus) {
        if (!chatMessages) {
            return;
        }

        const messageId = Number(item.id) || 0;
        const existingMessage = document.getElementById('chat-message-' + messageId);

        if (existingMessage) {
            if (deliveryStatus) {
                let status = existingMessage.querySelector('.message-status');

                if (!status) {
                    status = document.createElement('div');
                    status.className = 'message-status';
                    existingMessage.querySelector('.message-content').appendChild(status);
                }

                status.textContent = deliveryStatus;
            }

            return;
        }

        const isCustomer = item.sender === 'customer';

        const row = document.createElement('div');
        row.className = 'message-row ' + (isCustomer ? 'customer-message' : 'cs-message');
        row.id = 'chat-message-' + messageId;
        row.dataset.id = String(messageId);

        if (!isCustomer) {
            const avatar = document.createElement('div');
            avatar.className = 'message-avatar';
            const icon = document.createElement('i');
            icon.className = 'bi ' + (item.is_auto ? 'bi-journal-text' : 'bi-headset');
            avatar.appendChild(icon);
            row.appendChild(avatar);
        }

        const content = document.createElement('div');
        content.className = 'message-content';

        const name = document.createElement('div');
        name.className = 'message-name';
        name.textContent = String(item.label || '');

        const bubble = document.createElement('div');
        bubble.className = 'message-bubble';
        bubble.textContent = String(item.message || '');

        const time = document.createElement('div');
        time.className = 'message-time';
        time.textContent = String(item.time_label || '');

        content.append(name, bubble, time);

        if (deliveryStatus) {
            const status = document.createElement('div');
            status.className = 'message-status';
            status.textContent = deliveryStatus;
            content.appendChild(status);
        }

        row.appendChild(content);

        if (isCustomer) {
            const avatar = document.createElement('div');
            avatar.className = 'message-avatar customer-avatar';
            avatar.textContent = <?= json_encode($avatar, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT); ?>;
            row.appendChild(avatar);
        }

        chatMessages.appendChild(row);
    }

    let pollPending = false;

    async function pollMessages() {
        if (!chatReady || pollPending || document.hidden) {
            return;
        }

        pollPending = true;

        try {
            const url = 'chat.php?poll=1&topic=' + encodeURIComponent(currentTopic)
                + '&after=' + encodeURIComponent(lastMessageId);

            const response = await fetch(url, {
                headers: { Accept: 'application/json' },
                cache: 'no-store',
                credentials: 'same-origin'
            });

            if (!response.ok) {
                return;
            }

            const payload = await response.json();
            const items = Array.isArray(payload.messages) ? payload.messages : [];

            setHandlerNote(Boolean(payload.handled_by_cs));

            if (items.length === 0) {
                return;
            }

            const nearBottom = chatBody
                ? chatBody.scrollHeight - chatBody.scrollTop - chatBody.clientHeight < 120
                : true;

            const placeholder = document.getElementById('chatPlaceholder');

            if (placeholder) {
                placeholder.remove();
            }

            items.forEach(function (item) {
                if (Number(item.id) > lastMessageId) {
                    appendMessage(item);
                    lastMessageId = Number(item.id);
                }
            });

            if (nearBottom) {
                scrollToBottom();
            }
        } catch (error) {
            console.error('Pembaruan chat gagal:', error);
        } finally {
            pollPending = false;
        }
    }

    // Periksa balasan baru tiap 5 detik, dan segera saat tab dibuka kembali.
    window.setInterval(pollMessages, 5000);

    document.addEventListener('visibilitychange', function () {
        if (!document.hidden) {
            pollMessages();
        }
    });

});

</script>

</body>

</html>