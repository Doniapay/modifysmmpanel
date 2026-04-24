<?php
if (!defined('ADDFUNDS')) {
    http_response_code(404);
    die();
}

$apiKey = $methodExtras["api_key"];
$apiUrl = "https://api.doniapay.com/v2/order/synchronize/prepare";
$exchangeRate = $methodExtras["exchange_rate"];
$payeeName = $user["name"] ?: "User";
$payeeEmail = $user["email"] ?: "test@test.com";
$paymentURL = site_url("payment/" . $methodCallback);
$orderId = md5(RAND_STRING(5) . time());

$insert = $conn->prepare(
    "INSERT INTO payments SET
    client_id=:client_id,
    payment_amount=:amount,
    payment_method=:method,
    payment_mode=:mode,
    payment_create_date=:date,
    payment_ip=:ip,
    payment_extra=:extra"
);

$insert->execute([
    "client_id" => $user["client_id"],
    "amount" => $paymentAmount,
    "method" => $methodId,
    "mode" => "Automatic",
    "date" => date("Y.m.d H:i:s"),
    "ip" => GetIP(),
    "extra" => $orderId
]);

$rawData = [
    "dn_su" => site_url("addfunds"),
    "dn_cu" => site_url("addfunds"),
    "dn_wu" => $paymentURL, 
    "dn_am" => (string)round($paymentAmount * $exchangeRate, 2),
    "dn_cn" => $payeeName,
    "dn_ce" => $payeeEmail,
    "dn_mt" => json_encode(["order_id" => $orderId]),
    "dn_rt" => "GET"
];

$payload = base64_encode(json_encode($rawData));
$signature = hash_hmac('sha256', $payload, $apiKey);

$curl = curl_init();
curl_setopt_array($curl, [
    CURLOPT_URL => $apiUrl,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 30,
    CURLOPT_CUSTOMREQUEST => "POST",
    CURLOPT_POSTFIELDS => json_encode(['dp_payload' => $payload]),
    CURLOPT_HTTPHEADER => [
        "X-Signature-Key: " . $apiKey,
        "donia-signature: " . $signature,
        "Content-Type: application/json"
    ],
    CURLOPT_SSL_VERIFYPEER => false 
]);

$upresponse = curl_exec($curl);
$err = curl_error($curl);
curl_close($curl);

if ($err) {
    errorExit("cURL Error #:" . $err);
} else {
    $result = json_decode($upresponse, true);
    
    if (isset($result['status']) && ($result['status'] == 'success' || $result['status'] == 1) && !empty($result['payment_url'])) {
        $paymentUrl = $result['payment_url'];
        $redirectForm = '<form method="GET" action="' . $paymentUrl . '" name="doniapayForm"></form>
                         <script type="text/javascript">document.doniapayForm.submit();</script>';
    } else {
        $error_msg = $result['message'] ?? "Payment initialization failed";
        errorExit("Gateway Error: " . $error_msg);
    }
}

$response["success"] = true;
$response["message"] = "Your payment has been initiated and you will now be redirected to the payment gateway.";
$response["content"] = $redirectForm;
?>
