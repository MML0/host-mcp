<?php
declare(strict_types=1);

/*
 * PHP MCP file manager — place this file at public_html/mcp/index.php
 * Keep this directory excluded from the managed website root.
 */

const MCP_TOKEN = 'REPLACE_WITH_A_LONG_RANDOM_SECRET';
const MCP_PROTOCOL_VERSION = '2025-03-26';
const MCP_SERVER_NAME = 'php-host-manager';
const MCP_SERVER_VERSION = '1.0.0';

/* By default, manages public_html except the /mcp directory itself. */
$ROOT = realpath(dirname(__DIR__));
if ($ROOT === false || !is_dir($ROOT)) {
    http_response_code(500);
    exit('Invalid configured root');
}
$PROTECTED_DIR = realpath(__DIR__);

header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

function respond(array $data, int $status = 200): never {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function rpcError($id, int $code, string $message): array {
    return ['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => $code, 'message' => $message]];
}

function pathFor(string $relative, string $root, string $protectedDir, bool $mustExist = false): string {
    if ($relative === '' || str_contains($relative, "\0") || str_starts_with($relative, '/') ||
        preg_match('/^[A-Za-z]:[\\\\\\/]/', $relative)) {
        throw new RuntimeException('Path must be a non-empty relative path.');
    }
    $parts = preg_split('~[\\\\/]~', $relative);
    if (in_array('..', $parts, true)) {
        throw new RuntimeException('Parent-directory traversal is not allowed.');
    }

    $candidate = $root . DIRECTORY_SEPARATOR . implode(DIRECTORY_SEPARATOR, $parts);
    if ($mustExist) {
        $resolved = realpath($candidate);
        if ($resolved === false) throw new RuntimeException('Path does not exist.');
        $candidate = $resolved;
    } else {
        $parent = realpath(dirname($candidate));
        if ($parent === false) {
            throw new RuntimeException('Parent directory does not exist.');
        }
        $candidate = $parent . DIRECTORY_SEPARATOR . basename($candidate);
    }

    $rootPrefix = rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
    if ($candidate !== $root && !str_starts_with($candidate, $rootPrefix)) {
        throw new RuntimeException('Path is outside the managed root.');
    }

    if ($protectedDir !== false) {
        $protectedPrefix = rtrim($protectedDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        if ($candidate === $protectedDir || str_starts_with($candidate, $protectedPrefix)) {
            throw new RuntimeException('The MCP server directory is protected.');
        }
    }

    return $candidate;
}

function walkFiles(string $dir, string $root, string $protectedDir, int $depth = 0): array {
    if ($depth > 8) return [];
    $items = [];
    $names = @scandir($dir);
    if ($names === false) return [];
    foreach ($names as $name) {
        if ($name === '.' || $name === '..') continue;
        $full = $dir . DIRECTORY_SEPARATOR . $name;
        if (is_link($full)) continue;
        if ($protectedDir !== false && ($full === $protectedDir ||
            str_starts_with($full, rtrim($protectedDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR))) continue;
        $relative = ltrim(substr($full, strlen($root)), DIRECTORY_SEPARATOR);
        $items[] = [
            'path' => str_replace(DIRECTORY_SEPARATOR, '/', $relative),
            'type' => is_dir($full) ? 'directory' : 'file',
            'size' => is_file($full) ? filesize($full) : null,
            'permissions' => substr(sprintf('%o', fileperms($full)), -4)
        ];
        if (is_dir($full)) {
            $items = array_merge($items, walkFiles($full, $root, $protectedDir, $depth + 1));
        }
    }
    return $items;
}

/* Create a relative directory and any missing parents, one checked step at a time. */
function ensureDir(string $relative, string $root, string $protectedDir): void {
    $built = '';
    foreach (explode('/', $relative) as $part) {
        $built = $built === '' ? $part : $built . '/' . $part;
        $path = pathFor($built, $root, $protectedDir, false);
        if (is_link($path)) throw new RuntimeException('Symlinks are not supported: ' . $built);
        if (is_dir($path)) continue;
        if (file_exists($path)) throw new RuntimeException('Not a directory: ' . $built);
        if (!@mkdir($path, 0755)) throw new RuntimeException('Could not create directory: ' . $built);
    }
}

/* Zip entry names are untrusted: reject anything that is not a plain relative path. */
function zipEntryName(string $name): string {
    $name = str_replace('\\', '/', $name);
    if (str_starts_with($name, './')) $name = substr($name, 2);
    $parts = explode('/', rtrim($name, '/'));
    if ($name === '' || str_contains($name, "\0") || str_starts_with($name, '/') ||
        preg_match('/^[A-Za-z]:/', $name) || array_intersect($parts, ['', '.', '..'])) {
        throw new RuntimeException('Unsafe path in zip: ' . $name);
    }
    return $name;
}

function toolDefinitions(): array {
    return [
        [
            'name' => 'get_server_info',
            'description' => 'Return basic PHP host information and the managed root path.',
            'inputSchema' => ['type' => 'object', 'properties' => new stdClass(), 'additionalProperties' => false]
        ],
        [
            'name' => 'list_files',
            'description' => 'List files and directories. Optional path is relative to the managed root; use "." for the root.',
            'inputSchema' => ['type' => 'object', 'properties' => [
                'path' => ['type' => 'string', 'default' => '.', 'description' => 'Relative directory path']
            ], 'additionalProperties' => false]
        ],
        [
            'name' => 'read_file',
            'description' => 'Read a UTF-8 text file. Binary files are not supported. Maximum size: 1 MB.',
            'inputSchema' => ['type' => 'object', 'properties' => [
                'path' => ['type' => 'string', 'description' => 'Relative file path']
            ], 'required' => ['path'], 'additionalProperties' => false]
        ],
        [
            'name' => 'write_file',
            'description' => 'Create or overwrite a UTF-8 text file. Parent directory must already exist. Maximum content: 1 MB.',
            'inputSchema' => ['type' => 'object', 'properties' => [
                'path' => ['type' => 'string'],
                'content' => ['type' => 'string']
            ], 'required' => ['path', 'content'], 'additionalProperties' => false]
        ],
        [
            'name' => 'create_directory',
            'description' => 'Create one directory. Its parent directory must already exist.',
            'inputSchema' => ['type' => 'object', 'properties' => [
                'path' => ['type' => 'string']
            ], 'required' => ['path'], 'additionalProperties' => false]
        ],
        [
            'name' => 'delete_file',
            'description' => 'Permanently delete a file (not a directory). This cannot be undone.',
            'inputSchema' => ['type' => 'object', 'properties' => [
                'path' => ['type' => 'string']
            ], 'required' => ['path'], 'additionalProperties' => false]
        ],
        [
            'name' => 'set_permissions',
            'description' => 'Set file or directory permissions. Allowed modes: 600, 640, 644, 700, 750, 755.',
            'inputSchema' => ['type' => 'object', 'properties' => [
                'path' => ['type' => 'string'],
                'mode' => ['type' => 'string', 'enum' => ['600','640','644','700','750','755']]
            ], 'required' => ['path', 'mode'], 'additionalProperties' => false]
        ],
        [
            'name' => 'extract_zip',
            'description' => 'Upload a base64-encoded .zip and extract it into an existing directory, creating subfolders and overwriting files. Binary files are supported. Max 8 MB zipped, 100 MB / 5000 entries unpacked. Skips __MACOSX/ and .DS_Store. Every name is checked before anything is written.',
            'inputSchema' => ['type' => 'object', 'properties' => [
                'zip_base64' => ['type' => 'string', 'description' => 'The .zip file, base64-encoded'],
                'path' => ['type' => 'string', 'default' => '.', 'description' => 'Relative destination directory; use "." for the root']
            ], 'required' => ['zip_base64'], 'additionalProperties' => false]
        ]
    ];
}

function toolCall(string $name, array $args, string $root, string $protectedDir): array {
    switch ($name) {
        case 'get_server_info':
            return ['php_version' => PHP_VERSION, 'managed_root' => $root,
                'free_disk_bytes' => @disk_free_space($root) ?: null,
                'max_upload_bytes' => ini_get('upload_max_filesize'),
                'max_execution_time' => ini_get('max_execution_time')];

        case 'list_files':
            $relative = (string)($args['path'] ?? '.');
            if ($relative === '.') $dir = $root;
            else $dir = pathFor($relative, $root, $protectedDir, true);
            if (!is_dir($dir)) throw new RuntimeException('Path is not a directory.');
            return ['items' => walkFiles($dir, $root, $protectedDir)];

        case 'read_file':
            $path = pathFor((string)($args['path'] ?? ''), $root, $protectedDir, true);
            if (!is_file($path)) throw new RuntimeException('Path is not a file.');
            if (filesize($path) > 1048576) throw new RuntimeException('File exceeds 1 MB limit.');
            $content = @file_get_contents($path);
            if ($content === false || str_contains($content, "\0")) throw new RuntimeException('Unable to read as a text file.');
            return ['path' => $args['path'], 'content' => $content];

        case 'write_file':
            $relative = (string)($args['path'] ?? '');
            $content = $args['content'] ?? null;
            if (!is_string($content)) throw new RuntimeException('content must be a string.');
            if (strlen($content) > 1048576) throw new RuntimeException('Content exceeds 1 MB limit.');
            $path = pathFor($relative, $root, $protectedDir, false);
            if (file_exists($path) && (!is_file($path) || is_link($path))) throw new RuntimeException('Target must be a regular file.');
            $written = @file_put_contents($path, $content, LOCK_EX);
            if ($written === false) throw new RuntimeException('Write failed. Check PHP user permissions.');
            return ['path' => $relative, 'bytes_written' => $written];

        case 'create_directory':
            $relative = (string)($args['path'] ?? '');
            $path = pathFor($relative, $root, $protectedDir, false);
            if (file_exists($path)) throw new RuntimeException('Path already exists.');
            if (!@mkdir($path, 0755)) throw new RuntimeException('Could not create directory.');
            return ['path' => $relative, 'created' => true];

        case 'delete_file':
            $relative = (string)($args['path'] ?? '');
            $path = pathFor($relative, $root, $protectedDir, true);
            if (!is_file($path) || is_link($path)) throw new RuntimeException('Only regular files can be deleted.');
            if (!@unlink($path)) throw new RuntimeException('Delete failed.');
            return ['path' => $relative, 'deleted' => true];

        case 'set_permissions':
            $relative = (string)($args['path'] ?? '');
            $modeText = (string)($args['mode'] ?? '');
            $allowed = ['600' => 0600, '640' => 0640, '644' => 0644, '700' => 0700, '750' => 0750, '755' => 0755];
            if (!isset($allowed[$modeText])) throw new RuntimeException('Permission mode is not allowed.');
            $path = pathFor($relative, $root, $protectedDir, true);
            if (is_link($path)) throw new RuntimeException('Symlinks are not supported.');
            if (!@chmod($path, $allowed[$modeText])) throw new RuntimeException('chmod failed; host may restrict permissions.');
            return ['path' => $relative, 'mode' => $modeText, 'changed' => true];

        case 'extract_zip':
            if (!class_exists('ZipArchive')) throw new RuntimeException('The PHP zip extension is not enabled on this host.');
            $dest = (string)($args['path'] ?? '.');
            if ($dest !== '.' && !is_dir(pathFor($dest, $root, $protectedDir, true))) {
                throw new RuntimeException('Destination is not a directory.');
            }
            $prefix = $dest === '.' ? '' : trim(str_replace('\\', '/', $dest), '/') . '/';
            $bytes = base64_decode((string)($args['zip_base64'] ?? ''), true);
            if ($bytes === false || $bytes === '') throw new RuntimeException('zip_base64 is not valid base64.');
            if (strlen($bytes) > 8 * 1048576) throw new RuntimeException('Zip exceeds 8 MB limit.');

            $tmp = tempnam(sys_get_temp_dir(), 'mcpzip');
            if ($tmp === false || file_put_contents($tmp, $bytes) === false) throw new RuntimeException('Could not stage the zip.');
            $zip = new ZipArchive();
            $opened = false;
            try {
                if ($zip->open($tmp) !== true) throw new RuntimeException('Not a valid zip file.');
                $opened = true;
                if ($zip->numFiles > 5000) throw new RuntimeException('Zip has more than 5000 entries.');

                /* Validate every entry before writing any, so a bad zip changes nothing. */
                $protectedRel = str_starts_with($protectedDir, $root . DIRECTORY_SEPARATOR)
                    ? str_replace(DIRECTORY_SEPARATOR, '/', substr($protectedDir, strlen($root) + 1)) : null;
                $entries = [];
                $skipped = [];
                $unpacked = 0;
                for ($i = 0; $i < $zip->numFiles; $i++) {
                    $raw = (string)$zip->getNameIndex($i);
                    if (str_starts_with($raw, '__MACOSX/') || basename($raw) === '.DS_Store') {
                        $skipped[] = $raw;
                        continue;
                    }
                    $entries[$i] = zipEntryName($raw);
                    $target = rtrim($prefix . $entries[$i], '/');
                    if ($protectedRel !== null && ($target === $protectedRel || str_starts_with($target, $protectedRel . '/'))) {
                        throw new RuntimeException('The MCP server directory is protected: ' . $raw);
                    }
                    $unpacked += (int)$zip->statIndex($i)['size'];
                }
                if ($unpacked > 100 * 1048576) throw new RuntimeException('Zip unpacks to more than 100 MB.');

                $files = 0;
                $dirs = 0;
                $written = 0;
                foreach ($entries as $i => $name) {
                    if (str_ends_with($name, '/')) {
                        ensureDir($prefix . rtrim($name, '/'), $root, $protectedDir);
                        $dirs++;
                        continue;
                    }
                    if (str_contains($name, '/')) ensureDir($prefix . dirname($name), $root, $protectedDir);
                    $path = pathFor($prefix . $name, $root, $protectedDir, false);
                    if (file_exists($path) && (!is_file($path) || is_link($path))) throw new RuntimeException('Target must be a regular file: ' . $name);
                    $content = $zip->getFromIndex($i);
                    if ($content === false) throw new RuntimeException('Could not read from zip: ' . $name);
                    if (@file_put_contents($path, $content, LOCK_EX) === false) throw new RuntimeException('Write failed: ' . $name);
                    $files++;
                    $written += strlen($content);
                }
                return ['destination' => $dest, 'files' => $files, 'directories' => $dirs,
                    'bytes_written' => $written, 'skipped' => $skipped];
            } finally {
                if ($opened) $zip->close();
                @unlink($tmp);
            }

        default:
            throw new RuntimeException('Unknown tool: ' . $name);
    }
}

/* Only HTTPS should be used. If Origin is present, require it to match this host. */
if (!empty($_SERVER['HTTP_ORIGIN'])) {
    $originHost = parse_url($_SERVER['HTTP_ORIGIN'], PHP_URL_HOST);
    $requestHost = $_SERVER['HTTP_HOST'] ?? '';
    if (!$originHost || strcasecmp($originHost, preg_replace('/:\d+$/', '', $requestHost)) !== 0) {
        respond(['error' => 'Origin not allowed'], 403);
    }
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    respond(['error' => 'Use POST with a JSON-RPC MCP request.'], 405);
}

$auth = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
if (!$auth && function_exists('getallheaders')) {
    $headers = getallheaders();
    $auth = $headers['Authorization'] ?? $headers['authorization'] ?? '';
}
if (!preg_match('/^Bearer\s+(.+)$/i', $auth, $match) || !hash_equals(MCP_TOKEN, trim($match[1])) ||
    MCP_TOKEN === 'REPLACE_WITH_A_LONG_RANDOM_SECRET') {
    respond(['error' => 'Unauthorized'], 401);
}

$raw = file_get_contents('php://input');
/* 12 MB: room for an 8 MB zip once base64 and JSON have inflated it. */
if ($raw === false || strlen($raw) > 12 * 1048576) respond(['error' => 'Request too large'], 413);
$request = json_decode($raw, true);
if (!is_array($request) || ($request['jsonrpc'] ?? '') !== '2.0' || !isset($request['method'])) {
    respond(rpcError($request['id'] ?? null, -32600, 'Invalid Request'), 400);
}
$id = $request['id'] ?? null;
$method = (string)$request['method'];
$params = is_array($request['params'] ?? null) ? $request['params'] : [];

if (str_starts_with($method, 'notifications/')) {
    http_response_code(202);
    exit;
}

try {
    switch ($method) {
        case 'initialize':
            $result = [
                'protocolVersion' => MCP_PROTOCOL_VERSION,
                'capabilities' => ['tools' => ['listChanged' => false]],
                'serverInfo' => ['name' => MCP_SERVER_NAME, 'version' => MCP_SERVER_VERSION],
                'instructions' => 'File access is restricted to the configured managed root. Paths are relative.'
            ];
            break;
        case 'ping':
            $result = new stdClass();
            break;
        case 'tools/list':
            $result = ['tools' => toolDefinitions()];
            break;
        case 'tools/call':
            $name = (string)($params['name'] ?? '');
            $args = is_array($params['arguments'] ?? null) ? $params['arguments'] : [];
            $data = toolCall($name, $args, $ROOT, $PROTECTED_DIR ?: '');
            $result = ['content' => [['type' => 'text', 'text' => json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)]], 'structuredContent' => $data, 'isError' => false];
            break;
        default:
            respond(rpcError($id, -32601, 'Method not found'), 404);
    }
    if (!array_key_exists('id', $request)) {
        http_response_code(202);
        exit;
    }
    respond(['jsonrpc' => '2.0', 'id' => $id, 'result' => $result]);
} catch (Throwable $e) {
    if ($method === 'tools/call') {
        respond(['jsonrpc' => '2.0', 'id' => $id, 'result' => [
            'content' => [['type' => 'text', 'text' => $e->getMessage()]],
            'isError' => true
        ]]);
    }
    respond(rpcError($id, -32603, 'Internal error'), 500);
}
