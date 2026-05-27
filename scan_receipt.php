<?php
require_once 'config.php';

// Auth check
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

// Only accept POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

header('Content-Type: application/json');

// Get image from request
$image_data = null;
$media_type = null;

// Accept either a file upload or base64 JSON body
if (isset($_FILES['receipt_scan']) && $_FILES['receipt_scan']['error'] === UPLOAD_ERR_OK) {
    $file       = $_FILES['receipt_scan'];
    $media_type = $file['type'];
    $image_data = base64_encode(file_get_contents($file['tmp_name']));
} else {
    $body = json_decode(file_get_contents('php://input'), true);
    if (!empty($body['image_data']) && !empty($body['media_type'])) {
        $image_data = $body['image_data'];
        $media_type = $body['media_type'];
    }
}

if (!$image_data || !$media_type) {
    http_response_code(400);
    echo json_encode(['error' => 'No image provided']);
    exit;
}

// Validate media type
$allowed_types = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
if (!in_array($media_type, $allowed_types)) {
    http_response_code(400);
    echo json_encode(['error' => 'Unsupported image type. Use JPG, PNG, or WEBP.']);
    exit;
}

// Fetch user's categories to guide Claude's response
$user_id = $_SESSION['user_id'];
$stmt = $conn->prepare("SELECT id, name FROM categories WHERE user_id = ? OR user_id IS NULL");
$stmt->bind_param('i', $user_id);
$stmt->execute();
$categories = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

$category_list = implode(', ', array_column($categories, 'name'));

// Build the prompt
$prompt = <<<PROMPT
Analyze this receipt image and extract expense details. Respond ONLY with a valid JSON object — no markdown, no explanation, no extra text.

Available expense categories: {$category_list}

Return this exact structure:
{
  "merchant": "business or vendor name, empty string if unclear",
  "date": "date in YYYY-MM-DD format, empty string if not found",
  "amount": "total amount as string with 2 decimal places, no currency symbol, empty string if not found",
  "category_name": "best matching category from the list above, or empty string if none fit",
  "description": "one short sentence describing the purchase, empty string if unclear",
  "payment_method": "one of: cash, credit_card, debit_card, mobile_payment — or empty string if not shown"
}
PROMPT;

// Call Anthropic API
$api_key = ANTHROPIC_API_KEY; // Define this constant in your config.php
$payload = json_encode([
    'model'      => 'claude-sonnet-4-20250514',
    'max_tokens' => 512,
    'messages'   => [[
        'role'    => 'user',
        'content' => [
            [
                'type'   => 'image',
                'source' => [
                    'type'       => 'base64',
                    'media_type' => $media_type,
                    'data'       => $image_data,
                ],
            ],
            [
                'type' => 'text',
                'text' => $prompt,
            ],
        ],
    ]],
]);

$ch = curl_init('https://api.anthropic.com/v1/messages');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => $payload,
    CURLOPT_HTTPHEADER     => [
        'Content-Type: application/json',
        'x-api-key: ' . $api_key,
        'anthropic-version: 2023-06-01',
    ],
    CURLOPT_TIMEOUT        => 30,
]);

$response    = curl_exec($ch);
$http_status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curl_error  = curl_error($ch);
curl_close($ch);

if ($curl_error) {
    http_response_code(502);
    echo json_encode(['error' => 'Failed to reach AI service: ' . $curl_error]);
    exit;
}

$api_response = json_decode($response, true);

if ($http_status !== 200 || empty($api_response['content'][0]['text'])) {
    http_response_code(502);
    $api_error = $api_response['error']['message'] ?? 'Unknown API error';
    echo json_encode(['error' => 'AI service error: ' . $api_error]);
    exit;
}

// Parse Claude's JSON response
$raw_text  = $api_response['content'][0]['text'];
$clean     = trim(preg_replace('/```(?:json)?|```/', '', $raw_text));
$extracted = json_decode($clean, true);

if (!$extracted) {
    http_response_code(500);
    echo json_encode(['error' => 'Could not parse receipt data. Please fill in the form manually.']);
    exit;
}

// Match category name to a real category_id from the DB
$matched_category_id   = null;
$matched_category_name = '';
if (!empty($extracted['category_name'])) {
    $search = strtolower(trim($extracted['category_name']));
    foreach ($categories as $cat) {
        if (strtolower($cat['name']) === $search) {
            $matched_category_id   = $cat['id'];
            $matched_category_name = $cat['name'];
            break;
        }
    }
    // Fallback: partial match
    if (!$matched_category_id) {
        foreach ($categories as $cat) {
            if (str_contains(strtolower($cat['name']), $search) ||
                str_contains($search, strtolower($cat['name']))) {
                $matched_category_id   = $cat['id'];
                $matched_category_name = $cat['name'];
                break;
            }
        }
    }
}

echo json_encode([
    'success'        => true,
    'merchant'       => $extracted['merchant']      ?? '',
    'date'           => $extracted['date']           ?? '',
    'amount'         => $extracted['amount']         ?? '',
    'description'    => $extracted['description']    ?? '',
    'payment_method' => $extracted['payment_method'] ?? '',
    'category_id'    => $matched_category_id,
    'category_name'  => $matched_category_name,
]);
