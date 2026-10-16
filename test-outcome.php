<?php
require __DIR__ . '/config.php';
?>
<!doctype html><meta charset="utf-8"><title>Test checkout</title>
<body style="font:16px Arial;max-width:600px;margin:40px auto">
<h1>Test checkout (simulator)</h1>
<p>Cart items: <?= array_sum($_SESSION['cart'] ?? []) ?> &mdash; <a href="index.php">add items</a></p>
<form action="checkout.php" method="post">
  <p><label>Name <input name="customer_name" value="Student Test" required></label></p>
  <p><label>Method <select name="payment_method"><option>CARD</option><option>EWALLET</option><option>BANK</option></select></label></p>
  <p><label>Simulated outcome <select name="outcome"><option>SUCCESS</option><option>FAILED</option><option>PENDING</option></select></label></p>
  <button>Place order and pay</button>
</form></body>