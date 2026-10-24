<?php
declare(strict_types=1);

session_start();

header('Content-Type: text/plain; charset=utf-8');

// ---------- Settings ----------
// getenv() takes the NAME of an environment variable (e.g. TEXTBEE_API_KEY), never the value.
// If that variable is not set, the value after "?:" is used.
$baseUrl = getenv('TEXTBEE_BASE_URL') ?: 'https://api.textbee.dev/api/v1';
$apiKey  = trim(getenv('TEXTBEE_API_KEY') ?: 'txb_mScxpFxpvcbDKmrKANt0Z46wDWutraRv');

// Optional: copy your Device ID from the TextBee dashboard (Devices section).
// Leave empty ('') to let TextBee pick your enabled device automatically.
$deviceId = trim(getenv('TEXTBEE_DEVICE_ID') ?: '');

/**
 * Send SMS using TextBee API.
 */
function sendSms(string $baseUrl, string $apiKey, array $recipients, string $message, string $deviceId = ''): array
{
    if ($apiKey === '' || $apiKey === 'PASTE_YOUR_NEW_API_KEY_HERE') {
        throw new RuntimeException('API key missing. Paste your key in $apiKey or set TEXTBEE_API_KEY.');
    }

    if (empty($recipients)) {
        throw new InvalidArgumentException('At least one recipient is required.');
    }

    if (trim($message) === '') {
        throw new InvalidArgumentException('Message cannot be empty.');
    }

    foreach ($recipients as $recipient) {
        if (!is_string($recipient) || !preg_match('/^\+[1-9]\d{1,14}$/', $recipient)) {
            throw new InvalidArgumentException('Invalid recipient number: ' . (string) $recipient);
        }
    }

    // Remove "/gateway/send-sms" if it was accidentally included in the base URL
    $baseUrl = preg_replace('#/gateway/send-sms/?$#', '', rtrim($baseUrl, '/'));
    $url = $baseUrl . '/gateway/send-sms';

    $data = [
        'recipients' => array_values($recipients),
        'message'    => $message,
    ];

    if ($deviceId !== '') {
        $data['deviceId'] = $deviceId;
    }

    $payload = json_encode($data, JSON_THROW_ON_ERROR);

    $ch = curl_init($url);
    if ($ch === false) {
        throw new RuntimeException('Could not initialize cURL.');
    }

    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_HTTPHEADER     => [
            'x-api-key: ' . $apiKey,
            'Content-Type: application/json',
            'Accept: application/json',
        ],
        CURLOPT_POSTFIELDS     => $payload,
    ]);

    $body = curl_exec($ch);

    if ($body === false) {
        $error = curl_error($ch);
        curl_close($ch);
        throw new RuntimeException('cURL request failed: ' . $error);
    }

    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    try {
        $response = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $e) {
        throw new RuntimeException("Invalid JSON response (HTTP $status): $body");
    }

    // 200 and 201 are both success responses
    if ($status < 200 || $status >= 300) {
        $details = $response['message'] ?? $response['error'] ?? $response['code'] ?? $body;

        throw new RuntimeException(
            "TextBee API error (HTTP $status): " .
            (is_string($details) ? $details : json_encode($details))
        );
    }

    if (!isset($response['data']) || !is_array($response['data'])) {
        throw new RuntimeException('Unexpected API response: ' . $body);
    }

    return $response['data'];
}

// ---------- Test SMS ----------
$recipients = ['+923254216167'];

try {
    $result = sendSms($baseUrl, $apiKey, $recipients, 'Hello from TextBee', $deviceId);

    echo "SMS request accepted successfully.\n";
    echo 'Batch ID: ' . ($result['smsBatchId'] ?? 'Not provided') . "\n";
    echo 'Recipients: ' . ($result['recipientCount'] ?? count($recipients)) . "\n";
    echo "Note: Accepted does not necessarily mean delivered.\n";

} catch (Throwable $e) {
    http_response_code(500);
    echo 'Error: ' . $e->getMessage() . "\n";
}
?>