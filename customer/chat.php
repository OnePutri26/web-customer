<?php

require_once "../config/database.php";
require_once "../config/auth.php";

requireRole('customer');

$userId = $_SESSION['user_id'];

$stmt = $conn->prepare("
    SELECT *
    FROM customers
    WHERE user_id = ?
    LIMIT 1
");

$stmt->bind_param("i", $userId);
$stmt->execute();

$customer = $stmt->get_result()->fetch_assoc();

if (!$customer) {
    die("Data customer tidak ditemukan.");
}


$customerId = $customer['id'];

$nama = $customer['nama'] ?? 'Customer';

$initial = strtoupper(
    substr(trim($nama), 0, 1)
);

$topic = $_GET['topic'] ?? 'general';

$allowedTopics = [
    'general',
    'wifi',
    'billing',
    'complaint',
    'installation'
];

if (!in_array($topic, $allowedTopics, true)) {
    $topic = 'general';
}

$topicLabels = [

    'general' => 'Pertanyaan Umum',

    'wifi' => 'Bantuan WiFi',

    'billing' => 'Tagihan',

    'complaint' => 'Gangguan Internet',

    'installation' => 'Instalasi'

];

$topicLabel =
    $topicLabels[$topic] ??
    'Pertanyaan Umum';

$topicIcons = [

    'general' => 'bi-chat-dots',

    'wifi' => 'bi-wifi',

    'billing' => 'bi-credit-card',

    'complaint' => 'bi-tools',

    'installation' => 'bi-calendar-check'

];

$topicIcon =
    $topicIcons[$topic] ??
    'bi-chat-dots';

$messageError = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $message = trim(
        $_POST['message'] ?? ''
    );

    $postTopic =
        $_POST['topic'] ?? 'general';


    if (
        !in_array(
            $postTopic,
            $allowedTopics,
            true
        )
    ) {
        $postTopic = 'general';
    }


    if ($message === '') {

        $messageError =
            'Pesan tidak boleh kosong.';

    } else {

        try {

            $stmt = $conn->prepare("
                INSERT INTO chat_messages
                (
                    id_customer,
                    sender,
                    message,
                    topic,
                    created_at
                )
                VALUES
                (
                    ?,
                    'customer',
                    ?,
                    ?,
                    NOW()
                )
            ");

            $stmt->bind_param(
                "iss",
                $customerId,
                $message,
                $postTopic
            );

            $stmt->execute();

            header(
                "Location: chat.php?topic=" .
                urlencode($postTopic)
            );

            exit;

        } catch (Exception $e) {

            $messageError =
                'Pesan belum dapat disimpan. Pastikan tabel chat_messages tersedia.';
        }
    }
}

$messages = [];


try {

    $stmt = $conn->prepare("
        SELECT
            id,
            sender,
            message,
            topic,
            created_at
        FROM chat_messages
        WHERE id_customer = ?
        AND topic = ?
        ORDER BY created_at ASC
    ");

    $stmt->bind_param(
        "is",
        $customerId,
        $topic
    );

    $stmt->execute();

    $result =
        $stmt->get_result();


    while ($row = $result->fetch_assoc()) {

        $messages[] = $row;

    }

} catch (Exception $e) {

    $messages = [];

}

if (empty($messages)) {

    $messages[] = [

        'id' => 0,

        'sender' => 'cs',

        'message' =>
            'Halo ' .
            $nama .
            '! 👋 Ada yang bisa kami bantu terkait layanan internet kamu?',

        'topic' => $topic,

        'created_at' =>
            date('Y-m-d H:i:s')

    ];

}

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

$currentQuickQuestions =
    $quickQuestions[$topic] ??
    $quickQuestions['general'];

?>

<!DOCTYPE html>
<html lang="id">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>
        Chat CS | Customer Portal
    </title>

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
        rel="stylesheet"
    >

    <link
        rel="stylesheet"
        href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css"
    >

    <link
        rel="stylesheet"
        href="assets/css/chat.css"
    >

</head>


<body>

<aside class="sidebar">


    <!-- LOGO -->

    <div class="sidebar-logo">

        <div class="logo-icon">

            <i class="bi bi-wifi"></i>

        </div>


        <div class="logo-text">

            <h5>
                WiFi Management
            </h5>

            <span>
                Customer Portal
            </span>

        </div>

    </div>


    <!-- MENU -->

    <div class="sidebar-menu">

        <p class="menu-title">
            MENU
        </p>


        <a
            href="dashboard.php"
            class="menu-item"
        >

            <i class="bi bi-grid-fill"></i>

            <span>
                Dashboard
            </span>

        </a>


        <a
            href="billing.php"
            class="menu-item"
        >

            <i class="bi bi-credit-card-fill"></i>

            <span>
                Tagihan
            </span>

        </a>


        <a
            href="usage.php"
            class="menu-item"
        >

            <i class="bi bi-speedometer2"></i>

            <span>
                Pemakaian
            </span>

        </a>


        <a
            href="speedtest.php"
            class="menu-item"
        >

            <i class="bi bi-lightning-charge-fill"></i>

            <span>
                Speed Test
            </span>

        </a>


        <a
            href="complaint.php"
            class="menu-item"
        >

            <i class="bi bi-tools"></i>

            <span>
                Gangguan
            </span>

        </a>


        <a
            href="network_status.php"
            class="menu-item"
        >

            <i class="bi bi-globe2"></i>

            <span>
                Status Jaringan
            </span>

        </a>


        <a
            href="chat.php"
            class="menu-item active"
        >

            <i class="bi bi-chat-dots-fill"></i>

            <span>
                Chat CS
            </span>

        </a>


        <p class="menu-title menu-account">
            AKUN
        </p>


        <a
            href="upgrade.php"
            class="menu-item"
        >

            <i class="bi bi-arrow-up-circle-fill"></i>

            <span>
                Upgrade Paket
            </span>

        </a>


        <a
            href="service_request.php"
            class="menu-item"
        >

            <i class="bi bi-plus-circle-fill"></i>

            <span>
                Layanan Tambahan
            </span>

        </a>


        <a
            href="profile.php"
            class="menu-item"
        >

            <i class="bi bi-person-circle"></i>

            <span>
                Profile Saya
            </span>

        </a>


        <a
            href="../logout.php"
            class="menu-item logout"
        >

            <i class="bi bi-box-arrow-right"></i>

            <span>
                Logout
            </span>

        </a>

    </div>


    <!-- USER -->

    <div class="sidebar-user">

        <div class="user-avatar">

            <?= htmlspecialchars($initial) ?>

        </div>


        <div class="user-detail">

            <strong>
                <?= htmlspecialchars($nama) ?>
            </strong>

            <span>
                Customer
            </span>

        </div>

    </div>

</aside>

<main class="main-content">

    <header class="topbar">

        <div>

            <h4>
                Chat Customer Service
            </h4>

            <span>
                Hubungi CS untuk bantuan layanan internet
            </span>

        </div>


        <div class="topbar-right">


            <div class="notification">

                <i class="bi bi-bell"></i>

            </div>


            <a
                href="profile.php"
                class="top-profile"
            >

                <div class="top-avatar">

                    <?= htmlspecialchars($initial) ?>

                </div>


                <div class="top-user">

                    <strong>
                        <?= htmlspecialchars($nama) ?>
                    </strong>

                    <span>
                        Customer
                    </span>

                </div>

            </a>

        </div>

    </header>

    <div class="content chat-page">

        <div class="chat-wrapper">

            <div class="chat-header">


                <div class="cs-profile">

                    <div class="cs-avatar">

                        <i class="bi bi-headset"></i>

                        <span class="online-dot"></span>

                    </div>


                    <div>

                        <h5>
                            Customer Service
                        </h5>

                        <span>
                            <i class="bi bi-circle-fill"></i>
                            Online • Siap membantu
                        </span>

                    </div>

                </div>


                <div class="chat-header-actions">

                    <span class="support-badge">

                        <i class="bi bi-clock"></i>

                        24/7 Support

                    </span>

                </div>

            </div>

            <div
                class="chat-body"
                id="chatBody"
            >


                <!-- WELCOME -->

                <div class="chat-welcome">

                    <div class="welcome-icon">

                        <i class="bi bi-chat-heart"></i>

                    </div>

                    <h3>
                        Halo, <?= htmlspecialchars($nama) ?> 👋
                    </h3>

                    <p>
                        Selamat datang di Customer Service.
                        Pilih topik atau tulis pertanyaan kamu
                        di bawah.
                    </p>

                </div>



                <!-- TOPIC -->

                <div class="topic-selector">

                    <span class="topic-title">
                        Pilih topik bantuan
                    </span>


                    <div class="topic-list">


                        <a
                            href="chat.php?topic=general"
                            class="topic-item
                            <?= $topic === 'general'
                                ? 'active'
                                : ''
                            ?>"
                        >

                            <i class="bi bi-chat-dots"></i>

                            <span>
                                Umum
                            </span>

                        </a>


                        <a
                            href="chat.php?topic=wifi"
                            class="topic-item
                            <?= $topic === 'wifi'
                                ? 'active'
                                : ''
                            ?>"
                        >

                            <i class="bi bi-wifi"></i>

                            <span>
                                WiFi
                            </span>

                        </a>


                        <a
                            href="chat.php?topic=billing"
                            class="topic-item
                            <?= $topic === 'billing'
                                ? 'active'
                                : ''
                            ?>"
                        >

                            <i class="bi bi-credit-card"></i>

                            <span>
                                Tagihan
                            </span>

                        </a>


                        <a
                            href="chat.php?topic=complaint"
                            class="topic-item
                            <?= $topic === 'complaint'
                                ? 'active'
                                : ''
                            ?>"
                        >

                            <i class="bi bi-tools"></i>

                            <span>
                                Gangguan
                            </span>

                        </a>


                        <a
                            href="chat.php?topic=installation"
                            class="topic-item
                            <?= $topic === 'installation'
                                ? 'active'
                                : ''
                            ?>"
                        >

                            <i class="bi bi-calendar-check"></i>

                            <span>
                                Instalasi
                            </span>

                        </a>

                    </div>

                </div>



                <!-- TOPIC INFO -->

                <div class="current-topic">

                    <div class="current-topic-icon">

                        <i class="bi <?= htmlspecialchars($topicIcon) ?>"></i>

                    </div>


                    <div>

                        <span>
                            TOPIK CHAT
                        </span>

                        <strong>
                            <?= htmlspecialchars($topicLabel) ?>
                        </strong>

                    </div>

                </div>



                <!-- MESSAGES -->

                <div class="messages">


                    <?php foreach ($messages as $message): ?>


                        <?php

                        $isCustomer =
                            ($message['sender'] ?? '') === 'customer';

                        ?>


                        <div
                            class="message-row
                            <?= $isCustomer
                                ? 'customer-message'
                                : 'cs-message'
                            ?>"
                        >


                            <?php if (!$isCustomer): ?>

                                <div class="message-avatar">

                                    <i class="bi bi-headset"></i>

                                </div>

                            <?php endif; ?>


                            <div class="message-content">

                                <div class="message-name">

                                    <?= $isCustomer
                                        ? 'Anda'
                                        : 'Customer Service'
                                    ?>

                                </div>


                                <div class="message-bubble">

                                    <?= nl2br(
                                        htmlspecialchars(
                                            $message['message']
                                        )
                                    ) ?>

                                </div>


                                <div class="message-time">

                                    <?= date(
                                        'd M Y, H:i',
                                        strtotime(
                                            $message['created_at']
                                        )
                                    ) ?>

                                    <?php if ($isCustomer): ?>

                                        <i class="bi bi-check2-all"></i>

                                    <?php endif; ?>

                                </div>

                            </div>


                            <?php if ($isCustomer): ?>

                                <div class="message-avatar customer-avatar">

                                    <?= htmlspecialchars($initial) ?>

                                </div>

                            <?php endif; ?>


                        </div>


                    <?php endforeach; ?>


                </div>


                <!-- QUICK QUESTIONS -->

                <div class="quick-section">

                    <span>
                        Pertanyaan cepat
                    </span>


                    <div class="quick-list">

                        <?php foreach (
                            $currentQuickQuestions
                            as $question
                        ): ?>

                            <button
                                type="button"
                                class="quick-question"
                                onclick="useQuickQuestion(this)"
                            >

                                <?= htmlspecialchars($question) ?>

                            </button>

                        <?php endforeach; ?>

                    </div>

                </div>


            </div>

            <div class="chat-footer">


                <?php if ($messageError): ?>

                    <div class="chat-error">

                        <i class="bi bi-exclamation-circle"></i>

                        <?= htmlspecialchars($messageError) ?>

                    </div>

                <?php endif; ?>


                <form
                    method="POST"
                    action="chat.php?topic=<?= urlencode($topic) ?>"
                    class="message-form"
                    id="messageForm"
                >


                    <input
                        type="hidden"
                        name="topic"
                        value="<?= htmlspecialchars($topic) ?>"
                    >


                    <div class="message-input-wrapper">

                        <textarea
                            name="message"
                            id="messageInput"
                            rows="1"
                            placeholder="Tulis pesan untuk Customer Service..."
                            maxlength="2000"
                        ></textarea>


                        <span
                            class="character-count"
                            id="characterCount"
                        >
                            0/2000
                        </span>

                    </div>


                    <button
                        type="submit"
                        class="send-button"
                        title="Kirim pesan"
                    >

                        <i class="bi bi-send-fill"></i>

                    </button>

                </form>


                <div class="chat-footer-info">

                    <span>

                        <i class="bi bi-shield-check"></i>

                        Percakapan aman dan terlindungi

                    </span>


                    <span>
                        Tekan Enter untuk mengirim
                    </span>

                </div>

            </div>


        </div>

        <aside class="chat-info-panel">


            <!-- CS CARD -->

            <div class="info-card">

                <div class="info-card-title">

                    <i class="bi bi-headset"></i>

                    <h5>
                        Customer Service
                    </h5>

                </div>


                <div class="cs-status">

                    <span></span>

                    Semua CS sedang online

                </div>


                <p>
                    Tim kami siap membantu kamu
                    terkait layanan internet.
                </p>

            </div>



            <!-- TOPIC CARD -->

            <div class="info-card">

                <div class="info-card-title">

                    <i class="bi bi-question-circle"></i>

                    <h5>
                        Bantuan
                    </h5>

                </div>


                <div class="help-list">


                    <a href="chat.php?topic=wifi">

                        <i class="bi bi-wifi"></i>

                        <span>
                            Masalah WiFi
                        </span>

                        <i class="bi bi-chevron-right"></i>

                    </a>


                    <a href="chat.php?topic=billing">

                        <i class="bi bi-receipt"></i>

                        <span>
                            Masalah Tagihan
                        </span>

                        <i class="bi bi-chevron-right"></i>

                    </a>


                    <a href="chat.php?topic=complaint">

                        <i class="bi bi-tools"></i>

                        <span>
                            Gangguan Internet
                        </span>

                        <i class="bi bi-chevron-right"></i>

                    </a>


                    <a href="chat.php?topic=installation">

                        <i class="bi bi-calendar-check"></i>

                        <span>
                            Instalasi
                        </span>

                        <i class="bi bi-chevron-right"></i>

                    </a>

                </div>

            </div>



            <!-- CONTACT -->

            <div class="info-card contact-card">

                <div class="info-card-title">

                    <i class="bi bi-info-circle"></i>

                    <h5>
                        Informasi
                    </h5>

                </div>


                <div class="contact-item">

                    <i class="bi bi-clock"></i>

                    <div>

                        <strong>
                            Layanan 24/7
                        </strong>

                        <span>
                            CS tersedia setiap hari
                        </span>

                    </div>

                </div>


                <div class="contact-item">

                    <i class="bi bi-lightning-charge"></i>

                    <div>

                        <strong>
                            Respon Cepat
                        </strong>

                        <span>
                            Kami akan merespons secepatnya
                        </span>

                    </div>

                </div>


                <div class="contact-item">

                    <i class="bi bi-shield-check"></i>

                    <div>

                        <strong>
                            Aman
                        </strong>

                        <span>
                            Data percakapan terlindungi
                        </span>

                    </div>

                </div>

            </div>


        </aside>


    </div>

</main>

<script>

const chatBody =
    document.getElementById('chatBody');

if (chatBody) {

    chatBody.scrollTop =
        chatBody.scrollHeight;

function useQuickQuestion(button) {

    const input =
        document.getElementById('messageInput');

    if (!input) {
        return;
    }


    input.value =
        button.textContent.trim();


    input.focus();


    updateCharacterCount();

}

const messageInput =
    document.getElementById('messageInput');

const characterCount =
    document.getElementById('characterCount');


function updateCharacterCount() {

    if (!messageInput || !characterCount) {
        return;
    }


    characterCount.textContent =
        messageInput.value.length +
        '/2000';

}


if (messageInput) {

    messageInput.addEventListener(
        'input',
        function () {

            updateCharacterCount();


            /*
            | Auto resize textarea
            */

            this.style.height =
                'auto';

            this.style.height =
                Math.min(
                    this.scrollHeight,
                    120
                ) + 'px';

        }
    );

    messageInput.addEventListener(
        'keydown',
        function (event) {

            if (
                event.key === 'Enter' &&
                !event.shiftKey
            ) {

                event.preventDefault();


                const form =
                    document.getElementById(
                        'messageForm'
                    );


                if (
                    this.value.trim() !== '' &&
                    form
                ) {

                    form.submit();

                }

            }

        }
    );

}

updateCharacterCount();

</script>


</body>

</html>
