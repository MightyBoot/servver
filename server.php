<?php
/**
 * PayNow EcoCash USD - Server-side handler
 * No composer / SDK required. Raw cURL + SHA512.
 *
 * DEPLOY TO RENDER:
 *   1. Push this file (alone, or with a Dockerfile) to a GitHub repo
 *   2. Render → New → Web Service → connect your repo
 *   3. Runtime: PHP 8.x  OR use Docker (Dockerfile below)
 *   4. Build command:  echo "ready"
 *   5. Start command:  php -S 0.0.0.0:10000 -t .
 *   6. Your live URL:  https://fartai-php.onrender.com
 *   7. Update PAYNOW_RESULT_URL and PAYNOW_RETURN_URL below to your URLs
 *
 * DOCKERFILE (create Dockerfile in same repo if Render doesn't auto-detect PHP):
 *   FROM php:8.2-cli
 *   WORKDIR /app
 *   COPY . .
 *   EXPOSE 10000
 *   CMD ["php", "-S", "0.0.0.0:10000", "-t", "."]
 */

// ─── YOUR CREDENTIALS (fill these in) ────────────────────────────────────────
define('PAYNOW_ID',         '23587');
define('PAYNOW_KEY',        'xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx');
define('PAYNOW_RESULT_URL', 'https://YOUR-RENDER-URL.onrender.com/server.php?action=result');
define('PAYNOW_RETURN_URL', 'https://YOUR-NETLIFY-SITE.netlify.app/index.html');
define('PAYNOW_AUTH_EMAIL', '28dollarboot@gmail.com');

// ─── SUPABASE (for referral discount check) ───────────────────────────────────
define('SUPABASE_URL',      'https://cjwoulzegfjunhurfzjb.supabase.co');
define('SUPABASE_SERVICE_KEY', 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJpc3MiOiJzdXBhYmFzZSIsInJlZiI6ImNqd291bHplZ2ZqdW5odXJmempiIiwicm9sZSI6InNlcnZpY2Vfcm9sZSIsImlhdCI6MTc3NTcyNjMyNywiZXhwIjoyMDkxMzAyMzI3fQ.9r-evb7ihLTrLLEF7Z3NCXJ_IYskOc5YXvMIKn80G6k'); // Settings → API → service_role key
// ─────────────────────────────────────────────────────────────────────────────

define('PRICE_FULL',       '1.25');
define('PRICE_REFERRAL',   '1.00');  // $0.25 discount for referring

define('PAYNOW_INITIATE_URL', 'https://www.paynow.co.zw/interface/remotetransaction');
define('PAYNOW_STATUS_URL',   'https://www.paynow.co.zw/Interface/CheckPayment/');

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

$action = $_GET['action'] ?? '';

match ($action) {
    'initiate'      => handleInitiate(),
    'check'         => handleCheck(),
    'result'        => handleResult(),
    'get_price'     => handleGetPrice(),
    default         => jsonOut(['error' => 'Unknown action'], 400),
};


// ─── GET PRICE (checks referral discount) ────────────────────────────────────
function handleGetPrice(): void {
    $userId = trim($_GET['user_id'] ?? '');
    if (empty($userId)) {
        jsonOut(['price' => PRICE_FULL, 'has_referral' => false]);
        return;
    }
    $hasReferral = checkUserHasReferral($userId);
    jsonOut([
        'price'        => $hasReferral ? PRICE_REFERRAL : PRICE_FULL,
        'has_referral' => $hasReferral,
    ]);
}

// ─── INITIATE ECOCASH PAYMENT ─────────────────────────────────────────────────
function handleInitiate(): void {
    $phone  = trim($_POST['phone']  ?? '');
    $email  = trim($_POST['email']  ?? '');
    $userId = trim($_POST['user_id'] ?? '');
    $ref    = trim($_POST['ref']    ?? uniqid('fartai_'));

    // Determine price based on referral
    $hasReferral = !empty($userId) && checkUserHasReferral($userId);
    $amount = $hasReferral ? PRICE_REFERRAL : PRICE_FULL;

    // Sanitise phone: strip spaces / +263 prefix, normalise to 07XXXXXXXX
    $phone = preg_replace('/\s+/', '', $phone);
    $phone = preg_replace('/^\+?263/', '0', $phone);

    if (!preg_match('/^07\d{8}$/', $phone))    { jsonOut(['success' => false, 'error' => 'Invalid EcoCash number. Use format 07XXXXXXXX.']); return; }
    if (empty($email))                          { jsonOut(['success' => false, 'error' => 'Email is required.']);                              return; }

    $amount = number_format((float)$amount, 2, '.', '');

    // Build POST fields (ORDER MATTERS for hash)
    $fields = [
        'id'             => PAYNOW_ID,
        'reference'      => $ref,
        'amount'         => $amount,
        'additionalinfo' => 'FartAI Unlimited Access - ' . $ref,
        'returnurl'      => PAYNOW_RETURN_URL,
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
        jsonOut(['success' => false, 'error' => $parsed['error'] ?? 'PayNow returned an error. Check credentials and EcoCash integration setting.']);
        return;
    }

    if (!isset($parsed['pollurl'])) {
        jsonOut(['success' => false, 'error' => 'No poll URL in response. Raw: ' . substr($response, 0, 300)]);
        return;
    }

    jsonOut([
        'success'      => true,
        'pollUrl'      => $parsed['pollurl'],
        'amount'       => $amount,
        'has_referral' => $hasReferral,
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

    // Validate URL is a PayNow domain (security check)
    $host = parse_url($pollUrl, PHP_URL_HOST);
    if ($host === false || !str_contains($host, 'paynow.co.zw')) {
        jsonOut(['status' => 'error', 'error' => 'Invalid poll URL.']);
        return;
    }

    $response = curlGet($pollUrl);

    if ($response === false) {
        jsonOut(['status' => 'pending']); // Network blip, keep polling
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


// ─── RESULT URL WEBHOOK (PayNow calls this) ───────────────────────────────────
function handleResult(): void {
    $data = $_POST;

    // Validate hash from PayNow
    if (!empty($data['hash'])) {
        $receivedHash = $data['hash'];
        unset($data['hash']);
        $expectedHash = validateInboundHash($data);

        if (!hash_equals($expectedHash, strtoupper($receivedHash))) {
            http_response_code(400);
            echo 'Hash mismatch';
            exit;
        }
    }

    // Log the result (write to a file for debugging)
    $logLine = date('Y-m-d H:i:s') . ' | ' . http_build_query($_POST) . PHP_EOL;
    file_put_contents(__DIR__ . '/paynow_results.log', $logLine, FILE_APPEND | LOCK_EX);

    // You can add DB writes, email notifications, order fulfillment here
    http_response_code(200);
    echo 'OK';
    exit;
}


// ─── HELPERS ──────────────────────────────────────────────────────────────────

/**
 * Check if a user has at least 1 referral click in Supabase.
 * Uses the Supabase REST API with the service role key (bypasses RLS).
 */
function checkUserHasReferral(string $userId): bool {
    if (SUPABASE_SERVICE_KEY === 'YOUR_SUPABASE_SERVICE_ROLE_KEY') return false; // not configured
    $url = SUPABASE_URL . '/rest/v1/referral_clicks?referrer_id=eq.' . urlencode($userId) . '&select=id&limit=1';
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 8,
        CURLOPT_HTTPHEADER     => [
            'apikey: ' . SUPABASE_SERVICE_KEY,
            'Authorization: Bearer ' . SUPABASE_SERVICE_KEY,
            'Content-Type: application/json',
        ],
    ]);
    $res = curl_exec($ch);
    curl_close($ch);
    if (!$res) return false;
    $data = json_decode($res, true);
    return is_array($data) && count($data) > 0;
}

/**
 * Generate SHA512 hash for outbound PayNow requests.
 * Concat all VALUES in field order, append integration key, SHA512, uppercase.
 */
function generateHash(array $fields): string {
    $str = implode('', array_values($fields)) . PAYNOW_KEY;
    return strtoupper(hash('sha512', $str));
}

/**
 * Validate hash on inbound PayNow messages (result URL callbacks).
 */
function validateInboundHash(array $data): string {
    $str = implode('', array_values($data)) . PAYNOW_KEY;
    return strtoupper(hash('sha512', $str));
}

/**
 * Parse PayNow's URL-encoded response string into an associative array.
 */
function parsePaynowResponse(string $response): array {
    $result = [];
    parse_str($response, $result);
    // Keys are lowercase in PayNow responses
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