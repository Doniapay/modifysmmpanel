<?php
// Enable error reporting
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

if (!defined('PAYMENT')) {
    http_response_code(404);
    die('Direct access is not allowed.');
}

// Retrieve and validate the required parameters
$invoice_id = $_REQUEST['order_id'] ?? null;
$transactionId = $_REQUEST['transactionId'] ?? null;

if (empty($invoice_id) || empty($transactionId)) {
    errorExit("Missing order_id or transactionId.");
}

$apiKey = trim($methodExtras['api_key']);
$secret_key = trim($methodExtras['secret_key']);
$domain = trim($methodExtras['domain']);

if (isset($_REQUEST['success']) && $_REQUEST['success'] == '1') {
    $curl = curl_init();
    curl_setopt_array(
        $curl,
        array(
            CURLOPT_URL => 'https://pay.doniapay.com/request/payment/verify',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => '',
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 30, 
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => 'POST',
            CURLOPT_POSTFIELDS => http_build_query(['transaction_id' => $transactionId]),
            CURLOPT_HTTPHEADER => [
                'app-key: ' . $apiKey,
                'secret-key: ' . $secret_key,
                'host-name: ' . $domain
            ],
        )
    );

    $response = curl_exec($curl);
    $err = curl_error($curl);
    $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
    curl_close($curl);

    if ($err) {
        errorExit("cURL Error #: " . $err);
    }

    if ($httpCode >= 400) {
        errorExit("HTTP Error: " . $httpCode);
    }

    $response = json_decode($response, true);

    if (!isset($response['status']) || $response['status'] != 1) {
        errorExit("Payment verification failed: " . print_r($response, true));
    }

    $orderId = $invoice_id;
    $paymentDetailsStmt = $conn->prepare("SELECT * FROM payments WHERE payment_extra=:orderId");
    $paymentDetailsStmt->execute(['orderId' => $orderId]);

    if ($paymentDetailsStmt->rowCount() == 0) {
        errorExit("Order ID not found.");
    }

    $paymentDetails = $paymentDetailsStmt->fetch(PDO::FETCH_ASSOC);

    if (countRow([
        'table' => 'payments',
        'where' => [
            'client_id' => $user['client_id'],
            'payment_method' => $methodId,
            'payment_status' => 3,
            'payment_delivery' => 2,
            'payment_extra' => $orderId
        ]
    ]) > 0) {
        errorExit("Order ID is already used.");
    }

    $paidAmount = floatval($paymentDetails["payment_amount"]);

    if ($paymentFee > 0) {
        $fee = ($paidAmount * ($paymentFee / 100));
        $paidAmount -= $fee;
    }

    if ($paymentBonusStartAmount != 0 && $paidAmount > $paymentBonusStartAmount) {
        $bonus = $paidAmount * ($paymentBonus / 100);
        $paidAmount += $bonus;
    }

    try {
        $conn->beginTransaction();

        $update = $conn->prepare('UPDATE payments SET 
            client_balance=:balance,
            payment_status=:status, 
            payment_delivery=:delivery WHERE payment_id=:id');
        $update->execute([
            'balance' => $user["balance"],
            'status' => 3,
            'delivery' => 2,
            'id' => $paymentDetails['payment_id']
        ]);

        $balance = $conn->prepare('UPDATE clients SET balance=:balance WHERE client_id=:id');
        $balance->execute([
            "balance" => $user["balance"] + $paidAmount,
            "id" => $user["client_id"]
        ]);

        $conn->commit();
    } catch (Exception $e) {
        $conn->rollBack();
        errorExit("Database error: " . $e->getMessage());
    }

    header("Location: " . site_url("addfunds"));
    exit();
}

http_response_code(405);
die();

function errorExit($message) {
    echo $message;
    error_log($message); 
    exit();
}

function countRow($params) {
    global $conn;
    $table = $params['table'];
    $where = $params['where'];
    $sql = "SELECT COUNT(*) FROM $table WHERE ";
    $conditions = [];
    foreach ($where as $key => $value) {
        $conditions[] = "$key = :$key";
    }
    $sql .= implode(" AND ", $conditions);
    $stmt = $conn->prepare($sql);
    foreach ($where as $key => $value) {
        $stmt->bindValue(":$key", $value);
    }
    $stmt->execute();
    return $stmt->fetchColumn();
}

function GetIP() {
    return $_SERVER['REMOTE_ADDR'];
}
?>
