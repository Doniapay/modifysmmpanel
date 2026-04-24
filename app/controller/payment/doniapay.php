<?php
if (!defined('PAYMENT')) {
    http_response_code(404);
    die();
}

/**
 * Doniapay - Payment Verification (Callback/Webhook)
 */

// Doniapay typically sends 'ids' in the URL on return
$transaction_id = $_REQUEST['ids'] ?? $_REQUEST['transactionId'] ?? '';

if (empty($transaction_id)) {
    $up_response = file_get_contents('php://input');
    $up_response_decode = json_decode($up_response, true);
    $transaction_id = $up_response_decode['transaction_id'] ?? $up_response_decode['ids'] ?? '';
}

if (empty($transaction_id)) {
    errorExit("Direct access is not allowed.");
}

$apiKey = trim($methodExtras['api_key']);
$apiUrl = "https://api.doniapay.com/v2/order/synchronize/confirm";

$postData = [
    'transaction_id' => $transaction_id
];

$curl = curl_init();
curl_setopt_array($curl, [
    CURLOPT_URL => $apiUrl,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_ENCODING => "",
    CURLOPT_MAXREDIRS => 10,
    CURLOPT_TIMEOUT => 30,
    CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
    CURLOPT_CUSTOMREQUEST => "POST",
    CURLOPT_POSTFIELDS => json_encode($postData),
    CURLOPT_HTTPHEADER => [
        "X-Signature-Key: " . $apiKey,
        "Content-Type: application/json"
    ],
    CURLOPT_SSL_VERIFYPEER => false
]);

$response = curl_exec($curl);
$err = curl_error($curl);
curl_close($curl);

if ($err) {
    errorExit("cURL Error #:" . $err);
}

if (empty($response)) {
    errorExit("Invalid Response From Payment API.");
}

$data = json_decode($response, true);

// New API returns 'status' => 'Paid' on success
if (isset($data['status']) && $data['status'] == 'Paid') {
    
    // Extract metadata where order_id was stored
    // The new API returns the custom data in 'dn_mt'
    $meta = json_decode($data['dn_mt'] ?? $data['metadata'] ?? '{}', true);
    $orderId = $meta['order_id'] ?? '';

    if (empty($orderId)) {
        errorExit("Order ID not found in metadata.");
    }

    $paymentDetails = $conn->prepare("SELECT * FROM payments WHERE payment_extra=:orderId");
    $paymentDetails->execute(["orderId" => $orderId]);

    if ($paymentDetails->rowCount()) {
        $paymentDetails = $paymentDetails->fetch(PDO::FETCH_ASSOC);

        $row = $conn->prepare("SELECT * FROM clients WHERE client_id=:id");
        $row->execute(array("id" => $paymentDetails["client_id"]));
        $user = $row->fetch(PDO::FETCH_ASSOC);

        // Session handling
        $_SESSION["msmbilisim_userlogin"] = 1;
        $_SESSION["msmbilisim_userid"]    = $user["client_id"];
        $_SESSION["msmbilisim_userpass"]  = $user["password"];

        if (!countRow([
            'table' => 'payments',
            'where' => [
                'client_id' => $user['client_id'],
                'payment_method' => $methodId,
                'payment_status' => 3,
                'payment_delivery' => 2,
                'payment_extra' => $orderId
            ]
        ])) {
            $paidAmount = floatval($paymentDetails["payment_amount"]);
            
            if ($paymentFee > 0) {
                $fee = ($paidAmount * ($paymentFee / 100));
                $paidAmount -= $fee;
            }
            if ($paymentBonusStartAmount != 0 && $paidAmount > $paymentBonusStartAmount) {
                $bonus = $paidAmount * ($paymentBonus / 100);
                $paidAmount += $bonus;
            }

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

            header("Location: " . site_url("addfunds"));
            exit();
        } else {
            errorExit("Order ID is already used.");
        }
    } else {
        errorExit("Order ID not found in database.");
    }
} else {
    errorExit($data['message'] ?? "Payment not completed or failed.");
}

http_response_code(405);
die();
