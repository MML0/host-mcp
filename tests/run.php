<?php
declare(strict_types=1);

/* Runs the real server under PHP's built-in web server in a temp folder. Usage: php tests/run.php */

$site = sys_get_temp_dir() . '/host-mcp-test-' . bin2hex(random_bytes(4));
mkdir($site . '/mcp', 0755, true);
$token = 'test-token';
$source = (string)file_get_contents(__DIR__ . '/../mcp/index.php');
file_put_contents($site . '/mcp/index.php', str_replace('REPLACE_WITH_A_LONG_RANDOM_SECRET\';', $token . '\';', $source));

$port = 8940 + random_int(0, 50);
$server = proc_open([PHP_BINARY, '-S', "127.0.0.1:$port", '-t', $site], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
usleep(700000);

$failures = 0;
$checks = 0;
function check(string $label, bool $ok): void {
    global $failures, $checks;
    $checks++;
    if (!$ok) { $failures++; echo "FAIL: $label\n"; }
}
function http(string $body, string $bearer): array {
    global $port;
    $context = stream_context_create(['http' => ['method' => 'POST', 'ignore_errors' => true, 'timeout' => 15,
        'header' => "Content-Type: application/json\r\nAuthorization: Bearer $bearer\r\n", 'content' => $body]]);
    $raw = file_get_contents("http://127.0.0.1:$port/mcp/index.php", false, $context);
    preg_match('~ (\d{3})~', $http_response_header[0] ?? '', $status);
    return [(int)($status[1] ?? 0), json_decode((string)$raw, true)];
}
function tool(string $name, array $args = []): array {
    global $token;
    $body = json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => $name, 'arguments' => (object)$args]]);
    return http($body, $token)[1]['result'] ?? ['isError' => true, 'structuredContent' => ['error' => ['code' => 'bad_response']]];
}
function ok(array $result): bool { return ($result['isError'] ?? true) === false; }
function code(array $result): string { return $result['structuredContent']['error']['code'] ?? ''; }
function zipBase64(array $files, array $swap = []): string {
    $tmp = tempnam(sys_get_temp_dir(), 'zip');
    $zip = new ZipArchive();
    $zip->open($tmp, ZipArchive::OVERWRITE);
    foreach ($files as $name => $content) $zip->addFromString($name, $content);
    $zip->close();
    $bytes = str_replace(array_keys($swap), array_values($swap), (string)file_get_contents($tmp));
    unlink($tmp);
    return base64_encode($bytes);
}

try {
    /* Auth and protocol */
    check('wrong token refused', http('{"jsonrpc":"2.0","id":1,"method":"ping"}', 'wrong')[0] === 401);
    [$status, $init] = http('{"jsonrpc":"2.0","id":1,"method":"initialize","params":{}}', $token);
    check('initialize', $status === 200 && $init['result']['serverInfo']['version'] === '1.1.0');
    $tools = array_column(http('{"jsonrpc":"2.0","id":1,"method":"tools/list"}', $token)[1]['result']['tools'], 'name');
    check('ten tools', count($tools) === 10 && in_array('search_files', $tools, true) && in_array('get_file_info', $tools, true));

    /* Server info */
    $info = tool('get_server_info')['structuredContent'];
    check('server info', isset($info['extensions']['zip'], $info['memory_limit'], $info['post_max_size'], $info['tool_limits']) && $info['zip_available'] === true);

    /* Write, read, atomic */
    check('create dir', ok(tool('create_directory', ['path' => 'd'])));
    $write = tool('write_file', ['path' => 'd/a.txt', 'content' => "سلام\r\n"]);
    check('write atomic', ok($write) && $write['structuredContent']['atomic'] === true);
    check('read same bytes', (tool('read_file', ['path' => 'd/a.txt'])['structuredContent']['content'] ?? '') === "سلام\r\n");
    chmod("$site/d/a.txt", 0640);
    tool('write_file', ['path' => 'd/a.txt', 'content' => 'new']);
    check('overwrite keeps mode', (fileperms("$site/d/a.txt") & 0777) === 0640);
    check('no temp files left', glob("$site/d/.mcp-*") === [] && count(scandir("$site/d")) === 3);
    check('missing parent', code(tool('write_file', ['path' => 'no/a.txt', 'content' => 'x'])) === 'parent_missing');

    /* UTF-8 */
    file_put_contents("$site/latin.txt", "caf\xE9");
    file_put_contents("$site/bin.dat", "a\0b");
    @file_put_contents("$site/bad\xE9name.txt", 'x'); /* macOS refuses this name; Linux allows it */
    check('invalid utf-8 refused', code(tool('read_file', ['path' => 'latin.txt'])) === 'not_utf8');
    check('null byte refused', code(tool('read_file', ['path' => 'bin.dat'])) === 'not_utf8');
    check('bad file name still gives json', ok(tool('list_files', ['recursive' => false])));
    @unlink("$site/bad\xE9name.txt");

    /* Path safety */
    check('traversal', code(tool('read_file', ['path' => '../x'])) === 'invalid_path');
    check('absolute', code(tool('read_file', ['path' => '/etc/passwd'])) === 'invalid_path');
    check('protected dir', code(tool('read_file', ['path' => 'mcp/index.php'])) === 'protected_path');
    check('not found', code(tool('read_file', ['path' => 'nope.txt'])) === 'not_found');

    /* Symlinks */
    file_put_contents("$site/target.txt", 'keep');
    symlink('target.txt', "$site/link.txt");
    symlink(sys_get_temp_dir(), "$site/outdir");
    check('delete through link refused', code(tool('delete_file', ['path' => 'link.txt'])) === 'symlink_rejected');
    check('target survives', file_get_contents("$site/target.txt") === 'keep' && is_link("$site/link.txt"));
    check('chmod through link refused', code(tool('set_permissions', ['path' => 'link.txt', 'mode' => '600'])) === 'symlink_rejected');
    check('read through link refused', code(tool('read_file', ['path' => 'link.txt'])) === 'symlink_rejected');
    check('write through link refused', code(tool('write_file', ['path' => 'link.txt', 'content' => 'x'])) === 'symlink_rejected');
    check('write via linked dir refused', code(tool('write_file', ['path' => 'outdir/x.txt', 'content' => 'x'])) === 'symlink_rejected');
    check('zip via link refused', code(tool('extract_zip', ['zip_base64' => zipBase64(['outdir/x.txt' => 'x'])])) === 'symlink_rejected');
    check('zip over link refused', code(tool('extract_zip', ['zip_base64' => zipBase64(['link.txt' => 'x'])])) === 'symlink_rejected');
    check('target unchanged', file_get_contents("$site/target.txt") === 'keep');

    /* Zip */
    $good = tool('extract_zip', ['zip_base64' => zipBase64(['z/sub/b.bin' => "\0\1\2", 'z/c.txt' => 'c']), 'path' => 'd']);
    check('zip extracts', ok($good) && $good['structuredContent']['files'] === 2 && file_get_contents("$site/d/z/sub/b.bin") === "\0\1\2");
    check('unsafe name', code(tool('extract_zip', ['zip_base64' => zipBase64(['safe1.txt' => 'x', '../evil.txt' => 'x'])])) === 'unsafe_zip');
    check('duplicate entries', code(tool('extract_zip', ['zip_base64' => zipBase64(['dup1.txt' => 'a', 'dup2.txt' => 'b'], ['dup2.txt' => 'dup1.txt'])])) === 'unsafe_zip');
    check('file and folder conflict', code(tool('extract_zip', ['zip_base64' => zipBase64(['safe2' => 'a', 'safe2/x.txt' => 'b'])])) === 'unsafe_zip');
    check('zip dir over existing file', code(tool('extract_zip', ['zip_base64' => zipBase64(['safe3.txt' => 'a', 'target.txt/x.txt' => 'b'])])) === 'path_conflict');
    check('zip file over existing dir', code(tool('extract_zip', ['zip_base64' => zipBase64(['safe4.txt' => 'a', 'd' => 'b'])])) === 'path_conflict');
    check('zip into mcp refused', code(tool('extract_zip', ['zip_base64' => zipBase64(['mcp/x.php' => 'x'])])) === 'protected_path');
    check('bad zips wrote nothing', glob("$site/safe*") === [] && !file_exists("$site/dup1.txt") && !file_exists(dirname($site) . '/evil.txt'));

    /* Listing pages */
    mkdir("$site/many");
    for ($i = 0; $i < 25; $i++) file_put_contents(sprintf('%s/many/f%02d.log', $site, $i), 'x');
    $first = tool('list_files', ['path' => 'many', 'limit' => 10])['structuredContent'];
    $second = tool('list_files', ['path' => 'many', 'limit' => 10, 'offset' => 20])['structuredContent'];
    check('first page', count($first['items']) === 10 && $first['has_more'] === true && $first['next_offset'] === 10);
    check('last page', count($second['items']) === 5 && $second['has_more'] === false && $second['next_offset'] === null);
    check('bad limit', code(tool('list_files', ['limit' => 0])) === 'invalid_argument');
    $shallow = array_column(tool('list_files', ['recursive' => false])['structuredContent']['items'], 'path');
    check('not recursive', in_array('many', $shallow, true) && !in_array('many/f00.log', $shallow, true));
    check('list hides mcp and links', !array_intersect(['mcp', 'link.txt', 'outdir'], $shallow));

    /* Search and info */
    $found = tool('search_files', ['extension' => '.LOG', 'limit' => 5])['structuredContent'];
    check('search extension', count($found['items']) === 5 && $found['truncated'] === true);
    check('search name', count(tool('search_files', ['name' => 'F03'])['structuredContent']['items']) === 1);
    check('search needs a filter', code(tool('search_files')) === 'invalid_argument');
    $file = tool('get_file_info', ['path' => 'target.txt'])['structuredContent'];
    check('file info', $file['size'] === 4 && $file['type'] === 'file' && !empty($file['modified']) && array_key_exists('mime', $file));
    check('directory info', tool('get_file_info', ['path' => 'many'])['structuredContent']['type'] === 'directory');
    check('info on link refused', code(tool('get_file_info', ['path' => 'link.txt'])) === 'symlink_rejected');

    /* Errors */
    $error = tool('delete_file', ['path' => 'many']);
    check('hint in text', code($error) === 'not_a_file' && str_contains($error['content'][0]['text'], 'file manager'));
    check('delete real file', ok(tool('delete_file', ['path' => 'target.txt'])) && !file_exists("$site/target.txt"));
    check('unknown tool', code(tool('nope')) === 'unknown_tool');
} finally {
    proc_terminate($server);
    exec('rm -rf ' . escapeshellarg($site));
}

echo 'PHP ' . PHP_VERSION . ": $checks checks, $failures failed\n";
exit($failures === 0 ? 0 : 1);
