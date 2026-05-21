<?php
/**
 * Soxyc PayNow EcoCash – Server-side handler
 * Adapted from the original FartAI payment server.
 *
 * DEPLOY TO RENDER (same as before):
 *   1. Push this file to your GitHub repo
 *   2. Render → Web Service → connect repo
 *   3. Runtime: PHP 8.x
 *   4. Build command:  echo "ready"
 *   5. Start command:  php -S 0.0.0.0:10000 -t .
 *   6. Live URL:       https://servver.onrender.com  (update below if it changes)
 *
 * WHAT CHANGED FROM THE ORIGINAL:
 *   - Amount is now read from POST data (dynamic cart total)
 *   - Minimum amount guard ($0.50) to prevent test / zero payments
 *   - Referral discount logic removed (not used by Soxyc)
 *   - PAYNOW_RETURN_URL points to receipt.html
 *   - additionalinfo uses the Soxyc order reference
 */

// ─── CREDENTIALS ──────────────────────────────────────────────────────────────
define('PAYNOW_ID',         '23587');
define('PAYNOW_KEY',        'c70ad8fc-ed89-4473-b3cb-300d94cad56a');
define('PAYNOW_RESULT_URL', 'https://servver.onrender.com/server.php?action=result');
define('PAYNOW_RETURN_URL', 'https://soxyc.netlify.app/receipt.html');  // ← update to your live URL
define('PAYNOW_AUTH_EMAIL', '28dollarboot@gmail.com');

define('PAYNOW_MIN_AMOUNT', '0.50');   // refuse anything below this (USD)

define('PAYNOW_INITIATE_URL', 'https://www.paynow.co.zw/interface/remotetransaction');
define('PAYNOW_STATUS_URL',   'https://www.paynow.co.zw/Interface/CheckPayment/');
// ──────────────────────────────────────────────────────────────────────────────

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

$action = $_GET['action'] ?? '';

match ($action) {
    'initiate' => handleInitiate(),
    'check'    => handleCheck(),
    'result'   => handleResult(),
    default    => jsonOut(['error' => 'Unknown action'], 400),
};


// ─── INITIATE ECOCASH PAYMENT ─────────────────────────────────────────────────
function handleInitiate(): void {
    $phone  = trim($_POST['phone']  ?? '');
    $email  = trim($_POST['email']  ?? '');
    $ref    = trim($_POST['ref']    ?? uniqid('soxyc_'));
    $amount = trim($_POST['amount'] ?? '');

    // ── Validate amount ──────────────────────────────────────
    $amount = (float) $amount;
    if ($amount < (float) PAYNOW_MIN_AMOUNT) {
        jsonOut(['success' => false, 'error' => 'Order total is too low to process.']);
        return;
    }
    $amount = number_format($amount, 2, '.', '');

    // ── Validate phone ───────────────────────────────────────
    // Strip spaces and normalise +263 / 263 prefix → 07XXXXXXXX
    $phone = preg_replace('/\s+/', '', $phone);
    $phone = preg_replace('/^\+?263/', '0', $phone);
    if (!preg_match('/^07\d{8}$/', $phone)) {
        jsonOut(['success' => false, 'error' => 'Invalid EcoCash number. Use format 07XXXXXXXX.']);
        return;
    }

    // ── Validate email ───────────────────────────────────────
    if (empty($email)) {
        // Fall back to the admin confirmation email so PayNow always has one
        $email = PAYNOW_AUTH_EMAIL;
    }

    // ── Build POST fields (ORDER MATTERS for hash) ───────────
    $fields = [
        'id'             => PAYNOW_ID,
        'reference'      => $ref,
        'amount'         => $amount,
        'additionalinfo' => 'Soxyc Order ' . $ref,
        'returnurl'      => PAYNOW_RETURN_URL . '?order=' . urlencode($ref),
        'resulturl'      => PAYNOW_RESULT_URL,
        'authemail'      => PAYNOW_AUTH_EMAIL,
        'status'         => 'Message',
        'method'         => 'ecocash',
        'phone'          => $phone,
        'email'          => $email,
    ];

    $fields['hash'] = generateHash($fields);

    $response = curlPost(PAYNOW_INITIATE_URL, $fields);

    if ($response === false) {
        jsonOut(['success' => false, 'error' => 'Network error: could not reach PayNow servers.']);
        return;
    }

    $parsed = parsePaynowResponse($response);

    if (strtolower($parsed['status'] ?? '') === 'error') {
        jsonOut(['success' => false, 'error' => $parsed['error'] ?? 'PayNow returned an error. Check credentials and EcoCash integration settings.']);
        return;
    }

    if (!isset($parsed['pollurl'])) {
        jsonOut(['success' => false, 'error' => 'No poll URL received. Raw: ' . substr($response, 0, 300)]);
        return;
    }

    jsonOut([
        'success'      => true,
        'pollUrl'      => $parsed['pollurl'],
        'amount'       => $amount,
        'instructions' => $parsed['instructions'] ?? 'Check your phone and enter your EcoCash PIN to confirm payment.',
        'reference'    => $ref,
    ]);
}


// ─── POLL TRANSACTION STATUS ──────────────────────────────────────────────────
function handleCheck(): void {
    $pollUrl = $_GET['pollurl'] ?? '';

    if (empty($pollUrl)) {
        jsonOut(['status' => 'error', 'error' => 'No poll URL provided.']);
        return;
    }

    // Security: only allow real PayNow domains
    $host = parse_url($pollUrl, PHP_URL_HOST);
    if ($host === false || !str_contains($host, 'paynow.co.zw')) {
        jsonOut(['status' => 'error', 'error' => 'Invalid poll URL.']);
        return;
    }

    $response = curlGet($pollUrl);
    if ($response === false) {
        jsonOut(['status' => 'pending']);  // network blip, keep polling
        return;
    }

    $parsed = parsePaynowResponse($response);
    $status = strtolower($parsed['status'] ?? '');

    if (in_array($status, ['paid', 'awaiting delivery'])) {
        jsonOut([
            'status'    => 'paid',
            'amount'    => $parsed['amount']    ?? '',
            'reference' => $parsed['reference'] ?? '',
        ]);
    } elseif (in_array($status, ['cancelled', 'failed', 'disputed', 'refunded'])) {
        jsonOut(['status' => 'failed', 'error' => 'Transaction ' . $status . '.']);
    } else {
        jsonOut(['status' => 'pending', 'paynowStatus' => $status]);
    }
}


// ─── RESULT URL WEBHOOK (PayNow calls this server-side) ───────────────────────
function handleResult(): void {
    $data = $_POST;

    // Validate inbound hash
    if (!empty($data['hash'])) {
        $received = $data['hash'];
        unset($data['hash']);
        $expected = validateInboundHash($data);
        if (!hash_equals($expected, strtoupper($received))) {
            http_response_code(400);
            echo 'Hash mismatch';
            exit;
        }
    }

    // Append to log file for debugging
    $line = date('Y-m-d H:i:s') . ' | ' . http_build_query($_POST) . PHP_EOL;
    file_put_contents(__DIR__ . '/paynow_results.log', $line, FILE_APPEND | LOCK_EX);

    http_response_code(200);
    echo 'OK';
    exit;
}


// ─── HELPERS ──────────────────────────────────────────────────────────────────

function generateHash(array $fields): string {
    $str = implode('', array_values($fields)) . PAYNOW_KEY;
    return strtoupper(hash('sha512', $str));
}

function validateInboundHash(array $data): string {
    $str = implode('', array_values($data)) . PAYNOW_KEY;
    return strtoupper(hash('sha512', $str));
}

function parsePaynowResponse(string $response): array {
    $result = [];
    parse_str($response, $result);
    return array_change_key_case($result, CASE_LOWER);
}

function curlPost(string $url, array $fields): string|false {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query($fields),
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
    ]);
    $res = curl_exec($ch);
    curl_close($ch);
    return $res;
}

function curlGet(string $url): string|false {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $res = curl_exec($ch);
    curl_close($ch);
    return $res;
}

function jsonOut(array $data, int $code = 200): void {
    http_response_code($code);
    echo json_encode($data);
    exit;
}