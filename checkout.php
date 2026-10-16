<?php
require __DIR__ . '/config.php';
 
function finish(string $title, string $message): never {
    echo '<!doctype html><html lang="en"><meta charset="utf-8"><title>' . htmlspecialchars($title) . '</title><body style="font:16px Arial;max-width:800px;margin:40px auto">';
    echo '<h1>' . htmlspecialchars($title) . '</h1><p>' . htmlspecialchars($message) . '</p><p><a href="index.php">Store</a> | <a href="cart.php">Cart</a> | <a href="orders.php">Orders</a></p></body></html>';
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: cart.php'); exit; }
 
$customer = trim((string)($_POST['customer_name'] ?? ''));
$method = (string)($_POST['payment_method'] ?? 'CARD');
if ($customer === '') finish('Missing information', 'Enter a customer name.');
if (!in_array($method, ['CARD','EWALLET','BANK'], true)) $method = 'CARD';
 
$cart = $_SESSION['cart'] ?? [];
if (!$cart) finish('Empty cart', 'Add an item before checkout.');
 
$pdo = cart_db();
$ids = array_map('intval', array_keys($cart));
$marks = implode(',', array_fill(0, count($ids), '?'));
$stmt = $pdo->prepare("SELECT id,name,price,stock FROM products WHERE id IN ($marks)");
$stmt->execute($ids);
$products = $stmt->fetchAll();
$total = 0.0; $validItems = [];
foreach ($products as $p) {
    $qty = min((int)$cart[$p['id']], (int)$p['stock']);
    if ($qty > 0) {
        $total += $qty * (float)$p['price'];
        $validItems[] = ['product'=>$p,'qty'=>$qty];
    }
}
if (!$validItems || $total <= 0) finish('Checkout error', 'No valid items were found.');
 

$pdo->beginTransaction();
try {
    $stmt = $pdo->prepare("INSERT INTO orders (customer_name,total_amount,status) VALUES (?,?,'PENDING_PAYMENT')");
    $stmt->execute([$customer, number_format($total,2,'.','')]);
    $orderId = (int)$pdo->lastInsertId();
    $itemStmt = $pdo->prepare('INSERT INTO order_items (order_id,product_id,product_name,quantity,unit_price) VALUES (?,?,?,?,?)');
    foreach ($validItems as $i) {
        $itemStmt->execute([$orderId,$i['product']['id'],$i['product']['name'],$i['qty'],$i['product']['price']]);
    }
    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    finish('Order error', 'Could not save the order.');
}
 
 
 

 
// Gateway endpoint (see payment-gateway/README-API.md).
$apiUrl = 'http://localhost:9999/payment-gateway/api/payments.php.';
 
// Simulator-only control (classroom testing). Optional; if absent the gateway default is used.
$simulatedOutcome = strtoupper(trim((string)($_POST['outcome'] ?? $_GET['outcome'] ?? '')));
if (!in_array($simulatedOutcome, ['SUCCESS', 'FAILED', 'PENDING'], true)) $simulatedOutcome = '';
 

$idempotencyKey = 'cart-order-' . $orderId;
$payload = [
    'order_id'        => $orderId,
    'amount'          => (float)number_format($total, 2, '.', ''),
    'customer_name'   => $customer,
    'payment_method'  => $method,
    'idempotency_key' => $idempotencyKey,
];
if ($simulatedOutcome !== '') $payload['outcome'] = $simulatedOutcome;
 

$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL            => $apiUrl,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => json_encode($payload),
    CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Accept: application/json',
                               'Idempotency-Key: ' . $idempotencyKey],
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_CONNECTTIMEOUT => 5,
    CURLOPT_TIMEOUT        => 15,
]);
$raw      = curl_exec($ch);
$curlErrNo = curl_errno($ch);
$curlErr  = curl_error($ch);
$httpCode = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
curl_close($ch);
 

$newStatus = 'INTEGRATION_ERROR';
$txnId     = null;
$title     = 'Payment status could not be confirmed';
$message   = 'We could not confirm your payment. Please check your order status in Orders before trying to pay again.';
 
if ($raw === false || $curlErrNo !== 0) {
    error_log("Gateway cURL error [$curlErrNo] for order $orderId: $curlErr");
} elseif ($httpCode < 200 || $httpCode >= 300) {
    error_log("Gateway HTTP $httpCode for order $orderId");
} else {
    $res = json_decode((string)$raw, true);
    $validShape = is_array($res)
        && ($res['success'] ?? false) === true
        && isset($res['status']) && is_string($res['status'])
        && isset($res['order_id']) && (int)$res['order_id'] === $orderId          // must match OUR order
        && isset($res['amount']) && is_numeric($res['amount'])
        && abs((float)$res['amount'] - (float)$payload['amount']) < 0.005;        // must match OUR amount
 
    if (!$validShape) {
        error_log("Gateway invalid/mismatched response for order $orderId: " . substr((string)$raw, 0, 300));
    } else {
        $gwStatus = strtoupper($res['status']);
        $txnId = (isset($res['transaction_id']) && is_string($res['transaction_id']) && $res['transaction_id'] !== '')
            ? substr($res['transaction_id'], 0, 80) : null;
 
        if ($gwStatus === 'SUCCESS') {
            $newStatus = 'PAID';
            $title = 'Payment successful';
            $message = 'Thank you, ' . $customer . '! Order #' . $orderId . ' is paid.' . ($txnId ? ' Transaction: ' . $txnId : '');
        } elseif ($gwStatus === 'FAILED') {
            $newStatus = 'PAYMENT_FAILED';
            $title = 'Payment failed';
            $message = 'Your payment for order #' . $orderId . ' was declined.' . ($txnId ? ' Transaction: ' . $txnId : '');
        } elseif ($gwStatus === 'PENDING') {
            $newStatus = 'PAYMENT_PENDING';
            $title = 'Payment pending';
            $message = 'Order #' . $orderId . ' is awaiting payment confirmation.' . ($txnId ? ' Transaction: ' . $txnId : '');
        } else {
            error_log("Gateway unrecognized status '$gwStatus' for order $orderId");
            $txnId = null; 
        }
    }
}
 

try {
    $upd = $pdo->prepare('UPDATE orders SET status = ?, gateway_transaction_id = ? WHERE id = ?');
    $upd->execute([$newStatus, $txnId, $orderId]);
} catch (Throwable $e) {
    error_log("Could not update order $orderId: " . $e->getMessage());
    finish('Payment status could not be saved', 'Order #' . $orderId . ' needs review. Check Orders before paying again.');
}
 

finish($title, $message);



/*
 * STUDENT TASK: Integrate the shopping cart with the
 * independent payment gateway.
 *
 * Requirements:
 * 1. Prepare the payment request payload.
 * 2. Send an HTTP POST request with JSON using PHP cURL.
 * 3. Handle the gateway's HTTP response.
 * 4. Validate the response and match the order ID.
 * 5. Handle SUCCESS, FAILED, and PENDING outcomes.
 * 6. Handle timeouts and invalid responses safely.
 * 7. Save the gateway transaction ID and update the
 *    order status in the shopping cart database.
 * 8. Prevent duplicate payment requests using an
 *    idempotency key.
 *
 * Read payment-gateway/README-API.md for the API contract.
 */

// TODO: Implement the system integration here.

// TODO: Display the appropriate checkout result.
