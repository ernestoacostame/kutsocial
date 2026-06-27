<?php
namespace KutSocial;

class Router {
    private array $routes = [];
    private array $corsHeaders = [];

    public function __construct() {
        // Habilitar CORS para integración con clientes externos (Tusky, KutPod, navegadores)
        $this->corsHeaders = [
            'Access-Control-Allow-Origin: *',
            'Access-Control-Allow-Headers: Authorization, Content-Type, Accept, Origin, X-Requested-With',
            'Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS',
            'Access-Control-Expose-Headers: Link, X-RateLimit-Limit, X-RateLimit-Remaining, X-RateLimit-Reset'
        ];
    }

    public function add(string $method, string $route, $handler): void {
        $this->routes[] = [
            'method' => strtoupper($method),
            'route' => $route,
            'handler' => $handler
        ];
    }

    public function get(string $route, $handler): void {
        $this->add('GET', $route, $handler);
    }

    public function post(string $route, $handler): void {
        $this->add('POST', $route, $handler);
    }

    public function put(string $route, $handler): void {
        $this->add('PUT', $route, $handler);
    }

    public function patch(string $route, $handler): void {
        $this->add('PATCH', $route, $handler);
    }

    public function delete(string $route, $handler): void {
        $this->add('DELETE', $route, $handler);
    }

    public function options(string $route, $handler): void {
        $this->add('OPTIONS', $route, $handler);
    }

    /**
     * Parsea peticiones multipart/form-data y urlencoded de tipo PATCH/PUT que PHP
     * no soporta de forma nativa en $_POST y $_FILES.
     */
    private function parseMultipartPatch(): void {
        $method = $_SERVER['REQUEST_METHOD'] ?? '';
        if ($method !== 'PATCH' && $method !== 'PUT') {
            return;
        }

        $contentType = $_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? '';
        if (!str_contains($contentType, 'multipart/form-data')) {
            if (str_contains($contentType, 'application/x-www-form-urlencoded')) {
                parse_str(file_get_contents('php://input'), $_POST);
            }
            return;
        }

        preg_match('/boundary=(.*)$/', $contentType, $matches);
        if (empty($matches[1])) {
            return;
        }
        $boundary = $matches[1];

        $raw = file_get_contents('php://input');
        $parts = explode('--' . $boundary, $raw);

        foreach ($parts as $part) {
            $part = trim($part);
            if ($part === '' || $part === '--') {
                continue;
            }

            $subparts = explode("\r\n\r\n", $part, 2);
            if (count($subparts) < 2) {
                continue;
            }
            $headersStr = $subparts[0];
            $body = $subparts[1];

            $headers = [];
            foreach (explode("\r\n", $headersStr) as $line) {
                $lineParts = explode(':', $line, 2);
                if (count($lineParts) === 2) {
                    $headers[strtolower(trim($lineParts[0]))] = trim($lineParts[1]);
                }
            }

            $contentDisposition = $headers['content-disposition'] ?? '';
            if (empty($contentDisposition)) {
                continue;
            }

            preg_match('/name="([^"]+)"/', $contentDisposition, $nameMatch);
            if (empty($nameMatch[1])) {
                continue;
            }
            $name = $nameMatch[1];

            preg_match('/filename="([^"]+)"/', $contentDisposition, $filenameMatch);
            if (!empty($filenameMatch[1])) {
                $filename = $filenameMatch[1];
                $subContentType = $headers['content-type'] ?? 'application/octet-stream';

                $tmpFile = tempnam(sys_get_temp_dir(), 'php_patch_upload_');
                if (str_ends_with($body, "\r\n")) {
                    $body = substr($body, 0, -2);
                }
                file_put_contents($tmpFile, $body);

                $_FILES[$name] = [
                    'name' => $filename,
                    'type' => $subContentType,
                    'tmp_name' => $tmpFile,
                    'error' => UPLOAD_ERR_OK,
                    'size' => filesize($tmpFile)
                ];
            } else {
                if (str_ends_with($body, "\r\n")) {
                    $body = substr($body, 0, -2);
                }

                if (str_contains($name, '[')) {
                    $parts = explode('[', $name);
                    $keys = [];
                    $keys[] = $parts[0];
                    for ($i = 1; $i < count($parts); $i++) {
                        $keys[] = rtrim($parts[$i], ']');
                    }
                    
                    $temp = &$_POST;
                    foreach ($keys as $idx => $key) {
                        if ($idx === count($keys) - 1) {
                            $temp[$key] = $body;
                        } else {
                            if (!isset($temp[$key]) || !is_array($temp[$key])) {
                                $temp[$key] = [];
                            }
                            $temp = &$temp[$key];
                        }
                    }
                } else {
                    $_POST[$name] = $body;
                }
            }
        }
    }

    public function dispatch(): void {
        $this->parseMultipartPatch();

        $requestMethod = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $requestUri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);

        // Limpiar headers CORS
        if ($requestMethod === 'OPTIONS') {
            foreach ($this->corsHeaders as $header) {
                header($header);
            }
            http_response_code(200);
            exit;
        }

        foreach ($this->routes as $route) {
            if ($route['method'] !== $requestMethod) {
                continue;
            }

            // Convertir /api/v1/accounts/:id en expresión regular
            $pattern = preg_replace('/:[a-zA-Z0-9_]+/', '([^/]+)', $route['route']);
            $pattern = '@^' . $pattern . '$@';

            if (preg_match($pattern, $requestUri, $matches)) {
                array_shift($matches); // Quitar la coincidencia completa

                // Extraer nombres de parámetros de la ruta para mapearlos
                preg_match_all('/:([a-zA-Z0-9_]+)/', $route['route'], $paramNames);
                $params = [];
                if (!empty($paramNames[1])) {
                    foreach ($paramNames[1] as $index => $name) {
                        $params[$name] = urldecode($matches[$index] ?? '');
                    }
                }

                // Aplicar headers CORS
                foreach ($this->corsHeaders as $header) {
                    header($header);
                }

                try {
                    call_user_func($route['handler'], $params);
                } catch (\Throwable $e) {
                    self::json(['error' => $e->getMessage()], 500);
                }
                return;
            }
        }

        // Si no coincide y es OPTIONS/CORS
        foreach ($this->corsHeaders as $header) {
            header($header);
        }

        self::json(['error' => 'Not Found'], 404);
    }

    public static function json(mixed $data, int $status = 200): void {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code($status);
        echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }

    public static function html(string $content, int $status = 200): void {
        header('Content-Type: text/html; charset=utf-8');
        http_response_code($status);
        echo $content;
        exit;
    }

    public static function getAllHeaders(): array {
        if (function_exists('getallheaders')) {
            $h = getallheaders();
            if ($h !== false) {
                return $h;
            }
        }
        $headers = [];
        foreach ($_SERVER as $name => $value) {
            if (str_starts_with($name, 'HTTP_')) {
                $key = str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($name, 5)))));
                $headers[$key] = $value;
            } elseif ($name === 'CONTENT_TYPE') {
                $headers['Content-Type'] = $value;
            } elseif ($name === 'CONTENT_LENGTH') {
                $headers['Content-Length'] = $value;
            }
        }
        return $headers;
    }

    public static function getBearerToken(): ?string {
        $headers = self::getAllHeaders();
        $auth = $headers['Authorization'] ?? $headers['authorization'] ?? $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        if (preg_match('/Bearer\s(\S+)/i', $auth, $matches)) {
            return $matches[1];
        }
        return null;
    }

    public static function getRequestBody(): array {
        $body = file_get_contents('php://input');
        $json = json_decode($body, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            return $json;
        }
        // Fallback a $_POST
        return $_POST;
    }
}
