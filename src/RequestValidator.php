<?php
/**
 * Security validation class for API inputs
 */

class RequestValidator {
    /**
     * Validate API request
     */
    public static function validateRequest() {
        // Skip validation if running from CLI (development)
        if (php_sapi_name() === 'cli') {
            return;
        }
        
        // Skip validation in development environment if no API_KEY is set
        if (ENVIRONMENT === 'development' && empty(API_KEY)) {
            return;
        }
        
        // Check API key
        $apiKey = $_SERVER['HTTP_X_API_KEY'] ?? null;
        
        if (empty($apiKey) || $apiKey !== API_KEY) {
            throw new Exception('Invalid or missing API key', 401);
        }
        
        // Validate CORS
        self::validateCORS();
        
        // Validate request method
        $method = $_SERVER['REQUEST_METHOD'];
        if (!in_array($method, ['GET', 'POST', 'OPTIONS'])) {
            throw new Exception('Method not allowed', 405);
        }
    }
    
    /**
     * Validate CORS origin
     */
    public static function validateCORS() {
        // Skip CORS validation if running from CLI
        if (php_sapi_name() === 'cli') {
            return;
        }
        
        $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
        $allowedOrigins = ALLOWED_ORIGINS;
        
        if (empty($origin)) {
            return; // Allow same-origin requests
        }
        
        // Check if origin is allowed
        $isAllowed = false;
        foreach ($allowedOrigins as $allowed) {
            $allowed = trim($allowed);
            if ($origin === $allowed || $allowed === '*') {
                $isAllowed = true;
                break;
            }
        }
        
        if (!$isAllowed && ENVIRONMENT !== 'development') {
            throw new Exception('CORS policy violation', 403);
        }
        
        if ($isAllowed) {
            header("Access-Control-Allow-Origin: $origin");
            header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
            header('Access-Control-Allow-Headers: Content-Type, X-API-Key');
        }
    }
    
    /**
     * Sanitize input parameter
     */
    public static function sanitizeInput($value, $type = 'string') {
        if ($type === 'string') {
            return htmlspecialchars(trim($value), ENT_QUOTES, 'UTF-8');
        } elseif ($type === 'int') {
            return (int)$value;
        }
        return $value;
    }
    
    /**
     * Validate tipo parameter (whitelist)
     */
    public static function validateTipo($tipo) {
        $validTypes = ['logistica', 'contactos'];
        
        if (!in_array($tipo, $validTypes, true)) {
            throw new Exception('Invalid tipo parameter', 400);
        }
        
        return $tipo;
    }
    
    /**
     * Validate rows data
     */
    public static function validateRows($rows) {
        if (!is_array($rows)) {
            throw new Exception('Rows must be an array', 400);
        }
        
        if (empty($rows)) {
            throw new Exception('Rows cannot be empty', 400);
        }
        
        // Limit rows to prevent abuse
        if (count($rows) > 1000) {
            throw new Exception('Too many rows (max 1000)', 400);
        }
        
        return $rows;
    }
}
