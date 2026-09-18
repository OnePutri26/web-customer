<?php

require_once "../config/database.php";
require_once "../config/midtrans.php";

date_default_timezone_set('Asia/Jakarta');

mysqli_report(
    MYSQLI_REPORT_ERROR |
    MYSQLI_REPORT_STRICT
);


/*
|--------------------------------------------------------------------------
| AMBIL DATA WEBHOOK
|--------------------------------------------------------------------------
*/

$rawInput = file_get_contents(
    'php://input'
);

$data = json_decode(
    $rawInput,
    true
);


if (!is_array($data)) {

    http_response_code(400);

    echo "Invalid JSON";

    exit;
}


/*
|--------------------------------------------------------------------------
| DATA MIDTRANS
|--------------------------------------------------------------------------
*/

$orderId = trim(
    (string) (
        $data['order_id'] ?? ''
    )
);

$statusCode = trim(
    (string) (
        $data['status_code'] ?? ''
    )
);

$grossAmount = trim(
    (string) (
        $data['gross_amount'] ?? ''
    )
);

$signatureKey = trim(
    (string) (
        $data['signature_key'] ?? ''
    )
);

$transactionStatus = strtolower(
    trim(
        (string) (
            $data['transaction_status'] ?? ''
        )
    )
);

$fraudStatus = strtolower(
    trim(
        (string) (
            $data['fraud_status'] ?? ''
        )
    )
);


/*
|--------------------------------------------------------------------------
| VALIDASI DATA
|--------------------------------------------------------------------------
*/

if (
    $orderId === '' ||
    $statusCode === '' ||
    $grossAmount === '' ||
    $signatureKey === ''
) {

    http_response_code(400);

    echo "Invalid webhook data";

    exit;
}


/*
|--------------------------------------------------------------------------
| VERIFIKASI SIGNATURE
|--------------------------------------------------------------------------
*/

$serverKey =
    \Midtrans\Config::$serverKey;


$expectedSignature = hash(
    'sha512',
    $orderId .
    $statusCode .
    $grossAmount .
    $serverKey
);


if (
    !hash_equals(
        $expectedSignature,
        $signatureKey
    )
) {

    http_response_code(403);

    echo "Invalid signature";

    exit;
}


/*
|--------------------------------------------------------------------------
| TENTUKAN STATUS
|--------------------------------------------------------------------------
*/

$isPaid = false;

$isFailed = false;

$isCancelled = false;


if (
    $transactionStatus === 'settlement'
) {

    $isPaid = true;

} elseif (
    $transactionStatus === 'capture' &&
    $fraudStatus === 'accept'
) {

    $isPaid = true;

} elseif (
    $transactionStatus === 'cancel'
) {

    $isCancelled = true;

} elseif (
    $transactionStatus === 'deny' ||
    $transactionStatus === 'expire'
) {

    $isFailed = true;
}


/*
|--------------------------------------------------------------------------
| CARI PEMBAYARAN
|--------------------------------------------------------------------------
*/

$stmt = $conn->prepare("
    SELECT
        id,
        customer_id,
        jumlah,
        status
    FROM pembayaran
    WHERE order_id = ?
    LIMIT 1
");

$stmt->bind_param(
    "s",
    $orderId
);

$stmt->execute();

$result = $stmt->get_result();

$payment = $result->fetch_assoc();

$stmt->close();


if (!$payment) {

    http_response_code(404);

    echo "Payment not found";

    exit;
}


$paymentId = (int) $payment['id'];

$customerId = (int) $payment['customer_id'];

$paymentAmount = (float) $payment['jumlah'];

$paymentStatus =
    strtolower(
        trim(
            (string) $payment['status']
        )
    );


/*
|--------------------------------------------------------------------------
| VALIDASI NOMINAL
|--------------------------------------------------------------------------
*/

if (
    abs(
        $paymentAmount -
        (float) $grossAmount
    ) > 0.01
) {

    http_response_code(400);

    echo "Invalid amount";

    exit;
}


/*
|--------------------------------------------------------------------------
| PROSES DATABASE
|--------------------------------------------------------------------------
*/

$conn->begin_transaction();

try {

    /*
    |--------------------------------------------------------------------------
    | PEMBAYARAN BERHASIL
    |--------------------------------------------------------------------------
    */

    if ($isPaid) {

        /*
        |--------------------------------------------------------------------------
        | IDEMPOTENT
        |--------------------------------------------------------------------------
        |
        | Kalau webhook dikirim dua kali,
        | customer tidak diproses dua kali.
        |
        */

        if ($paymentStatus !== 'paid') {

            $stmt = $conn->prepare("
                UPDATE pembayaran
                SET
                    status = 'paid',
                    reference = ?,
                    tanggal_bayar = NOW()
                WHERE id = ?
            ");

            $stmt->bind_param(
                "si",
                $orderId,
                $paymentId
            );

            $stmt->execute();

            $stmt->close();


            /*
            |--------------------------------------------------------------------------
            | AKTIFKAN CUSTOMER
            |--------------------------------------------------------------------------
            */

            $stmt = $conn->prepare("
                UPDATE customers
                SET
                    status_langganan = 3,
                    tgl_mulai = NOW()
                WHERE id = ?
            ");

            $stmt->bind_param(
                "i",
                $customerId
            );

            $stmt->execute();

            $stmt->close();

        }


    /*
    |--------------------------------------------------------------------------
    | CANCELLED
    |--------------------------------------------------------------------------
    */

    } elseif ($isCancelled) {

        $stmt = $conn->prepare("
            UPDATE pembayaran
            SET
                status = 'cancelled',
                reference = ?
            WHERE id = ?
        ");

        $stmt->bind_param(
            "si",
            $orderId,
            $paymentId
        );

        $stmt->execute();

        $stmt->close();


    /*
    |--------------------------------------------------------------------------
    | FAILED
    |--------------------------------------------------------------------------
    */

    } elseif ($isFailed) {

        $stmt = $conn->prepare("
            UPDATE pembayaran
            SET
                status = 'failed',
                reference = ?
            WHERE id = ?
        ");

        $stmt->bind_param(
            "si",
            $orderId,
            $paymentId
        );

        $stmt->execute();

        $stmt->close();
    }


    $conn->commit();


    /*
    |--------------------------------------------------------------------------
    | RESPONSE
    |--------------------------------------------------------------------------
    */

    http_response_code(200);

    echo "OK";


} catch (Throwable $e) {

    $conn->rollback();

    http_response_code(500);

    echo "Webhook processing failed";
}