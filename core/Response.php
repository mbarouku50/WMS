<?php
/**
 * WMS - HTTP responses.
 *
 * Gives the API folder one consistent JSON envelope and gives HTML pages a
 * couple of small redirect helpers.
 */
class Response
{
    /** Sends a JSON body and stops the request. */
    public static function json(array $payload, int $status = 200): never
    {
        if (!headers_sent()) {
            http_response_code($status);
            header('Content-Type: application/json; charset=utf-8');
            header('X-Content-Type-Options: nosniff');
            header('Cache-Control: no-store');
        }
        echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }

    public static function success(mixed $data = null, string $message = 'OK', array $extra = []): never
    {
        self::json(array_merge([
            'success' => true,
            'message' => $message,
            'data'    => $data,
        ], $extra));
    }

    public static function error(string $message = 'Something went wrong.', int $status = 400, array $extra = []): never
    {
        self::json(array_merge([
            'success' => false,
            'message' => $message,
        ], $extra), $status);
    }

    /** Validation failure envelope: 422 plus a field => message map. */
    public static function validationError(array $errors, string $message = 'Please correct the highlighted fields.'): never
    {
        self::json([
            'success' => false,
            'message' => $message,
            'errors'  => $errors,
        ], 422);
    }

    public static function redirect(string $path, ?string $flashType = null, ?string $flashMessage = null): never
    {
        if ($flashType && $flashMessage) {
            Session::flash($flashType, $flashMessage);
        }
        $target = str_starts_with($path, 'http') ? $path : url($path);
        header('Location: ' . $target);
        exit;
    }

    public static function back(?string $flashType = null, ?string $flashMessage = null): never
    {
        if ($flashType && $flashMessage) {
            Session::flash($flashType, $flashMessage);
        }
        header('Location: ' . ($_SERVER['HTTP_REFERER'] ?? url('admin/index.php')));
        exit;
    }

    /** Streams an array of rows as a CSV download. */
    public static function csv(string $filename, array $headers, array $rows): never
    {
        if (!headers_sent()) {
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="' . preg_replace('/[^A-Za-z0-9._-]/', '', $filename) . '"');
            header('Cache-Control: no-store');
        }
        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF"); // BOM so Excel reads UTF-8 correctly
        if ($headers) {
            fputcsv($out, $headers);
        }
        foreach ($rows as $row) {
            fputcsv($out, array_map(static fn($v) => is_scalar($v) || $v === null ? (string)$v : json_encode($v), (array)$row));
        }
        fclose($out);
        exit;
    }
}
