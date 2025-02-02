<?php
if (!defined('ADDFUNDS')) {
    http_response_code(404);
    die("Direct access is not allowed.");
}

function getSanitizedValue($value, $default = "") {
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8') ?: $default;
}

$exchangeRate = getSanitizedValue($methodExtras["exchange_rate"]);
$payeeName = getSanitizedValue($user["name"], "User");
$payeeEmail = filter_var($user["email"], FILTER_VALIDATE_EMAIL) ? $user["email"] : "test@test.com";
$paymentURL = site_url("payment/" . $methodCallback);
function generateRandomString($length = 5) {
    $characters = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
    $charLength = strlen($characters);
    $randomString = '';
    for ($i = 0; $i < $length; $i++) {
        $randomString .= $characters[rand(0, $charLength - 1)];
    }
    return $randomString;
}

$orderId = md5(generateRandomString(5) . time());


try {
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
        "client_id" => htmlspecialchars($user["client_id"], ENT_QUOTES, 'UTF-8'),
        "amount" => $paymentAmount,
        "method" => $methodId,
        "mode" => "Automatic",
        "date" => date("Y.m.d H:i:s"),
        "ip" => GetIP(),
        "extra" => $orderId
    ]);
} catch (PDOException $e) {
    error_log("Database error: " . $e->getMessage());
    die("A database error occurred. Please try again later.");
}

$curl = curl_init();

$payload = [
    'cus_name' => $payeeName,
    'cus_email' => $payeeEmail,
    'amount' => round($paymentAmount * $exchangeRate, 2),
    'success_url' => $paymentURL . '?order_id=' . $orderId,
    'cancel_url' => site_url(""),
    'callback_url' => site_url("")
];

curl_setopt_array($curl, [
    CURLOPT_URL => 'https://pay.doniapay.com/request/payment/create',
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_ENCODING => '',
    CURLOPT_MAXREDIRS => 10,
    CURLOPT_TIMEOUT => 30, // Use a reasonable timeout
    CURLOPT_FOLLOWLOCATION => false,
    CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
    CURLOPT_CUSTOMREQUEST => 'POST',
    CURLOPT_POSTFIELDS => http_build_query($payload),
    CURLOPT_HTTPHEADER => [
        'app-key: ' . getSanitizedValue($methodExtras["api_key"]),
        'secret-key: ' . getSanitizedValue($methodExtras["secret_key"]),
        'host-name: ' . getSanitizedValue($methodExtras["domain"])
    ],
]);

$response = curl_exec($curl);
$httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);

if (curl_errno($curl) || $httpCode >= 400) {
    error_log("cURL error: " . curl_error($curl) . " HTTP code: " . $httpCode);
    curl_close($curl);
    die("An error occurred while processing the payment. Please try again later.");
}

curl_close($curl);

// Assuming the response contains the script as a string, extract the URL from it
preg_match('/window\.location="([^"]+)"/', $response, $matches);
if (isset($matches[1])) {
    $redirectUrl = htmlspecialchars_decode($matches[1]);
    header("Location: " . $redirectUrl);
} else {
    error_log("Unexpected response format: " . $response);
    die("An error occurred while processing the payment. Please try again later.");
}
?>
