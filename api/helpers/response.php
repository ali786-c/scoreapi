<?php
// Standard JSON API Response Helper for 60Score

class Response {
    public static function json($data, int $statusCode = 200): void {
        http_response_code($statusCode);
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    public static function success($data = null, string $message = 'Operation successful', int $statusCode = 200): void {
        self::json([
            'status' => 'success',
            'message' => $message,
            'data' => $data
        ], $statusCode);
    }

    public static function error(string $message, int $statusCode = 400, $errors = null): void {
        $payload = [
            'status' => 'error',
            'message' => $message
        ];
        if ($errors !== null) {
            $payload['errors'] = $errors;
        }
        self::json($payload, $statusCode);
    }

    public static function unauthorized(string $message = 'Authentication required'): void {
        self::error($message, 401);
    }

    public static function forbidden(string $message = 'Access denied. You do not have permission to perform this action.'): void {
        self::error($message, 403);
    }

    public static function notFound(string $message = 'Resource not found'): void {
        self::error($message, 404);
    }
}
