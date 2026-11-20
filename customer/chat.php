<?php

session_start();

require_once "../config/auth.php";

requireLogin();

require_once "../config/database.php";

requireRole('customer');

date_default_timezone_set('Asia/Jakarta');

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);


/*
 * Masa tampil chat untuk customer (dalam jam). 24 = 1 hari (rolling 24 jam).
 * Isi 0 untuk mematikan (chat tampil selamanya).
 */
const CHAT_RETENTION_HOURS = 24;

/*
 * false = pesan lama hanya DISEMBUNYIKAN dari customer (data tetap ada untuk
 *         riwayat admin / laporan).
 * true  = pesan lama juga DIHAPUS PERMANEN dari database (dibersihkan otomatis
 *         secara berkala saat ada customer membuka halaman chat).
 */
const CHAT_PURGE_OLD = false;

$chatContacts = require __DIR__ . '/../config/chat_contacts.php';


/*
|--------------------------------------------------------------------------
| HELPER
|--------------------------------------------------------------------------
*/

function e($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function renderChatMessage(string $message): string
{
    $pattern = '~(https://www\.sinaralamabadi\.com/(?:tagihan|paket\.php\?mode=direct)|https://wa\.me/[0-9]+(?:\?[A-Za-z0-9%._~=&+-]*)?|tel:\+?[0-9]+)~';
    $html = preg_replace_callback($pattern, static function (array $matches): string {
        $url = $matches[0];
        $safeUrl = e($url);
        $target = strpos($url, 'tel:') === 0 ? '' : ' target="_blank" rel="noopener noreferrer"';

        return '<a href="' . $safeUrl . '"' . $target . '>' . $safeUrl . '</a>';
    }, e($message));

    return nl2br($html ?? e($message), false);
}


function tableExists(mysqli $conn, string $table): bool
{
    $table = $conn->real_escape_string($table);
    $result = $conn->query("SHOW TABLES LIKE '{$table}'");

    return $result && $result->num_rows > 0;
}


function columnExists(mysqli $conn, string $table, string $column): bool
{
    $table  = $conn->real_escape_string($table);
    $column = $conn->real_escape_string($column);
    $result = $conn->query("SHOW COLUMNS FROM `{$table}` LIKE '{$column}'");

    return $result && $result->num_rows > 0;
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

    $row  = [];
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

    return $time ? date('d M Y, H:i', $time) : '';
}


/**
 * sender_id = 0 pada sender_type 'cs' berarti balasan otomatis YeNa.
 */
function senderLabel(string $senderType, int $senderId): string
{
    if ($senderType === 'customer') {
        return 'Anda';
    }

    return $senderId === 0 ? 'YeNa' : 'Customer Care';
}


/*
|--------------------------------------------------------------------------
| BALASAN OTOMATIS
| Jawaban data akun dan alur menu diberikan YeNa; FAQ berasal dari tabel FAQ aktif.
|--------------------------------------------------------------------------
*/

/**
 * Mencari jawaban FAQ. Mengembalikan null bila tidak ada yang cocok.
 */
function findAutoReply(mysqli $conn, string $message, string $topic): ?string
{
    if (
        !tableExists($conn, 'faq_auto_replies') ||
        !columnExists($conn, 'faq_auto_replies', 'keywords') ||
        !columnExists($conn, 'faq_auto_replies', 'answer') ||
        !columnExists($conn, 'faq_auto_replies', 'topic') ||
        !columnExists($conn, 'faq_auto_replies', 'is_active')
    ) {
        return null;
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

        return $bestAnswer;

    } catch (Throwable $e) {
        error_log('Chat YESNET auto reply failed: ' . $e->getMessage());

        return null;
    }
}

/*
|--------------------------------------------------------------------------
| ROOM CHAT (chat_rooms)
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

    return isset($rows[0]['id']) ? (int) $rows[0]['id'] : null;
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
 * True bila Customer Care (manusia) sudah mengambil alih room ini.
 * - Jika kolom chat_rooms.ai_mode ada: ai_mode = 0 berarti diambil alih.
 * - Jika tidak ada, tetapi kolom assigned_to ada: terisi berarti sudah ditangani.
 */
function roomHandledByCs(mysqli $conn, ?int $roomId): bool
{
    if ($roomId === null) {
        return false;
    }

    if (columnExists($conn, 'chat_rooms', 'ai_mode')) {
        $column = 'ai_mode';
    } elseif (columnExists($conn, 'chat_rooms', 'assigned_to')) {
        $column = 'assigned_to';
    } else {
        return false;
    }

    $stmt = $conn->prepare("SELECT `{$column}` AS v FROM chat_rooms WHERE id = ? LIMIT 1");
    $stmt->bind_param("i", $roomId);
    $stmt->execute();
    $rows = statementFetchAll($stmt);
    $stmt->close();

    if (!isset($rows[0]) || $rows[0]['v'] === null) {
        return false;
    }

    return $column === 'ai_mode'
        ? (int) $rows[0]['v'] === 0
        : (int) $rows[0]['v'] > 0;
}


/*
|--------------------------------------------------------------------------
| PESAN (chat_messages)
| Kolom `topic` bersifat opsional agar tetap berjalan pada tabel lama.
|--------------------------------------------------------------------------
*/

function fetchRoomMessages(
    mysqli $conn,
    int $roomId,
    string $topic,
    bool $hasTopic,
    int $afterId,
    int $limit
): array {
    $sql = "
        SELECT id, sender_type, sender_id, message, created_at
        FROM chat_messages
        WHERE id_room = ?
          AND id > ?
    ";

    if (CHAT_RETENTION_HOURS > 0) {
        $sql .= " AND created_at >= (NOW() - INTERVAL " . (int) CHAT_RETENTION_HOURS . " HOUR) ";
    }

    if ($hasTopic) {
        $sql .= " AND topic = ? ";
    }

    $sql .= " ORDER BY id ASC LIMIT " . (int) $limit;

    $stmt = $conn->prepare($sql);

    if ($hasTopic) {
        $stmt->bind_param("iis", $roomId, $afterId, $topic);
    } else {
        $stmt->bind_param("ii", $roomId, $afterId);
    }

    $stmt->execute();
    $rows = statementFetchAll($stmt);
    $stmt->close();

    return $rows;
}


function insertChatMessage(
    mysqli $conn,
    int $roomId,
    string $senderType,
    int $senderId,
    string $message,
    string $topic,
    bool $hasTopic
): int {
    if ($hasTopic) {
        $stmt = $conn->prepare("
            INSERT INTO chat_messages
                (id_room, sender_type, sender_id, message, topic, created_at)
            VALUES (?, ?, ?, ?, ?, NOW())
        ");
        $stmt->bind_param("isiss", $roomId, $senderType, $senderId, $message, $topic);
    } else {
        $stmt = $conn->prepare("
            INSERT INTO chat_messages
                (id_room, sender_type, sender_id, message, created_at)
            VALUES (?, ?, ?, ?, NOW())
        ");
        $stmt->bind_param("isis", $roomId, $senderType, $senderId, $message);
    }

    $stmt->execute();
    $id = (int) $conn->insert_id;
    $stmt->close();

    return $id;
}


/** Tandai balasan Customer Care / asisten sebagai sudah dibaca customer. */
function markCsMessagesRead(mysqli $conn, int $roomId): void
{
    try {
        if (!columnExists($conn, 'chat_messages', 'is_read')) {
            return;
        }

        $stmt = $conn->prepare("
            UPDATE chat_messages
            SET is_read = 1
            WHERE id_room = ?
              AND sender_type <> 'customer'
              AND is_read = 0
        ");
        $stmt->bind_param("i", $roomId);
        $stmt->execute();
        $stmt->close();
    } catch (Throwable $e) {
        error_log('Chat YESNET mark read failed: ' . $e->getMessage());
    }
}


/**
 * Hapus permanen pesan yang melewati masa tampil (hanya bila CHAT_PURGE_OLD = true).
 */
function purgeOldChatMessages(mysqli $conn): void
{
    if (!CHAT_PURGE_OLD || CHAT_RETENTION_HOURS <= 0) {
        return;
    }

    try {
        $conn->query(
            "DELETE FROM chat_messages
             WHERE created_at < (NOW() - INTERVAL " . (int) CHAT_RETENTION_HOURS . " HOUR)
             LIMIT 500"
        );
    } catch (Throwable $e) {
        error_log('Chat YESNET purge failed: ' . $e->getMessage());
    }
}


/** "24 jam" atau "1 hari" untuk teks informasi. */
function retentionLabel(): string
{
    $hours = (int) CHAT_RETENTION_HOURS;

    if ($hours > 0 && $hours % 24 === 0) {
        return ($hours / 24) . ' hari';
    }

    return $hours . ' jam';
}


/*
|--------------------------------------------------------------------------
| KONTAK WHATSAPP
|--------------------------------------------------------------------------
*/

function normalizeWaNumber(string $number): string
{
    $digits = preg_replace('/\D+/', '', $number);

    if ($digits === '') {
        return '';
    }

    if (strpos($digits, '0') === 0) {
        return '62' . substr($digits, 1);
    }

    if (strpos($digits, '8') === 0) {
        return '62' . $digits;
    }

    return $digits;
}


/**
 * Hanya memilih wilayah jika lokasi pelanggan menyebutnya dengan jelas.
 */
function detectRegion(array $customer): string
{
    $text = '';

    foreach (['kota', 'city', 'wilayah', 'area', 'kabupaten', 'provinsi', 'alamat', 'address'] as $column) {
        if (!empty($customer[$column])) {
            $text .= ' ' . $customer[$column];
        }
    }

    if (preg_match('/jakarta|dki/i', $text)) {
        return 'jakarta';
    }

    return preg_match('/jawa timur|jatim|surabaya/i', $text) ? 'surabaya' : '';
}

function firstAvailableColumn(mysqli $conn, string $table, array $candidates): ?string
{
    foreach ($candidates as $candidate) {
        if (columnExists($conn, $table, $candidate)) {
            return $candidate;
        }
    }

    return null;
}

function formatYenaDate($value): string
{
    $value = trim((string) $value);
    if ($value === '') {
        return 'Tidak tersedia';
    }

    $timestamp = strtotime($value);
    return $timestamp ? date('d M Y', $timestamp) : $value;
}

function getCustomerBill(mysqli $conn, int $customerId): ?array
{
    if (
        !tableExists($conn, 'tagihan') ||
        !columnExists($conn, 'tagihan', 'id_customer')
    ) {
        return null;
    }

    $order = '';
    if (columnExists($conn, 'tagihan', 'status')) {
        $order = "CASE WHEN LOWER(status) IN ('unpaid', 'pending', 'overdue', 'belum bayar', 'belum dibayar', 'terlambat') THEN 0 ELSE 1 END";
    }

    $dueColumn = firstAvailableColumn($conn, 'tagihan', ['jatuh_tempo', 'tanggal_jatuh_tempo']);
    $orders = [];
    if ($order !== '') {
        $orders[] = $order;
    }
    if ($dueColumn !== null) {
        $orders[] = "`{$dueColumn}` ASC";
    }
    if (columnExists($conn, 'tagihan', 'id')) {
        $orders[] = 'id DESC';
    }

    $sql = 'SELECT * FROM tagihan WHERE id_customer = ?';
    if ($orders !== []) {
        $sql .= ' ORDER BY ' . implode(', ', $orders);
    }
    $sql .= ' LIMIT 1';

    $stmt = $conn->prepare($sql);
    $stmt->bind_param('i', $customerId);
    $stmt->execute();
    $rows = statementFetchAll($stmt);
    $stmt->close();

    return $rows[0] ?? null;
}

function yenaSupportMessage(
    string $team,
    string $kind,
    string $region,
    array $contacts,
    array $customer
): string {
    $teamLabel = $team === 'tech' ? 'Technical Support' : 'Customer Care';
    $message = 'Untuk pertanyaan ini, silakan hubungi ' . $teamLabel . '.';
    $cid = trim((string) ($customer['cid'] ?? ''));
    $waText = 'Halo ' . $teamLabel . ' Yesnet, saya ' . (string) ($customer['nama'] ?? 'pelanggan');
    if ($cid !== '') {
        $waText .= ' (CID ' . $cid . ')';
    }
    $waText .= ', mohon bantuannya.';

    $regions = $region !== '' ? [$region] : ['jakarta', 'surabaya'];
    $regionLabels = ['jakarta' => 'Jakarta', 'surabaya' => 'Jawa Timur'];
    $links = [];
    foreach ($regions as $contactRegion) {
        $number = normalizeWaNumber((string) ($contacts[$contactRegion][$kind] ?? ''));
        if ($number === '') {
            continue;
        }
        $label = $regionLabels[$contactRegion] ?? $contactRegion;
        $links[] = 'WhatsApp ' . $label . ': https://wa.me/' . $number . '?text=' . rawurlencode($waText);
        $links[] = 'Telepon ' . $label . ': tel:+' . $number;
    }

    return $links === []
        ? $message . "\nNomor kontak belum dikonfigurasi. Silakan hubungi administrator website."
        : $message . "\n" . implode("\n", $links);
}

function yenaMenu(): string
{
    return "Silakan pilih menu:\n"
        . "1️⃣ Cek Tagihan & Pembayaran\n"
        . "2️⃣ Laporan Gangguan Internet\n"
        . "3️⃣ Daftar Berlangganan Baru";
}

function processYenaMessage(
    mysqli $conn,
    string $message,
    array $customer,
    array $contacts,
    array $flow
): array {
    $answer = trim($message);
    $choice = trim($answer);
    $step = (string) ($flow['step'] ?? 'menu');
    $nextFlow = $flow;
    $customerId = (int) ($customer['id'] ?? 0);
    $cid = trim((string) ($customer['cid'] ?? ''));
    $region = (string) ($flow['region'] ?? detectRegion($customer));
    $accountName = trim((string) ($customer['nama'] ?? $customer['name'] ?? ''));
    $address = trim((string) ($customer['alamat'] ?? $customer['address'] ?? ''));

    if ($choice === '0') {
        return [yenaMenu(), ['step' => 'menu']];
    }

    if ($step === 'menu') {
        if ($choice === '1') {
            return [
                'Silakan masukkan Nomor Pelanggan (CID) Anda.',
                ['step' => 'billing_cid'],
            ];
        }
        if ($choice === '2') {
            return [
                "Pilih lokasi Anda:\n1. Jakarta\n2. Jawa Timur\n(Ketik 0 untuk kembali ke menu utama.)",
                ['step' => 'complaint_region'],
            ];
        }
        if ($choice === '3') {
            $careReply = yenaSupportMessage('care', 'care', detectRegion($customer), $contacts, $customer);
            return [
                "Terima kasih atas minat Anda berlangganan Yesnet. Silakan lihat pilihan paket dan lanjutkan pendaftaran melalui https://www.sinaralamabadi.com/paket.php?mode=direct atau hubungi Customer Care.\n"
                    . $careReply . "\n\nKetik 1 untuk kembali ke menu utama.",
                ['step' => 'registration_return'],
            ];
        }

        $faqTopic = 'general';
        if (preg_match('/tagih|bayar|pembayaran|invoice|billing/i', $answer)) {
            $faqTopic = 'billing';
        } elseif (preg_match('/wifi|internet|koneksi|gangguan|modem|jaringan/i', $answer)) {
            $faqTopic = 'wifi';
        } elseif (preg_match('/pasang|instal|daftar|berlangganan/i', $answer)) {
            $faqTopic = 'installation';
        }
        $faqReply = findAutoReply($conn, $answer, $faqTopic);
        if ($faqReply !== null) {
            return [$faqReply . "\n\n" . yenaMenu(), ['step' => 'menu']];
        }

        $isTech = preg_match('/wifi|internet|koneksi|gangguan|modem|jaringan|lambat|lemot|putus/i', $answer) === 1;
        $support = yenaSupportMessage(
            $isTech ? 'tech' : 'care',
            $isTech ? 'tech' : 'care',
            detectRegion($customer),
            $contacts,
            $customer
        );
        return [
            "Maaf, YeNa belum memiliki informasi yang cukup untuk menjawab pertanyaan tersebut. Petugas kami dapat membantu Anda.\n"
                . $support . "\n\n" . yenaMenu(),
            ['step' => 'menu'],
        ];
    }

    if ($step === 'billing_cid') {
        if (!preg_match('/^[A-Za-z0-9_-]{3,50}$/', $choice)) {
            return ['Format CID tidak valid. Masukkan CID Anda (3–50 karakter) atau ketik 0 untuk kembali ke menu utama.', $nextFlow];
        }
        if ($cid === '') {
            return [
                "CID belum tersedia pada akun Anda. Customer Care dapat membantu memeriksa data pelanggan.\n"
                    . yenaSupportMessage('care', 'care', detectRegion($customer), $contacts, $customer),
                ['step' => 'menu'],
            ];
        }
        if (strcasecmp($choice, $cid) !== 0) {
            return [
                "CID tidak sesuai dengan akun yang sedang Anda gunakan. Periksa kembali CID dan masukkan lagi, atau ketik 0 untuk kembali ke menu utama.\n"
                    . yenaSupportMessage('care', 'care', detectRegion($customer), $contacts, $customer),
                $nextFlow,
            ];
        }

        $bill = getCustomerBill($conn, $customerId);
        $amount = $bill['jumlah'] ?? $bill['total'] ?? $bill['nominal'] ?? $bill['amount'] ?? null;
        $period = trim((string) ($bill['periode'] ?? ''));
        if ($period === '' && isset($bill['periode_bulan'], $bill['periode_tahun'])) {
            $period = trim((string) $bill['periode_bulan'] . ' ' . (string) $bill['periode_tahun']);
        } elseif ($period === '' && isset($bill['periode_bulan'])) {
            $period = trim((string) $bill['periode_bulan']);
        }
        $status = trim((string) ($bill['status'] ?? ''));
        $statusLabels = [
            'paid' => 'Lunas',
            'lunas' => 'Lunas',
            'pending' => 'Menunggu pembayaran',
            'unpaid' => 'Belum dibayar',
            'belum bayar' => 'Belum dibayar',
            'belum dibayar' => 'Belum dibayar',
            'overdue' => 'Terlambat',
            'terlambat' => 'Terlambat',
        ];
        if ($status !== '') {
            $status = $statusLabels[strtolower($status)] ?? $status;
        }

        $packageName = trim((string) ($customer['chat_paket'] ?? $customer['nama_paket'] ?? ''));
        $billText = $bill === null
            ? "Periode Tagihan: Tidak ada data tagihan\nTotal Tagihan: Tidak tersedia\nJatuh Tempo: Tidak tersedia\nStatus Pembayaran: Tidak tersedia"
            : 'Periode Tagihan: ' . ($period !== '' ? $period : 'Tidak tersedia')
                . "\nTotal Tagihan: " . (is_numeric($amount) ? 'Rp ' . number_format((float) $amount, 0, ',', '.') : 'Tidak tersedia')
                . "\nJatuh Tempo: " . formatYenaDate($bill['jatuh_tempo'] ?? $bill['tanggal_jatuh_tempo'] ?? '')
                . "\nStatus Pembayaran: " . ($status !== '' ? $status : 'Tidak tersedia');

        return [
            "Berikut data pelanggan Anda:\n"
                . 'Nama Akun: ' . ($accountName !== '' ? $accountName : 'Tidak tersedia')
                . "\nCID: " . $cid
                . "\nPaket Layanan: " . ($packageName !== '' ? $packageName : 'Tidak tersedia')
                . "\nAlamat: " . ($address !== '' ? $address : 'Tidak tersedia')
                . "\n" . $billText
                . "\n\nMetode pembayaran: Virtual Account (BCA, Mandiri, BNI), E-Wallet (OVO, DANA, GoPay), dan QRIS."
                . "\n\nApakah Anda ingin mendapatkan link pembayaran?\n1. Ya, kirim link pembayaran.\n2. Tidak, kembali ke menu utama.",
            ['step' => 'billing_payment_choice'],
        ];
    }

    if ($step === 'billing_payment_choice') {
        if ($choice === '1') {
            return [
                "Silakan buka link pembayaran: https://www.sinaralamabadi.com/tagihan\nCantumkan CID Anda pada halaman pembayaran agar tagihan dapat dikenali.\n\n1. Hubungkan dengan CS.\n2. Kembali ke menu utama.",
                ['step' => 'billing_after_link'],
            ];
        }
        if ($choice === '2') {
            return [yenaMenu(), ['step' => 'menu']];
        }
        return ['Silakan pilih 1 untuk mendapatkan link pembayaran atau 2 untuk kembali ke menu utama.', $nextFlow];
    }

    if ($step === 'billing_after_link') {
        if ($choice === '1') {
            return [
                yenaSupportMessage('care', 'care', detectRegion($customer), $contacts, $customer),
                ['step' => 'menu'],
            ];
        }
        if ($choice === '2') {
            return [yenaMenu(), ['step' => 'menu']];
        }
        return ['Silakan pilih 1 untuk menghubungi Customer Care atau 2 untuk kembali ke menu utama.', $nextFlow];
    }

    if ($step === 'complaint_region') {
        if ($choice === '1' || $choice === '2') {
            $region = $choice === '1' ? 'jakarta' : 'surabaya';
            return [
                'Silakan masukkan CID atau Nomor Pelanggan Anda (ketik 0 untuk kembali ke menu utama).',
                ['step' => 'complaint_cid', 'region' => $region],
            ];
        }
        return ['Pilihan lokasi tidak valid. Pilih 1 untuk Jakarta, 2 untuk Jawa Timur, atau 0 untuk kembali ke menu utama.', $nextFlow];
    }

    if ($step === 'complaint_cid') {
        if (!preg_match('/^[A-Za-z0-9_-]{3,50}$/', $choice)) {
            return ['Format CID tidak valid. Masukkan CID Anda atau ketik 0 untuk kembali ke menu utama.', $nextFlow];
        }
        if ($cid === '') {
            return [
                "CID belum tersedia pada akun Anda. Technical Support dapat membantu memeriksa data pelanggan.\n"
                    . yenaSupportMessage('tech', 'tech', $region, $contacts, $customer),
                ['step' => 'menu'],
            ];
        }
        if (strcasecmp($choice, $cid) !== 0) {
            return [
                "CID tidak sesuai dengan akun yang sedang Anda gunakan. Periksa kembali CID dan masukkan lagi, atau ketik 0 untuk kembali ke menu utama.\n"
                    . yenaSupportMessage('tech', 'tech', $region, $contacts, $customer),
                $nextFlow,
            ];
        }

        $packageName = trim((string) ($customer['chat_paket'] ?? $customer['nama_paket'] ?? ''));
        return [
            "Data pelanggan:\n"
                . 'Nama Akun: ' . ($accountName !== '' ? $accountName : 'Tidak tersedia')
                . "\nCID: " . $cid
                . "\nPaket Layanan: " . ($packageName !== '' ? $packageName : 'Tidak tersedia')
                . "\nAlamat: " . ($address !== '' ? $address : 'Tidak tersedia')
                . "\n\nSilakan jelaskan keluhan atau gangguan internet yang Anda alami.",
            ['step' => 'complaint_description', 'region' => $region],
        ];
    }

    if ($step === 'complaint_description') {
        return [
            "Terima kasih, keluhan Anda telah dicatat dalam percakapan ini. Coba panduan penanganan awal berikut:\n"
                . "1. Restart modem untuk mengatasi masalah konektivitas.\n"
                . "2. Periksa jumlah perangkat yang terhubung karena terlalu banyak perangkat dapat memperlambat koneksi.\n"
                . "3. Periksa pembayaran agar layanan tidak terisolir.\n\n"
                . "Apakah masalah sudah teratasi?\n1. Ya, masalah sudah teratasi.\n2. Cek Billing.\n3. Belum teratasi, mohon menunggu tim kami menghubungi Anda.",
            ['step' => 'complaint_resolution', 'region' => $region],
        ];
    }

    if ($step === 'complaint_resolution') {
        if ($choice === '1') {
            return ["Senang mendengar masalah Anda sudah teratasi.\n\n" . yenaMenu(), ['step' => 'menu']];
        }
        if ($choice === '2') {
            return [
                "Silakan cek tagihan melalui https://www.sinaralamabadi.com/tagihan\nCantumkan CID Anda pada halaman pembayaran.\n\n1. Hubungkan dengan CS.\n2. Kembali ke menu utama.",
                ['step' => 'billing_after_link'],
            ];
        }
        if ($choice === '3') {
            return [
                "Keluhan Anda tersimpan dalam percakapan ini. Mohon menunggu tim Technical Support menghubungi Anda. Untuk bantuan langsung, hubungi petugas melalui kontak berikut.\n"
                    . yenaSupportMessage('tech', 'tech', $region, $contacts, $customer)
                    . "\n\n" . yenaMenu(),
                ['step' => 'menu'],
            ];
        }
        return ['Pilihan tidak valid. Pilih 1 jika sudah teratasi, 2 untuk cek billing, 3 untuk Technical Support, atau 0 untuk kembali ke menu utama.', $nextFlow];
    }

    if ($step === 'registration_return' && $choice === '1') {
        return [yenaMenu(), ['step' => 'menu']];
    }

    return [
        "Maaf, YeNa belum dapat memahami jawaban tersebut. Silakan hubungi tim yang sesuai:\n"
            . yenaSupportMessage('care', 'care', detectRegion($customer), $contacts, $customer)
            . "\n" . yenaSupportMessage('tech', 'tech', detectRegion($customer), $contacts, $customer)
            . "\n\n" . yenaMenu(),
        ['step' => 'menu'],
    ];
}


/*
|--------------------------------------------------------------------------
| CEK LOGIN
|--------------------------------------------------------------------------
*/

$userId = (int) ($_SESSION['user_id'] ?? 0);

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
    $customerJoin = '';
    $customerPackageSelect = 'NULL AS chat_paket';
    if (
        tableExists($conn, 'paket_wifi') &&
        columnExists($conn, 'customers', 'paket_id') &&
        columnExists($conn, 'paket_wifi', 'id') &&
        columnExists($conn, 'paket_wifi', 'nama_paket')
    ) {
        $customerJoin = 'LEFT JOIN paket_wifi p ON p.id = c.paket_id';
        $customerPackageSelect = 'p.nama_paket AS chat_paket';
    }

    $stmt = $conn->prepare("
        SELECT c.*, {$customerPackageSelect}
        FROM customers c
        {$customerJoin}
        WHERE c.user_id = ?
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
        "Chat YESNET customer query error: " . $e->getMessage() .
        " | file: " . $e->getFile() . " | line: " . $e->getLine()
    );

    http_response_code(500);
    die("Data halaman gagal dimuat. Periksa struktur tabel customers atau error log hosting.");
}

$customerId = (int) ($customer['id'] ?? 0);

if (
    !isset($_SESSION['yena_chat_flow']) ||
    (int) ($_SESSION['yena_chat_flow']['customer_id'] ?? 0) !== $customerId
) {
    $_SESSION['yena_chat_flow'] = ['customer_id' => $customerId, 'step' => 'menu'];
}

$yenaFlow = $_SESSION['yena_chat_flow'];

$nama = trim((string) (
    $customer['nama']
    ?? $customer['name']
    ?? $_SESSION['nama']
    ?? $_SESSION['username']
    ?? 'Customer'
));

if ($nama === '') {
    $nama = 'Customer';
}

$statusLangganan = normalizeSubscriptionStatus($customer['status_langganan'] ?? '');

$avatar = strtoupper(substr($nama, 0, 1));


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
$topicIcon  = $topicIcons[$topic];


/*
|--------------------------------------------------------------------------
| TABEL CHAT SIAP?
| `topic` TIDAK menjadi syarat. Bila kolomnya ada, riwayat difilter
| per topik; bila tidak ada, semua pesan tampil dalam satu percakapan.
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
    columnExists($conn, 'chat_messages', 'message');

$hasTopic = $chatReady && columnExists($conn, 'chat_messages', 'topic');

$hasLastMessageAt = $chatReady && columnExists($conn, 'chat_rooms', 'last_message_at');

// Pembersihan berkala (sekitar 1 dari 20 kunjungan) agar tidak membebani server.
if ($chatReady && CHAT_PURGE_OLD && !isset($_GET['poll']) && mt_rand(1, 20) === 1) {
    purgeOldChatMessages($conn);
}


/*
|--------------------------------------------------------------------------
| GET: POLLING PESAN BARU (JSON)
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['poll'])) {

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

            $rows = fetchRoomMessages($conn, $roomId, $topic, $hasTopic, $afterId, 50);

            foreach ($rows as $row) {
                $pollMessages[] = [
                    'id'         => (int) $row['id'],
                    'sender'     => (string) $row['sender_type'],
                    'label'      => senderLabel((string) $row['sender_type'], (int) $row['sender_id']),
                    'is_auto'    => (string) $row['sender_type'] !== 'customer'
                                    && (int) $row['sender_id'] === 0,
                    'message'    => (string) $row['message'],
                    'time_label' => formatWaktuChat($row['created_at'])
                ];
            }

            if (!empty($rows)) {
                markCsMessagesRead($conn, $roomId);
            }
        }
    } catch (Throwable $e) {
        error_log('Chat YESNET poll failed: ' . $e->getMessage());
        jsonResponse(['messages' => [], 'error' => 'Gagal memuat pesan.'], 500);
    }

    jsonResponse([
        'messages'      => $pollMessages,
        'handled_by_cs' => $pollHandledByCs,
        'flow_step'     => (string) ($_SESSION['yena_chat_flow']['step'] ?? 'menu')
    ]);
}


/*
|--------------------------------------------------------------------------
| POST: KIRIM PESAN
|--------------------------------------------------------------------------
*/

$messageError = '';
$draftMessage = '';
$isAjaxRequest = strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $postMessage = trim((string) ($_POST['message'] ?? ''));
    $postTopic   = (string) ($_POST['topic'] ?? 'general');
    $postToken   = (string) ($_POST['csrf_token'] ?? '');

    if (!isset($topicLabels[$postTopic])) {
        $postTopic = 'general';
    }

    $topic      = $postTopic;
    $topicLabel = $topicLabels[$topic];
    $topicIcon  = $topicIcons[$topic];

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

                // Jika Customer Care sudah mengambil alih, jangan kirim balasan otomatis.
                $handledByCs = roomHandledByCs($conn, $roomId);

                $autoReply = null;

                if (!$handledByCs) {
                    [$autoReply, $nextYenaFlow] = processYenaMessage(
                        $conn,
                        $postMessage,
                        $customer,
                        $chatContacts,
                        $yenaFlow
                    );
                }

                $autoReplyMessageId = 0;

                $conn->begin_transaction();

                try {
                    $customerMessageId = insertChatMessage(
                        $conn, $roomId, 'customer', $customerId, $postMessage, $postTopic, $hasTopic
                    );

                    if ($autoReply !== null) {
                        $autoReplyMessageId = insertChatMessage(
                            $conn, $roomId, 'cs', 0, $autoReply, $postTopic, $hasTopic
                        );
                    }

                    if ($hasLastMessageAt) {
                        $stmt = $conn->prepare("UPDATE chat_rooms SET last_message_at = NOW() WHERE id = ?");
                        $stmt->bind_param("i", $roomId);
                        $stmt->execute();
                        $stmt->close();
                    }

                    $conn->commit();
                    if ($autoReply !== null) {
                        $_SESSION['yena_chat_flow'] = array_merge(
                            ['customer_id' => $customerId],
                            $nextYenaFlow
                        );
                        $yenaFlow = $_SESSION['yena_chat_flow'];
                    }
                } catch (Throwable $inner) {
                    $conn->rollback();
                    throw $inner;
                }

                if ($isAjaxRequest) {
                    $messageTime = formatWaktuChat(date('Y-m-d H:i:s'));

                    $responseMessages = [[
                        'id'         => $customerMessageId,
                        'sender'     => 'customer',
                        'label'      => 'Anda',
                        'is_auto'    => false,
                        'message'    => $postMessage,
                        'time_label' => $messageTime
                    ]];

                    if ($autoReply !== null) {
                        $responseMessages[] = [
                            'id'         => $autoReplyMessageId,
                            'sender'     => 'cs',
                            'label'      => senderLabel('cs', 0),
                            'is_auto'    => true,
                            'message'    => $autoReply,
                            'time_label' => $messageTime
                        ];
                    }

                    jsonResponse([
                        'messages'      => $responseMessages,
                        'handled_by_cs' => $handledByCs,
                        'flow_step'     => (string) ($_SESSION['yena_chat_flow']['step'] ?? 'menu')
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
| KONTAK WHATSAPP (Customer Care / Technical Support, per wilayah)
| Umum, Tagihan, Instalasi -> Customer Care
| WiFi, Gangguan           -> Technical Support
|--------------------------------------------------------------------------
*/

$region  = detectRegion($customer);
$useTech = in_array($topic, ['wifi', 'complaint'], true);

$waTeam   = $useTech ? 'Technical Support' : 'Customer Care';
$waNumber = normalizeWaNumber((string) ($chatContacts[$region][$useTech ? 'tech' : 'care'] ?? ''));
$waText   = 'Halo ' . $waTeam . ' YESNET, saya ' . $nama
          . ' (ID pelanggan #' . $customerId . '). '
          . 'Saya ingin bertanya terkait ' . $topicLabel . '.';

$waLink = $waNumber !== ''
    ? 'https://wa.me/' . $waNumber . '?text=' . rawurlencode($waText)
    : '';
$phoneLink = $waNumber !== '' ? 'tel:+' . $waNumber : '';


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
            $messages = fetchRoomMessages($conn, $currentRoomId, $topic, $hasTopic, 0, 200);

            if (!empty($messages)) {
                markCsMessagesRead($conn, $currentRoomId);
            }
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

$quickQuestionOptions = [
    'menu' => [
        '1️⃣ Cek Tagihan & Pembayaran' => '1',
        '2️⃣ Laporan Gangguan Internet' => '2',
        '3️⃣ Daftar Berlangganan Baru' => '3',
    ],
    'billing_cid' => ['Kembali ke menu utama' => '0'],
    'billing_payment_choice' => ['Ya, kirim link pembayaran' => '1', 'Kembali ke menu utama' => '2'],
    'billing_after_link' => ['Hubungkan dengan CS' => '1', 'Kembali ke menu utama' => '2'],
    'complaint_region' => ['Jakarta' => '1', 'Jawa Timur' => '2', 'Kembali ke menu utama' => '0'],
    'complaint_cid' => ['Kembali ke menu utama' => '0'],
    'complaint_description' => ['Kembali ke menu utama' => '0'],
    'complaint_resolution' => [
        'Ya, masalah sudah teratasi' => '1',
        'Cek Billing' => '2',
        'Belum teratasi, hubungi Technical Support' => '3',
    ],
    'registration_return' => ['Kembali ke menu utama' => '1'],
];
$currentQuickQuestions = $quickQuestionOptions[$yenaFlow['step'] ?? 'menu'] ?? $quickQuestionOptions['menu'];


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
        $sqlComplaint = "SELECT COUNT(*) AS total FROM complaint WHERE id_customer = ?";

        if (columnExists($conn, 'complaint', 'status')) {
            $sqlComplaint .= " AND LOWER(status) NOT IN ('closed', 'resolved')";
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

// Cache-busting CSS agar perubahan langsung terbaca browser.
function assetVersion(string $relativePath): int
{
    $file = __DIR__ . '/' . $relativePath;

    return is_file($file) ? (int) filemtime($file) : 1;
}

?>
<!DOCTYPE html>
<html lang="id">

<head>

    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#0f4cdb">

    <title>YeNa - Virtual Assistant Yesnet</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">

    <!-- Dashboard CSS (shell bersama) -->
    <link rel="stylesheet" href="assets/css/dashboard.css?v=<?= assetVersion('assets/css/dashboard.css'); ?>">

    <!-- Halaman Chat (harus setelah dashboard.css dan Bootstrap) -->
    <link rel="stylesheet" href="assets/css/chat.css?v=<?= assetVersion('assets/css/chat.css'); ?>">

    <link rel="stylesheet" href="assets/css/portal-unified.css">
    <script defer src="assets/js/portal-ui.js"></script>

</head>


<body class="dashboard-page chat-page">

<div class="customer-layout">

    <div class="sidebar-overlay" id="sidebarOverlay"></div>


    <!-- SIDEBAR -->
    <aside class="sidebar" id="sidebar">

        <div class="sidebar-brand">

            <div class="brand-logo"><i class="bi bi-wifi"></i></div>

            <div class="brand-text">
                <strong>YESNET</strong>
                <span>Customer Portal</span>
            </div>

            <button type="button" class="sidebar-close" id="sidebarClose">
                <i class="bi bi-x-lg"></i>
            </button>

        </div>


        <div class="sidebar-scroll">

            <div class="menu-section">

                <div class="menu-label">UTAMA</div>

                <a href="dashboard.php" class="menu-item <?= $currentPage === 'dashboard.php' ? 'active' : ''; ?>">
                    <span class="menu-icon"><i class="bi bi-grid-fill"></i></span>
                    <span class="menu-text">Dashboard</span>
                </a>

                <a href="usage.php" class="menu-item <?= $currentPage === 'usage.php' ? 'active' : ''; ?>">
                    <span class="menu-icon"><i class="bi bi-router-fill"></i></span>
                    <span class="menu-text">Internet Saya</span>
                </a>

                <a href="billing.php" class="menu-item <?= $currentPage === 'billing.php' ? 'active' : ''; ?>">
                    <span class="menu-icon"><i class="bi bi-receipt-cutoff"></i></span>
                    <span class="menu-text">Tagihan Saya</span>
                </a>

                <a href="billing.php#riwayat-pembayaran" class="menu-item">
                    <span class="menu-icon"><i class="bi bi-clock-history"></i></span>
                    <span class="menu-text">Riwayat Pembayaran</span>
                </a>

                <a href="speedtest.php" class="menu-item <?= $currentPage === 'speedtest.php' ? 'active' : ''; ?>">
                    <span class="menu-icon"><i class="bi bi-speedometer2"></i></span>
                    <span class="menu-text">Speed Test</span>
                </a>

                <a href="complaint.php" class="menu-item <?= $currentPage === 'complaint.php' ? 'active' : ''; ?>">
                    <span class="menu-icon"><i class="bi bi-tools"></i></span>
                    <span class="menu-text">Laporan Gangguan</span>
                    <?php if ($totalGangguan > 0): ?>
                        <span class="menu-badge"><?= $totalGangguan; ?></span>
                    <?php endif; ?>
                </a>

                <a href="network_status.php" class="menu-item <?= $currentPage === 'network_status.php' ? 'active' : ''; ?>">
                    <span class="menu-icon"><i class="bi bi-globe2"></i></span>
                    <span class="menu-text">Status Jaringan</span>
                </a>

                <a href="upgrade.php" class="menu-item <?= $currentPage === 'upgrade.php' ? 'active' : ''; ?>">
                    <span class="menu-icon"><i class="bi bi-arrow-up-circle-fill"></i></span>
                    <span class="menu-text">Upgrade Paket</span>
                </a>

                <a href="chat.php" class="menu-item <?= $currentPage === 'chat.php' ? 'active' : ''; ?>">
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


        <div class="sidebar-user">

            <div class="sidebar-user-avatar"><?= e($avatar); ?></div>

            <div class="sidebar-user-info">
                <strong><?= e($nama); ?></strong>
                <span>ID pelanggan #<?= (int) $customerId; ?></span>
            </div>

            <i class="bi bi-three-dots"></i>

        </div>

    </aside>


    <!-- MAIN CONTENT -->
    <main class="main-content">

        <header class="topbar">

            <div class="topbar-left">

                <button type="button" class="mobile-menu-button" id="mobileMenuButton">
                    <i class="bi bi-list"></i>
                </button>

                <div>
                    <div class="breadcrumb-text">
                        Customer Portal
                        <span>/</span>
                        Bantuan
                    </div>

                    <h1>YeNa Virtual Assistant</h1>
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

                <a href="notifikasi.php" class="notification-button" title="Notifikasi">
                    <i class="bi bi-bell"></i>

                    <span class="notification-count" <?= $unreadNotifications > 0 ? '' : 'hidden'; ?>><?= $unreadNotifications > 99 ? '99+' : $unreadNotifications; ?></span>
                </a>

                <div class="top-profile">

                    <div class="top-profile-avatar"><?= e($avatar); ?></div>

                    <div class="top-profile-info">
                        <strong><?= e($nama); ?></strong>
                        <span>ID pelanggan #<?= (int) $customerId; ?></span>
                    </div>

                    <i class="bi bi-chevron-down"></i>

                </div>

            </div>

        </header>


        <div class="dashboard-content chat-content">

            <!-- PANEL CHAT -->
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
                        <i class="bi bi-stars"></i>
                        YeNa · Virtual Assistant Yesnet
                    </span>

                </div>


                <nav class="chat-topics" aria-label="Pilih topik bantuan">

                    <?php foreach ($topicShort as $key => $label): ?>

                        <a href="chat.php?topic=<?= e($key); ?>" class="chat-topic <?= $topic === $key ? 'active' : ''; ?>">
                            <i class="bi <?= e($topicIcons[$key]); ?>"></i>
                            <?= e($label); ?>
                        </a>

                    <?php endforeach; ?>

                </nav>


                <?php if ($waLink !== ''): ?>

                    <div class="chat-wa-bar">

                        <div class="chat-wa-text">
                            <strong>Butuh respon langsung?</strong>
                            <span>
                                <?= $useTech
                                    ? 'Masalah WiFi atau gangguan ditangani tim Technical Support.'
                                    : 'Pertanyaan umum, tagihan, dan instalasi ditangani tim Customer Care.'; ?>
                            </span>
                        </div>

                        <a
                            href="<?= e($waLink); ?>"
                            class="chat-wa-button <?= $useTech ? 'is-tech' : 'is-care'; ?>"
                            target="_blank"
                            rel="noopener noreferrer"
                        >
                            <i class="bi bi-whatsapp"></i>
                            Hubungi <?= e($waTeam); ?> via WhatsApp
                        </a>
                        <?php if ($phoneLink !== ''): ?>
                            <a
                                href="<?= e($phoneLink); ?>"
                                class="chat-wa-button <?= $useTech ? 'is-tech' : 'is-care'; ?>"
                            >
                                <i class="bi bi-telephone"></i>
                                Telepon <?= e($waTeam); ?>
                            </a>
                        <?php endif; ?>

                    </div>

                <?php endif; ?>


                <div class="chat-handler-note" id="chatHandlerNote" role="status" <?= $handledByCs ? '' : 'hidden'; ?>>
                    <i class="bi bi-headset"></i>
                    <span>Customer Care sedang menangani percakapan ini. Balasan akan muncul di sini.</span>
                </div>


                <div class="chat-body" id="chatBody">

                    <?php if (CHAT_RETENTION_HOURS > 0): ?>
                        <div class="chat-retention">
                            <i class="bi bi-clock-history"></i>
                            Pesan otomatis hilang setelah <?= e(retentionLabel()); ?>
                        </div>
                    <?php endif; ?>

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

                                <div class="message-avatar"><i class="bi bi-stars"></i></div>

                                <div class="message-content">
                                    <div class="message-name">YeNa</div>
                                    <div class="message-bubble">Hai 👋<br>
                                        Selamat datang di Virtual Assistant YeNa. Kami akan membantu Anda seputar layanan internet Yesnet.<br><br>
                                        <?= nl2br(e(yenaMenu()), false); ?></div>
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
                                            <i class="bi <?= $isAuto ? 'bi-stars' : 'bi-headset'; ?>"></i>
                                        </div>
                                    <?php endif; ?>

                                    <div class="message-content">

                                        <div class="message-name">
                                            <?= e(senderLabel((string) $msg['sender_type'], (int) $msg['sender_id'])); ?>
                                        </div>

                                        <div class="message-bubble"><?= renderChatMessage((string) $msg['message']); ?></div>

                                        <div class="message-time"><?= e(formatWaktuChat($msg['created_at'])); ?></div>

                                    </div>

                                    <?php if ($isCustomer): ?>
                                        <div class="message-avatar customer-avatar"><?= e($avatar); ?></div>
                                    <?php endif; ?>

                                </div>

                            <?php endforeach; ?>

                        <?php endif; ?>

                    </div>

                </div>


                <?php if ($chatReady): ?>

                    <div class="chat-quick">

                        <span>Pertanyaan cepat</span>

                        <div class="chat-quick-list">

                            <?php foreach ($currentQuickQuestions as $label => $question): ?>
                                <button type="button" class="quick-question" data-question="<?= e($question); ?>">
                                    <?= e($label); ?>
                                </button>
                            <?php endforeach; ?>

                        </div>

                    </div>

                <?php endif; ?>


                <div class="chat-footer">

                    <?php if ($messageError !== ''): ?>

                        <div class="chat-error" role="alert">
                            <i class="bi bi-exclamation-circle"></i>
                            <span><?= e($messageError); ?></span>
                        </div>

                    <?php endif; ?>

                    <form method="POST" action="chat.php?topic=<?= e($topic); ?>" class="message-form" id="messageForm">

                        <input type="hidden" name="topic" value="<?= e($topic); ?>">
                        <input type="hidden" name="csrf_token" value="<?= e($csrfToken); ?>">

                        <div class="message-input-wrapper">

                            <textarea
                                name="message"
                                id="messageInput"
                                rows="1"
                                placeholder="Tulis pesan Anda..."
                                maxlength="2000"
                                <?= $chatReady ? '' : 'disabled'; ?>
                            ><?= e($draftMessage); ?></textarea>

                            <span class="character-count" id="characterCount">0/2000</span>

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
                            <?= CHAT_RETENTION_HOURS > 0
                                ? 'Pesan dihapus otomatis setelah ' . e(retentionLabel())
                                : 'Pesan tersimpan di akun kamu'; ?>
                        </span>
                        <span>Enter untuk kirim · Shift+Enter baris baru</span>
                    </div>

                </div>

            </section>


            <!-- PANEL INFO -->
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
                            <i class="bi bi-stars"></i>
                            <span>
                                <strong>Asisten Virtual</strong>
                                Menjawab dari data akun dan FAQ terdaftar. Jika pertanyaan belum dapat dijawab, YeNa mengarahkan Anda ke tim yang sesuai.
                            </span>
                        </div>

                        <div>
                            <i class="bi bi-headset"></i>
                            <span>
                                <strong>Tim Customer Care</strong>
                                Pesan kamu tersimpan dan dapat dibalas oleh Customer Care. Halaman ini memeriksa balasan baru secara berkala.
                            </span>
                        </div>

                        <div>
                            <i class="bi bi-tools"></i>
                            <span>
                                <strong>Gangguan teknis?</strong>
                                Gunakan <a href="complaint.php">Laporan Gangguan</a> agar tercatat dan ditindaklanjuti Technical Support.
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

    /* MOBILE SIDEBAR */

    const sidebar = document.getElementById('sidebar');
    const overlay = document.getElementById('sidebarOverlay');
    const mobileButton = document.getElementById('mobileMenuButton');
    const closeButton = document.getElementById('sidebarClose');

    function openSidebar() {
        if (!sidebar) { return; }
        sidebar.classList.add('show');
        if (overlay) { overlay.classList.add('show'); }
        document.body.classList.add('sidebar-open');
    }

    function closeSidebar() {
        if (!sidebar) { return; }
        sidebar.classList.remove('show');
        if (overlay) { overlay.classList.remove('show'); }
        document.body.classList.remove('sidebar-open');
    }

    if (mobileButton) { mobileButton.addEventListener('click', openSidebar); }
    if (closeButton) { closeButton.addEventListener('click', closeSidebar); }
    if (overlay) { overlay.addEventListener('click', closeSidebar); }


    /* THEME */

    const themeToggle = document.getElementById('themeToggle');
    const themeToggleIcon = document.getElementById('themeToggleIcon');

    function applyTheme(theme) {
        const isDark = theme === 'dark';
        document.body.dataset.theme = isDark ? 'dark' : 'light';

        if (themeToggle) {
            themeToggle.setAttribute('aria-pressed', isDark ? 'true' : 'false');
            themeToggle.setAttribute('aria-label', isDark ? 'Aktifkan tema terang' : 'Aktifkan tema gelap');
        }

        if (themeToggleIcon) {
            themeToggleIcon.className = isDark ? 'bi bi-sun' : 'bi bi-moon-stars';
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
            const nextTheme = document.body.dataset.theme === 'dark' ? 'light' : 'dark';
            applyTheme(nextTheme);

            try {
                localStorage.setItem('yesnet-customer-theme', nextTheme);
            } catch (error) {
                console.warn('Preferensi tema tidak dapat disimpan.', error);
            }
        });
    }


    /* CHAT */

    const chatBody = document.getElementById('chatBody');
    const chatMessages = document.getElementById('chatMessages');
    const chatHandlerNote = document.getElementById('chatHandlerNote');
    const messageInput = document.getElementById('messageInput');
    const characterCount = document.getElementById('characterCount');
    const messageForm = document.getElementById('messageForm');
    const sendButton = document.getElementById('sendButton');

    const currentTopic = <?= json_encode($topic, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT); ?>;
    const chatReady = <?= $chatReady ? 'true' : 'false'; ?>;
    const customerAvatarText = <?= json_encode($avatar, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT); ?>;
    const quickQuestionOptions = <?= json_encode($quickQuestionOptions, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT); ?>;
    let lastMessageId = <?= (int) $lastMessageId; ?>;

    function setHandlerNote(isHandled) {
        if (chatHandlerNote) { chatHandlerNote.hidden = !isHandled; }
    }

    function updateQuickQuestions(step) {
        const quickList = document.querySelector('.chat-quick-list');
        const options = quickQuestionOptions[step] || quickQuestionOptions.menu;
        if (!quickList) { return; }

        while (quickList.firstChild) {
            quickList.removeChild(quickList.firstChild);
        }

        Object.keys(options).forEach(function (label) {
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'quick-question';
            button.dataset.question = options[label];
            button.textContent = label;
            button.addEventListener('click', function () {
                if (!messageInput) { return; }
                messageInput.value = button.dataset.question || '';
                messageInput.focus();
                updateCharacterCount();
                autoResize();
            });
            quickList.appendChild(button);
        });
    }

    function scrollToBottom() {
        if (chatBody) { chatBody.scrollTop = chatBody.scrollHeight; }
    }

    function updateCharacterCount() {
        if (messageInput && characterCount) {
            characterCount.textContent = messageInput.value.length + '/2000';
        }
    }

    function autoResize() {
        if (!messageInput) { return; }
        messageInput.style.height = 'auto';
        messageInput.style.height = Math.min(messageInput.scrollHeight, 120) + 'px';
    }

    scrollToBottom();
    updateCharacterCount();
    autoResize();

    document.querySelectorAll('.quick-question').forEach(function (button) {
        button.addEventListener('click', function () {
            if (!messageInput) { return; }
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

    /* Indikator "mengetik" saat menunggu balasan asisten */

    function showTyping() {
        if (!chatMessages || document.getElementById('typingIndicator')) { return; }

        const row = document.createElement('div');
        row.className = 'message-row cs-message typing-row';
        row.id = 'typingIndicator';

        const avatar = document.createElement('div');
        avatar.className = 'message-avatar';
        const icon = document.createElement('i');
        icon.className = 'bi bi-stars';
        avatar.appendChild(icon);

        const content = document.createElement('div');
        content.className = 'message-content';

        const bubble = document.createElement('div');
        bubble.className = 'message-bubble typing-bubble';
        bubble.setAttribute('aria-label', 'Asisten sedang mengetik');
        bubble.append(
            document.createElement('span'),
            document.createElement('span'),
            document.createElement('span')
        );

        content.appendChild(bubble);
        row.append(avatar, content);
        chatMessages.appendChild(row);
        scrollToBottom();
    }

    function hideTyping() {
        const typing = document.getElementById('typingIndicator');
        if (typing) { typing.remove(); }
    }

    function appendMessage(item, deliveryStatus) {
        if (!chatMessages) { return; }

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
            icon.className = 'bi ' + (item.is_auto ? 'bi-stars' : 'bi-headset');
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
        appendMessageText(bubble, String(item.message || ''));

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
            avatar.textContent = customerAvatarText;
            row.appendChild(avatar);
        }

        chatMessages.appendChild(row);
    }

    function appendMessageText(element, message) {
        const urlPattern = /(https:\/\/www\.sinaralamabadi\.com\/(?:tagihan|paket\.php\?mode=direct)|https:\/\/wa\.me\/[0-9]+(?:\?[A-Za-z0-9%._~=&+-]*)?|tel:\+?[0-9]+)/g;
        let lastIndex = 0;
        let match;

        function appendText(text) {
            const lines = text.split('\n');
            lines.forEach(function (line, index) {
                if (index > 0) { element.appendChild(document.createElement('br')); }
                element.appendChild(document.createTextNode(line));
            });
        }

        while ((match = urlPattern.exec(message)) !== null) {
            appendText(message.slice(lastIndex, match.index));
            const link = document.createElement('a');
            link.href = match[0];
            link.textContent = match[0];
            if (match[0].indexOf('tel:') !== 0) {
                link.target = '_blank';
                link.rel = 'noopener noreferrer';
            }
            element.appendChild(link);
            lastIndex = match.index + match[0].length;
        }

        appendText(message.slice(lastIndex));
    }

    // Kirim melalui Fetch; tetap berfungsi tanpa JavaScript (form POST biasa).
    if (messageForm) {
        messageForm.addEventListener('submit', async function (event) {
            event.preventDefault();

            if (!messageInput || messageInput.value.trim() === '' || (sendButton && sendButton.disabled)) {
                return;
            }

            const formData = new FormData(messageForm);
            const previousError = document.querySelector('.chat-error');

            if (previousError) { previousError.remove(); }
            if (sendButton) { sendButton.disabled = true; }

            messageInput.disabled = true;
            messageForm.setAttribute('aria-busy', 'true');
            showTyping();

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

                hideTyping();

                const placeholder = document.getElementById('chatPlaceholder');
                if (placeholder) { placeholder.remove(); }

                (Array.isArray(payload.messages) ? payload.messages : []).forEach(function (item) {
                    appendMessage(item, item.sender === 'customer' ? 'Terkirim' : '');

                    if (Number(item.id) > lastMessageId) {
                        lastMessageId = Number(item.id);
                    }
                });

                setHandlerNote(Boolean(payload.handled_by_cs));
                if (payload.flow_step) { updateQuickQuestions(String(payload.flow_step)); }

                messageInput.value = '';
                updateCharacterCount();
                autoResize();
                scrollToBottom();
            } catch (error) {
                hideTyping();
                showSendError(error.message || 'Pesan gagal dikirim. Silakan coba lagi.');
            } finally {
                messageInput.disabled = false;
                messageForm.removeAttribute('aria-busy');

                if (sendButton) { sendButton.disabled = false; }

                messageInput.focus();
            }
        });
    }


    /* POLLING PESAN BARU */

    let pollPending = false;

    async function pollMessages() {
        if (!chatReady || pollPending || document.hidden) { return; }

        pollPending = true;

        try {
            const url = 'chat.php?poll=1&topic=' + encodeURIComponent(currentTopic)
                + '&after=' + encodeURIComponent(lastMessageId);

            const response = await fetch(url, {
                headers: { Accept: 'application/json' },
                cache: 'no-store',
                credentials: 'same-origin'
            });

            if (!response.ok) { return; }

            const payload = await response.json();
            const items = Array.isArray(payload.messages) ? payload.messages : [];

            setHandlerNote(Boolean(payload.handled_by_cs));
            if (payload.flow_step) { updateQuickQuestions(String(payload.flow_step)); }

            if (items.length === 0) { return; }

            const nearBottom = chatBody
                ? chatBody.scrollHeight - chatBody.scrollTop - chatBody.clientHeight < 120
                : true;

            const placeholder = document.getElementById('chatPlaceholder');
            if (placeholder) { placeholder.remove(); }

            items.forEach(function (item) {
                if (Number(item.id) > lastMessageId) {
                    appendMessage(item);
                    lastMessageId = Number(item.id);
                }
            });

            if (nearBottom) { scrollToBottom(); }
        } catch (error) {
            console.error('Pembaruan chat gagal:', error);
        } finally {
            pollPending = false;
        }
    }

    window.setInterval(pollMessages, 5000);

    document.addEventListener('visibilitychange', function () {
        if (!document.hidden) { pollMessages(); }
    });

});

</script>

</body>

</html>