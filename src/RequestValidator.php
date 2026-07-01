<?php

class RequestValidator
{
    public static function validateRequest()
    {
        if (php_sapi_name() === "cli") {
            return;
        }

        if (($_SERVER["REQUEST_METHOD"] ?? "") === "OPTIONS") {
            self::validateCORS();
            http_response_code(204);
            exit();
        }

        if (ENVIRONMENT === "development") {
            return;
        }

        self::validateCORS();

        if (ENVIRONMENT === "development") {
            return;
        }

        self::validateCORS();

        $referer = $_SERVER["HTTP_REFERER"] ?? "";
        $host = $_SERVER["HTTP_HOST"] ?? "";

        if (!empty($referer)) {
            $refererHost = parse_url($referer, PHP_URL_HOST);
            if ($refererHost !== $host) {
                throw new Exception("Invalid request origin", 403);
            }
        }

        $method = $_SERVER["REQUEST_METHOD"];
        if (!in_array($method, ["GET", "POST", "PUT", "DELETE", "OPTIONS"])) {
            throw new Exception("Method not allowed", 405);
        }
    }

    public static function validateCORS()
    {
        if (php_sapi_name() === "cli") {
            return;
        }

        $origin = $_SERVER["HTTP_ORIGIN"] ?? "";
        $allowedOrigins = ALLOWED_ORIGINS;

        if (empty($origin)) {
            return;
        }

        $isAllowed = false;
        foreach ($allowedOrigins as $allowed) {
            $allowed = trim($allowed);
            if ($origin === $allowed || $allowed === "*") {
                $isAllowed = true;
                break;
            }
        }

        if (!$isAllowed && ENVIRONMENT !== "development") {
            throw new Exception("CORS policy violation", 403);
        }

        if ($isAllowed) {
            header("Access-Control-Allow-Origin: $origin");
            header(
                "Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS",
            );
            header(
                "Access-Control-Allow-Headers: Content-Type, X-API-Key, Authorization",
            );
        }
    }

    public static function sanitizeInput($value, $type = "string")
    {
        if ($type === "string") {
            return htmlspecialchars(trim($value), ENT_QUOTES, "UTF-8");
        } elseif ($type === "int") {
            return (int) $value;
        }
        return $value;
    }

    public static function validateTipo($tipo)
    {
        $validTypes = [
            "logistica",
            "contactos",
            "usuarios",
            "directorio_monitoreo",
        ];

        if (!in_array($tipo, $validTypes, true)) {
            throw new Exception("Invalid tipo parameter", 400);
        }

        return $tipo;
    }

    public static function validateRows($rows)
    {
        if (!is_array($rows)) {
            throw new Exception("Rows must be an array", 400);
        }

        if (empty($rows)) {
            throw new Exception("Rows cannot be empty", 400);
        }

        if (count($rows) > 1000) {
            throw new Exception("Too many rows (max 1000)", 400);
        }

        return $rows;
    }
}
