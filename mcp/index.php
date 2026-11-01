<?php
declare(strict_types=1);

/*
 * PHP MCP file manager — place this file at public_html/mcp/index.php
 * Keep this directory excluded from the managed website root.
 */

const MCP_TOKEN = 'REPLACE_WITH_A_LONG_RANDOM_SECRET';
const MCP_PROTOCOL_VERSION = '2025-03-26';
const MCP_SERVER_NAME = 'php-host-manager';
const MCP_SERVER_VERSION = '1.1.0';
const MCP_JSON_FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE;
const MCP_MAX_DEPTH = 8;
const MCP_MAX_ZIP_UNPACKED = 100 * 1048576;

/* By default, manages public_html except the /mcp directory itself. */
$ROOT = realpath(dirname(__DIR__));
if ($ROOT === false || !is_dir($ROOT)) {
    http_response_code(500);
    exit('Invalid configured root');
}
$PROTECTED_DIR = realpath(__DIR__);

header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

/* A tool failure an agent can act on: a stable code, a message, and what to try next. */
final class ToolError extends RuntimeException {
    public function __construct(public string $errorCode, string $message, public string $hint = '') {
        parent::__construct($message);
    }
}

function respond(array $data, int $status = 200): never {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, MCP_JSON_FLAGS);
    exit;
}

function rpcError($id, int $code, string $message): array {
    return ['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => $code, 'message' => $message]];
}

function pathFor(string $relative, string $root, string $protectedDir, bool $mustExist = false): string {
    if ($relative === '' || str_contains($relative, "\0") || str_starts_with($relative, '/') ||
        preg_match('/^[A-Za-z]:[\\\\\\/]/', $relative)) {
        throw new ToolError('invalid_path', 'Path must be a non-empty relative path.', 'Use a path relative to the managed root, or "." for the root.');
    }
    $parts = preg_split('~[\\\\/]~', $relative);
    if (in_array('..', $parts, true)) {
        throw new ToolError('invalid_path', 'Parent-directory traversal is not allowed.', 'Remove ".." from the path.');
    }
    $parts = array_values(array_filter($parts, fn($part) => $part !== '' && $part !== '.'));

    /* Never operate through a symlink: check every component without following it. */
    $candidate = $root;
    foreach ($parts as $part) {
        $candidate .= DIRECTORY_SEPARATOR . $part;
        if (is_link($candidate)) {
            throw new ToolError('symlink_rejected', 'Symlinks are not supported: ' . $relative, 'Use the real path, or remove the link in the host file manager.');
        }
    }

    if ($mustExist) {
        $resolved = realpath($candidate);
        if ($resolved === false) {
            throw new ToolError('not_found', 'Path does not exist: ' . $relative, 'Call list_files or search_files to find the right path.');
        }
        $candidate = $resolved;
    } else {
        $parent = realpath(dirname($candidate));
        if ($parent === false) {
            throw new ToolError('parent_missing', 'Parent directory does not exist: ' . $relative, 'Call create_directory for each level first, or use extract_zip.');
        }
        $candidate = $parts === [] ? $root : $parent . DIRECTORY_SEPARATOR . basename($candidate);
    }

    $rootPrefix = rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
    if ($candidate !== $root && !str_starts_with($candidate, $rootPrefix)) {
        throw new ToolError('outside_root', 'Path is outside the managed root.', 'Call get_server_info to see the managed root.');
    }

    if ($protectedDir !== '') {
        $protectedPrefix = rtrim($protectedDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        if ($candidate === $protectedDir || str_starts_with($candidate, $protectedPrefix)) {
            throw new ToolError('protected_path', 'The MCP server directory is protected.', 'Change this folder through the host file manager or FTP.');
        }
    }

    return $candidate;
}

function itemInfo(string $full, string $root): array {
    $relative = ltrim(substr($full, strlen($root)), DIRECTORY_SEPARATOR);
    return [
        'path' => str_replace(DIRECTORY_SEPARATOR, '/', $relative),
        'type' => is_dir($full) ? 'directory' : 'file',
        'size' => is_file($full) ? filesize($full) : null,
        'permissions' => substr(sprintf('%o', fileperms($full)), -4)
    ];
}

/* Yields full paths in a stable order, so an offset means the same thing between calls. */
function walkPaths(string $dir, string $protectedDir, bool $recursive = true, int $depth = 0): Generator {
    if ($depth > MCP_MAX_DEPTH) return;
    $names = @scandir($dir);
    if ($names === false) return;
    foreach ($names as $name) {
        if ($name === '.' || $name === '..') continue;
        $full = $dir . DIRECTORY_SEPARATOR . $name;
        if (is_link($full)) continue;
        if ($protectedDir !== '' && ($full === $protectedDir ||
            str_starts_with($full, rtrim($protectedDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR))) continue;
        yield $full;
        if ($recursive && is_dir($full)) {
            yield from walkPaths($full, $protectedDir, true, $depth + 1);
        }
    }
}

function intArg(array $args, string $name, int $default, int $min, int $max): int {
    $value = $args[$name] ?? $default;
    if (!is_int($value) || $value < $min || $value > $max) {
        throw new ToolError('invalid_argument', "$name must be an integer from $min to $max.");
    }
    return $value;
}

/*
 * Write through a temp file and rename, so readers never see a partial file.
 * $source is a string or an open stream; streams stop at $limit bytes.
 * A folder the PHP user cannot create files in falls back to writing in place.
 */
function writeFileSafely(string $path, $source, int $limit = PHP_INT_MAX): array {
    $dir = dirname($path);
    $tmp = @tempnam($dir, '.mcp-');
    /* tempnam silently uses the system temp folder when $dir is not writable. */
    if ($tmp !== false && realpath(dirname($tmp)) !== realpath($dir)) {
        @unlink($tmp);
        $tmp = false;
    }
    $atomic = $tmp !== false;
    if (!$atomic && !(is_file($path) && is_writable($path))) {
        throw new ToolError('write_failed', 'Write failed: ' . basename($path), 'Check that the PHP user owns this folder.');
    }

    $out = @fopen($atomic ? $tmp : $path, $atomic ? 'wb' : 'cb');
    if ($out === false) {
        if ($atomic) @unlink($tmp);
        throw new ToolError('write_failed', 'Write failed: ' . basename($path), 'Check that the PHP user owns this file.');
    }
    $bytes = 0;
    $failed = null;
    try {
        if (!$atomic) {
            flock($out, LOCK_EX);
            ftruncate($out, 0);
        }
        $chunks = is_string($source) ? [$source] : null;
        while ($chunks !== null ? $chunks !== [] : !feof($source)) {
            $chunk = $chunks !== null ? array_shift($chunks) : fread($source, 65536);
            if ($chunk === false) { $failed = 'Could not read the source data.'; break; }
            $bytes += strlen($chunk);
            if ($bytes > $limit) { $failed = 'Data exceeds the size limit.'; break; }
            if ($chunk !== '' && fwrite($out, $chunk) !== strlen($chunk)) { $failed = 'Disk write failed; the disk may be full.'; break; }
        }
    } finally {
        fclose($out);
    }
    if ($failed !== null) {
        if ($atomic) @unlink($tmp);
        throw new ToolError('write_failed', $failed . ' File: ' . basename($path), $atomic ? 'The original file was not changed.' : 'The file was written in place and may be incomplete.');
    }
    if ($atomic) {
        @chmod($tmp, file_exists($path) ? (fileperms($path) & 0777) : (0666 & ~umask()));
        if (!@rename($tmp, $path)) {
            @unlink($tmp);
            throw new ToolError('write_failed', 'Could not replace: ' . basename($path), 'Check that the PHP user owns this folder.');
        }
    }
    return ['bytes' => $bytes, 'atomic' => $atomic];
}

/* Create a relative directory and any missing parents, one checked step at a time. */
function ensureDir(string $relative, string $root, string $protectedDir): void {
    $built = '';
    foreach (explode('/', $relative) as $part) {
        $built = $built === '' ? $part : $built . '/' . $part;
        $path = pathFor($built, $root, $protectedDir, false);
        if (is_dir($path)) continue;
        if (file_exists($path)) throw new ToolError('path_conflict', 'Not a directory: ' . $built);
        if (!@mkdir($path, 0755)) throw new ToolError('write_failed', 'Could not create directory: ' . $built, 'Check that the PHP user owns the parent folder.');
    }
}

/* Refuse a zip target that would clash with what is on disk, without creating anything. */
function checkZipTarget(string $relative, bool $isDir, string $root): void {
    $parts = explode('/', $relative);
    $current = $root;
    foreach ($parts as $i => $part) {
        $current .= DIRECTORY_SEPARATOR . $part;
        if (is_link($current)) {
            throw new ToolError('symlink_rejected', 'Zip target goes through a symlink: ' . $relative, 'Nothing was written.');
        }
        if (!file_exists($current)) return;
        $mustBeDir = $isDir || $i < count($parts) - 1;
        if ($mustBeDir ? !is_dir($current) : !is_file($current)) {
            throw new ToolError('path_conflict', 'Zip entry conflicts with an existing path: ' . $relative, 'Delete or rename the existing item. Nothing was written.');
        }
    }
}

/* Zip entry names are untrusted: reject anything that is not a plain relative path. */
function zipEntryName(string $name): string {
    $name = str_replace('\\', '/', $name);
    if (str_starts_with($name, './')) $name = substr($name, 2);
    $parts = explode('/', rtrim($name, '/'));
    if ($name === '' || str_contains($name, "\0") || str_starts_with($name, '/') ||
        preg_match('/^[A-Za-z]:/', $name) || array_intersect($parts, ['', '.', '..'])) {
        throw new ToolError('unsafe_zip', 'Unsafe path in zip: ' . $name, 'Rebuild the zip with plain relative paths. Nothing was written.');
    }
    return $name;
}

function toolDefinitions(): array {
    return [
        [
            'name' => 'get_server_info',
            'description' => 'Return the managed root, PHP version, extensions, memory, disk and hosting limits, and the limits of this server.',
            'inputSchema' => ['type' => 'object', 'properties' => new stdClass(), 'additionalProperties' => false]
        ],
        [
            'name' => 'list_files',
            'description' => 'List files and directories in pages. Path is relative to the managed root; use "." for the root. If has_more is true, call again with next_offset.',
            'inputSchema' => ['type' => 'object', 'properties' => [
                'path' => ['type' => 'string', 'default' => '.', 'description' => 'Relative directory path'],
                'recursive' => ['type' => 'boolean', 'default' => true, 'description' => 'Include subfolders, up to 8 levels'],
                'offset' => ['type' => 'integer', 'default' => 0, 'minimum' => 0],
                'limit' => ['type' => 'integer', 'default' => 1000, 'minimum' => 1, 'maximum' => 5000]
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
            'name' => 'search_files',
            'description' => 'Find files by name or extension. Give name, extension, or both. Does not search inside files.',
            'inputSchema' => ['type' => 'object', 'properties' => [
                'path' => ['type' => 'string', 'default' => '.', 'description' => 'Relative directory to search'],
                'name' => ['type' => 'string', 'description' => 'Case-insensitive part of the file name'],
                'extension' => ['type' => 'string', 'description' => 'File extension, such as php'],
                'limit' => ['type' => 'integer', 'default' => 200, 'minimum' => 1, 'maximum' => 5000]
            ], 'additionalProperties' => false]
        ],
        [
            'name' => 'get_file_info',
            'description' => 'Return type, size, MIME type, permissions and modification date for one path.',
            'inputSchema' => ['type' => 'object', 'properties' => [
                'path' => ['type' => 'string', 'description' => 'Relative path']
            ], 'required' => ['path'], 'additionalProperties' => false]
        ],
        [
            'name' => 'write_file',
            'description' => 'Create or overwrite a UTF-8 text file atomically where the host allows it. Parent directory must already exist. Maximum content: 1 MB.',
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
            $extensions = [];
            foreach (['zip', 'mbstring', 'fileinfo', 'curl', 'openssl', 'pdo_mysql', 'gd'] as $extension) {
                $extensions[$extension] = extension_loaded($extension);
            }
            return ['server_version' => MCP_SERVER_VERSION, 'php_version' => PHP_VERSION, 'php_sapi' => PHP_SAPI,
                'server_software' => $_SERVER['SERVER_SOFTWARE'] ?? null, 'managed_root' => $root,
                'extensions' => $extensions, 'zip_available' => class_exists('ZipArchive'),
                'free_disk_bytes' => @disk_free_space($root) ?: null,
                'total_disk_bytes' => @disk_total_space($root) ?: null,
                'memory_limit' => ini_get('memory_limit'),
                'post_max_size' => ini_get('post_max_size'),
                'max_upload_bytes' => ini_get('upload_max_filesize'),
                'max_execution_time' => ini_get('max_execution_time'),
                'tool_limits' => ['read_write_bytes' => 1048576, 'zip_bytes' => 8 * 1048576,
                    'zip_unpacked_bytes' => MCP_MAX_ZIP_UNPACKED, 'zip_entries' => 5000, 'list_depth' => MCP_MAX_DEPTH]];

        case 'list_files':
            $relative = (string)($args['path'] ?? '.');
            $dir = $relative === '.' ? $root : pathFor($relative, $root, $protectedDir, true);
            if (!is_dir($dir)) throw new ToolError('not_a_directory', 'Path is not a directory: ' . $relative, 'Use get_file_info or read_file for a file.');
            $offset = intArg($args, 'offset', 0, 0, PHP_INT_MAX);
            $limit = intArg($args, 'limit', 1000, 1, 5000);
            $items = [];
            $seen = 0;
            $hasMore = false;
            foreach (walkPaths($dir, $protectedDir, (bool)($args['recursive'] ?? true)) as $full) {
                if ($seen++ < $offset) continue;
                if (count($items) === $limit) { $hasMore = true; break; }
                $items[] = itemInfo($full, $root);
            }
            return ['items' => $items, 'offset' => $offset, 'has_more' => $hasMore,
                'next_offset' => $hasMore ? $offset + $limit : null];

        case 'search_files':
            $relative = (string)($args['path'] ?? '.');
            $name = (string)($args['name'] ?? '');
            $extension = ltrim((string)($args['extension'] ?? ''), '.');
            if ($name === '' && $extension === '') throw new ToolError('invalid_argument', 'Give name, extension, or both.');
            $dir = $relative === '.' ? $root : pathFor($relative, $root, $protectedDir, true);
            if (!is_dir($dir)) throw new ToolError('not_a_directory', 'Path is not a directory: ' . $relative);
            $limit = intArg($args, 'limit', 200, 1, 5000);
            $items = [];
            $truncated = false;
            foreach (walkPaths($dir, $protectedDir) as $full) {
                if (!is_file($full)) continue;
                $base = basename($full);
                if ($name !== '' && stripos($base, $name) === false) continue;
                if ($extension !== '' && strcasecmp(pathinfo($base, PATHINFO_EXTENSION), $extension) !== 0) continue;
                if (count($items) === $limit) { $truncated = true; break; }
                $items[] = itemInfo($full, $root);
            }
            return ['items' => $items, 'truncated' => $truncated];

        case 'get_file_info':
            $path = pathFor((string)($args['path'] ?? ''), $root, $protectedDir, true);
            $info = itemInfo($path, $root);
            $mime = null;
            if (is_file($path) && class_exists('finfo')) {
                $mime = (new finfo(FILEINFO_MIME_TYPE))->file($path) ?: null;
            }
            return $info + ['mime' => $mime, 'modified' => gmdate('c', (int)filemtime($path)),
                'readable' => is_readable($path), 'writable' => is_writable($path)];

        case 'read_file':
            $path = pathFor((string)($args['path'] ?? ''), $root, $protectedDir, true);
            if (!is_file($path)) throw new ToolError('not_a_file', 'Path is not a file: ' . $args['path'], 'Call list_files for a directory.');
            if (filesize($path) > 1048576) throw new ToolError('too_large', 'File exceeds 1 MB limit.', 'Call get_file_info for its size. This server cannot read larger files.');
            $content = @file_get_contents($path);
            if ($content === false) throw new ToolError('read_failed', 'Could not read the file.', 'Check its permissions with get_file_info.');
            if (str_contains($content, "\0") || preg_match('//u', $content) !== 1) {
                throw new ToolError('not_utf8', 'File is binary or not valid UTF-8.', 'Call get_file_info for its type. This server cannot read it as text.');
            }
            return ['path' => $args['path'], 'content' => $content];

        case 'write_file':
            $relative = (string)($args['path'] ?? '');
            $content = $args['content'] ?? null;
            if (!is_string($content)) throw new ToolError('invalid_argument', 'content must be a string.');
            if (strlen($content) > 1048576) throw new ToolError('too_large', 'Content exceeds 1 MB limit.', 'Use extract_zip for larger files.');
            $path = pathFor($relative, $root, $protectedDir, false);
            if (file_exists($path) && !is_file($path)) throw new ToolError('path_conflict', 'Target must be a regular file.');
            $result = writeFileSafely($path, $content);
            return ['path' => $relative, 'bytes_written' => $result['bytes'], 'atomic' => $result['atomic']];

        case 'create_directory':
            $relative = (string)($args['path'] ?? '');
            $path = pathFor($relative, $root, $protectedDir, false);
            if (file_exists($path)) throw new ToolError('path_conflict', 'Path already exists: ' . $relative);
            if (!@mkdir($path, 0755)) throw new ToolError('write_failed', 'Could not create directory.', 'Check that the PHP user owns the parent folder.');
            return ['path' => $relative, 'created' => true];

        case 'delete_file':
            $relative = (string)($args['path'] ?? '');
            $path = pathFor($relative, $root, $protectedDir, true);
            if (!is_file($path)) throw new ToolError('not_a_file', 'Only regular files can be deleted.', 'Delete the files inside, then remove the folder in the host file manager.');
            if (!@unlink($path)) throw new ToolError('write_failed', 'Delete failed.', 'Check that the PHP user owns the folder.');
            return ['path' => $relative, 'deleted' => true];

        case 'set_permissions':
            $relative = (string)($args['path'] ?? '');
            $modeText = (string)($args['mode'] ?? '');
            $allowed = ['600' => 0600, '640' => 0640, '644' => 0644, '700' => 0700, '750' => 0750, '755' => 0755];
            if (!isset($allowed[$modeText])) throw new ToolError('invalid_argument', 'Permission mode is not allowed.', 'Use 600, 640, 644, 700, 750 or 755.');
            $path = pathFor($relative, $root, $protectedDir, true);
            if (!@chmod($path, $allowed[$modeText])) throw new ToolError('write_failed', 'chmod failed; the host may restrict permissions.');
            return ['path' => $relative, 'mode' => $modeText, 'changed' => true];

        case 'extract_zip':
            if (!class_exists('ZipArchive')) throw new ToolError('zip_unavailable', 'The PHP zip extension is not enabled on this host.', 'Enable zip in the host PHP settings, or use write_file for text files.');
            $dest = (string)($args['path'] ?? '.');
            if ($dest !== '.' && !is_dir(pathFor($dest, $root, $protectedDir, true))) {
                throw new ToolError('not_a_directory', 'Destination is not a directory.', 'Call create_directory first.');
            }
            $prefix = $dest === '.' ? '' : trim(str_replace('\\', '/', $dest), '/') . '/';
            $bytes = base64_decode((string)($args['zip_base64'] ?? ''), true);
            if ($bytes === false || $bytes === '') throw new ToolError('invalid_argument', 'zip_base64 is not valid base64.');
            if (strlen($bytes) > 8 * 1048576) throw new ToolError('too_large', 'Zip exceeds 8 MB limit.', 'Split the upload into smaller zips.');

            $tmp = tempnam(sys_get_temp_dir(), 'mcpzip');
            if ($tmp === false || file_put_contents($tmp, $bytes) === false) throw new ToolError('write_failed', 'Could not stage the zip.', 'Check free disk space with get_server_info.');
            $zip = new ZipArchive();
            $opened = false;
            try {
                if ($zip->open($tmp) !== true) throw new ToolError('invalid_argument', 'Not a valid zip file.');
                $opened = true;
                if ($zip->numFiles > 5000) throw new ToolError('too_large', 'Zip has more than 5000 entries.', 'Split the upload into smaller zips.');

                /* Validate every entry before writing any, so a bad zip changes nothing. */
                $protectedRel = str_starts_with($protectedDir, $root . DIRECTORY_SEPARATOR)
                    ? str_replace(DIRECTORY_SEPARATOR, '/', substr($protectedDir, strlen($root) + 1)) : null;
                $entries = [];
                $skipped = [];
                $unpacked = 0;
                $fileTargets = [];
                $dirTargets = [];
                for ($i = 0; $i < $zip->numFiles; $i++) {
                    $raw = (string)$zip->getNameIndex($i);
                    if (str_starts_with($raw, '__MACOSX/') || basename($raw) === '.DS_Store') {
                        $skipped[] = $raw;
                        continue;
                    }
                    $name = zipEntryName($raw);
                    $entries[$raw] = $name;
                    $target = rtrim($prefix . $name, '/');
                    if ($protectedRel !== null && ($target === $protectedRel || str_starts_with($target, $protectedRel . '/'))) {
                        throw new ToolError('protected_path', 'The MCP server directory is protected: ' . $raw, 'Nothing was written.');
                    }
                    if (str_ends_with($name, '/')) {
                        $dirTargets[$target] = true;
                    } else {
                        if (isset($fileTargets[$target])) {
                            throw new ToolError('unsafe_zip', 'Duplicate file in zip: ' . $name, 'Rebuild the zip. Nothing was written.');
                        }
                        $fileTargets[$target] = true;
                    }
                    for ($parent = dirname($target); $parent !== '.' && $parent !== '/'; $parent = dirname($parent)) {
                        $dirTargets[$parent] = true;
                    }
                    $unpacked += (int)$zip->statIndex($i)['size'];
                }
                if ($unpacked > MCP_MAX_ZIP_UNPACKED) throw new ToolError('too_large', 'Zip unpacks to more than 100 MB.', 'Split the upload into smaller zips.');
                foreach ($fileTargets as $target => $_) {
                    if (isset($dirTargets[$target])) {
                        throw new ToolError('unsafe_zip', 'Zip uses the same path as a file and a folder: ' . $target, 'Rebuild the zip. Nothing was written.');
                    }
                    checkZipTarget((string)$target, false, $root);
                }
                foreach ($dirTargets as $target => $_) {
                    checkZipTarget((string)$target, true, $root);
                }

                /* Declared sizes can lie, so the real bytes are counted against the same limit. */
                $files = 0;
                $dirs = 0;
                $written = 0;
                foreach ($entries as $raw => $name) {
                    $raw = (string)$raw;
                    try {
                        if (str_ends_with($name, '/')) {
                            ensureDir($prefix . rtrim($name, '/'), $root, $protectedDir);
                            $dirs++;
                            continue;
                        }
                        if (str_contains($name, '/')) ensureDir($prefix . dirname($name), $root, $protectedDir);
                        $path = pathFor($prefix . $name, $root, $protectedDir, false);
                        $stream = $zip->getStream($raw);
                        if ($stream === false) throw new ToolError('invalid_argument', 'Could not read from zip: ' . $name);
                        try {
                            $result = writeFileSafely($path, $stream, MCP_MAX_ZIP_UNPACKED - $written);
                        } finally {
                            fclose($stream);
                        }
                    } catch (ToolError $e) {
                        throw new ToolError($e->errorCode, $e->getMessage(), "$files file(s) were already written before this failure. Fix the cause and send the zip again.");
                    }
                    $files++;
                    $written += $result['bytes'];
                }
                return ['destination' => $dest, 'files' => $files, 'directories' => $dirs,
                    'bytes_written' => $written, 'skipped' => $skipped];
            } finally {
                if ($opened) $zip->close();
                @unlink($tmp);
            }

        default:
            throw new ToolError('unknown_tool', 'Unknown tool: ' . $name, 'Call tools/list to see the available tools.');
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
                'instructions' => 'File access is restricted to the configured managed root. Paths are relative. Check isError on every tool result. Deletes and overwrites cannot be undone.'
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
            $result = ['content' => [['type' => 'text', 'text' => json_encode($data, MCP_JSON_FLAGS)]], 'structuredContent' => $data, 'isError' => false];
            break;
        default:
            respond(rpcError($id, -32601, 'Method not found'), 404);
    }
    if (!array_key_exists('id', $request)) {
        http_response_code(202);
        exit;
    }
    respond(['jsonrpc' => '2.0', 'id' => $id, 'result' => $result]);
} catch (ToolError $e) {
    if ($method !== 'tools/call') respond(rpcError($id, -32603, 'Internal error'), 500);
    respond(['jsonrpc' => '2.0', 'id' => $id, 'result' => [
        'content' => [['type' => 'text', 'text' => trim($e->getMessage() . ' ' . $e->hint)]],
        'structuredContent' => ['error' => ['code' => $e->errorCode, 'message' => $e->getMessage(), 'hint' => $e->hint]],
        'isError' => true
    ]]);
} catch (Throwable $e) {
    if ($method === 'tools/call') {
        respond(['jsonrpc' => '2.0', 'id' => $id, 'result' => [
            'content' => [['type' => 'text', 'text' => $e->getMessage()]],
            'isError' => true
        ]]);
    }
    respond(rpcError($id, -32603, 'Internal error'), 500);
}
