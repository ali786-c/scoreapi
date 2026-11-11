<?php
// 60Score / STUMPS Backend - Optional Firebase Auth Admin REST Helper
// Uses a Firebase Service Account JSON (placed at api/config/firebase_service_account.json)
// to delete users from Firebase Authentication via Identity Toolkit Admin REST API
// with pure PHP (openssl_sign + curl) — zero Composer packages required.

class FirebaseAdminHelper {
    private const SERVICE_ACCOUNT_PATH = __DIR__ . '/../config/firebase_service_account.json';

    public static function isConfigured(): bool {
        return file_exists(self::SERVICE_ACCOUNT_PATH);
    }

    private static function base64UrlEncode(string $data): string {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private static function httpPostJson(string $url, array $payload, array $headers = []): array {
        $json = json_encode($payload);
        $allHeaders = array_merge(['Content-Type: application/json'], $headers);

        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $json);
            curl_setopt($ch, CURLOPT_HTTPHEADER, $allHeaders);
            curl_setopt($ch, CURLOPT_TIMEOUT, 12);
            $body = curl_exec($ch);
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            return ['code' => $code, 'data' => json_decode((string)$body, true) ?: []];
        }

        $ctx = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => implode("\r\n", $allHeaders),
                'content' => $json,
                'timeout' => 12,
                'ignore_errors' => true,
            ],
        ]);
        $body = @file_get_contents($url, false, $ctx);
        $code = 0;
        if (!empty($http_response_header[0]) && preg_match('/\s(\d{3})\s/', $http_response_header[0], $m)) {
            $code = (int)$m[1];
        }
        return ['code' => $code, 'data' => json_decode((string)$body, true) ?: []];
    }

    private static function getAccessToken(array $sa): ?string {
        if (empty($sa['client_email']) || empty($sa['private_key']) || !function_exists('openssl_sign')) {
            return null;
        }
        $now = time();
        $header = self::base64UrlEncode(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
        $claims = self::base64UrlEncode(json_encode([
            'iss' => $sa['client_email'],
            'scope' => 'https://www.googleapis.com/auth/identitytoolkit https://www.googleapis.com/auth/cloud-platform',
            'aud' => 'https://oauth2.googleapis.com/token',
            'iat' => $now,
            'exp' => $now + 3600,
        ]));
        $unsigned = $header . '.' . $claims;
        $signature = '';
        if (!@openssl_sign($unsigned, $signature, $sa['private_key'], OPENSSL_ALGO_SHA256)) {
            return null;
        }
        $jwt = $unsigned . '.' . self::base64UrlEncode($signature);

        $postBody = http_build_query([
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion' => $jwt,
        ]);

        if (function_exists('curl_init')) {
            $ch = curl_init('https://oauth2.googleapis.com/token');
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $postBody);
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/x-www-form-urlencoded']);
            curl_setopt($ch, CURLOPT_TIMEOUT, 12);
            $resp = curl_exec($ch);
            curl_close($ch);
            $decoded = json_decode((string)$resp, true);
            return $decoded['access_token'] ?? null;
        }
        return null;
    }

    /**
     * Deletes a user from Firebase Auth by email if service account is configured.
     * Returns ['deleted' => bool, 'status' => string].
     */
    public static function deleteUserByEmail(string $email): array {
        $email = strtolower(trim($email));
        if (empty($email)) {
            return ['deleted' => false, 'status' => 'no_email'];
        }
        if (!self::isConfigured()) {
            return ['deleted' => false, 'status' => 'not_configured'];
        }

        $sa = json_decode((string)@file_get_contents(self::SERVICE_ACCOUNT_PATH), true);
        if (!is_array($sa) || empty($sa['project_id'])) {
            return ['deleted' => false, 'status' => 'invalid_service_account'];
        }

        $token = self::getAccessToken($sa);
        if (!$token) {
            return ['deleted' => false, 'status' => 'token_error'];
        }

        $projectId = $sa['project_id'];
        $authHeaders = ['Authorization: Bearer ' . $token];

        // 1. Lookup Firebase UID by email
        $lookup = self::httpPostJson(
            "https://identitytoolkit.googleapis.com/v1/projects/{$projectId}/accounts:lookup",
            ['email' => [$email]],
            $authHeaders
        );
        $users = $lookup['data']['users'] ?? [];
        if (empty($users[0]['localId'])) {
            return ['deleted' => false, 'status' => 'not_in_firebase'];
        }

        // 2. Delete Firebase user by localId
        $del = self::httpPostJson(
            "https://identitytoolkit.googleapis.com/v1/projects/{$projectId}/accounts:delete",
            ['localId' => $users[0]['localId']],
            $authHeaders
        );
        if ($del['code'] >= 200 && $del['code'] < 300) {
            return ['deleted' => true, 'status' => 'deleted_from_firebase'];
        }

        return ['deleted' => false, 'status' => 'firebase_delete_failed'];
    }
}
