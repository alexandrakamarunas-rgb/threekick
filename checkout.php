<?php
require __DIR__ . '/config.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { exit; }

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid JSON']);
    exit;
}

$name       = htmlspecialchars($input['name'] ?? 'Produktas', ENT_QUOTES);
$price_eur  = floatval($input['price'] ?? 0);
$size       = htmlspecialchars($input['size'] ?? '', ENT_QUOTES);

if ($price_eur <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid price']);
    exit;
}

$price_cents    = (int)round($price_eur * 100);
$product_label  = $size ? "$name — Dydis: $size" : $name;

$protocol   = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$base_url   = $protocol . '://' . $_SERVER['HTTP_HOST'];
$success_url = $base_url . '/parduotuve.html?success=1';
$cancel_url  = $base_url . '/parduotuve.html';

$post_data = http_build_query([
    'payment_method_types[0]'                          => 'card',
    'line_items[0][price_data][currency]'              => 'eur',
    'line_items[0][price_data][product_data][name]'    => $product_label,
    'line_items[0][price_data][unit_amount]'           => $price_cents,
    'line_items[0][quantity]'                          => 1,
    'mode'                                             => 'payment',
    'success_url'                                      => $success_url,
    'cancel_url'                                       => $cancel_url,
]);

$ch = curl_init('https://api.stripe.com/v1/checkout/sessions');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => $post_data,
    CURLOPT_USERPWD        => $secret_key . ':',
    CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
    CURLOPT_TIMEOUT        => 15,
]);

$response = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curl_err  = curl_error($ch);
curl_close($ch);

if ($curl_err) {
    http_response_code(502);
    echo json_encode(['error' => 'Connection error']);
    exit;
}

$session = json_decode($response, true);

if ($http_code !== 200 || empty($session['url'])) {
    http_response_code(502);
    echo json_encode(['error' => $session['error']['message'] ?? 'Stripe error']);
    exit;
}

echo json_encode(['url' => $session['url']]);
