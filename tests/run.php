<?php
declare(strict_types=1);

/* Runs the real server under PHP's built-in web server in a temp folder. Usage: php tests/run.php */

$base = sys_get_temp_dir() . '/host-mcp-test-' . bin2hex(random_bytes(4));
$site = "$base/site";
$data = "$base/data";
mkdir("$site/mcp", 0755, true);
copy(__DIR__ . '/../mcp/index.php', "$site/mcp/index.php");
$token = str_repeat('t', 40);
file_put_contents("$base/host-mcp.ini", "[host-mcp]\ntoken = \"$token\"\ndata_dir = \"$data\"\nmax_failed_auth = 3\nrate_limit = 0\n");

$port = 8940 + random_int(0, 50);
$env = array_merge(getenv(), ['HOST_MCP_CONFIG' => "$base/host-mcp.ini", 'PHP_CLI_SERVER_WORKERS' => '4']);
$server = proc_open([PHP_BINARY, '-d', 'display_errors=1', '-S', "127.0.0.1:$port", '-t', $site],
    [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, null, $env);
usleep(900000);

$failures = 0;
$checks = 0;
function check(string $label, bool $ok): void {
    global $failures, $checks;
    $checks++;
    if (!$ok) { $failures++; echo "FAIL: $label\n"; }
}
function http(string $body, ?string $bearer, string $method = 'POST', string $accept = 'application/json'): array {
    global $port;
    $headers = "Content-Type: application/json\r\nAccept: $accept\r\n" . ($bearer !== null ? "Authorization: Bearer $bearer\r\n" : '');
    $context = stream_context_create(['http' => ['method' => $method, 'ignore_errors' => true, 'timeout' => 30,
        'header' => $headers, 'content' => $body]]);
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
function out(array $result): array { return $result['structuredContent'] ?? []; }
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
    check('initialize', $status === 200 && $init['result']['serverInfo']['version'] === '2.0.0');
    $tools = array_column(http('{"jsonrpc":"2.0","id":1,"method":"tools/list"}', $token)[1]['result']['tools'], 'name');
    check('21 tools', count($tools) === 21 && in_array('patch_file', $tools, true) && in_array('restore_backup', $tools, true));

    /* GET status */
    [$status, $public] = http('', null, 'GET');
    check('public status', $status === 200 && $public['status'] === 'online' && $public['version'] === '2.0.0' && $public['configured'] === true);
    check('public status has php', $public['php']['version'] === PHP_VERSION && isset($public['extensions']['zip']));
    check('public status hides private data', !isset($public['managed_root']) && !isset($public['config']) && !str_contains(json_encode($public), $site));
    [$status, $full] = http('', $token, 'GET');
    check('authenticated status', $status === 200 && $full['managed_root'] === realpath($site) && is_array($full['warnings']) && $full['backups']['enabled'] === true);
    check('status never shows the token', !str_contains(json_encode($full), $token));
    check('GET with wrong token', http('', 'wrong-token', 'GET')[0] === 401);
    check('SSE stream refused', http('', null, 'GET', 'text/event-stream')[0] === 405);
    check('PUT refused', http('', $token, 'PUT')[0] === 405);

    /* Server info */
    $info = out(tool('get_server_info'));
    check('server info', isset($info['extensions']['zip'], $info['php_ini']['memory_limit'], $info['limits']) && $info['features']['zip_upload'] === true);

    /* Write, read, atomic */
    check('create dir', ok(tool('create_directory', ['path' => 'd'])));
    check('create nested dirs', ok(tool('create_directory', ['path' => 'n/e/s/t', 'parents' => true])) && is_dir("$site/n/e/s/t"));
    $write = tool('write_file', ['path' => 'd/a.txt', 'content' => "سلام\r\n"]);
    check('write atomic', ok($write) && out($write)['atomic'] === true && out($write)['created'] === true);
    $read = out(tool('read_file', ['path' => 'd/a.txt']));
    check('read same bytes', ($read['content'] ?? '') === "سلام\r\n" && $read['line_ending'] === 'crlf' && $read['sha256'] === hash('sha256', "سلام\r\n"));
    chmod("$site/d/a.txt", 0640);
    $write = tool('write_file', ['path' => 'd/a.txt', 'content' => 'new']);
    check('overwrite makes backup', ok($write) && preg_match('/^\d{8}-\d{6}-[0-9a-f]{6}$/', (string)out($write)['backup_id']) === 1);
    check('overwrite keeps mode', (fileperms("$site/d/a.txt") & 0777) === 0640);
    check('no temp files left', glob("$site/d/.mcp-*") === [] && count(scandir("$site/d")) === 3);
    check('missing parent', code(tool('write_file', ['path' => 'no/a.txt', 'content' => 'x'])) === 'parent_missing');
    check('stale hash refused', code(tool('write_file', ['path' => 'd/a.txt', 'content' => 'x', 'expected_sha256' => $read['sha256']])) === 'changed_since_read');
    check('fresh hash accepted', ok(tool('write_file', ['path' => 'd/a.txt', 'content' => 'newer', 'expected_sha256' => hash('sha256', 'new')])));
    $dry = out(tool('write_file', ['path' => 'd/a.txt', 'content' => 'other', 'dry_run' => true]));
    check('write dry run', $dry['dry_run'] === true && str_contains($dry['diff'], "-newer\n+other") && file_get_contents("$site/d/a.txt") === 'newer');
    check('wrong argument type', code(tool('read_file', ['path' => ['x']])) === 'invalid_argument');

    /* PHP syntax */
    check('bad php refused', code(tool('write_file', ['path' => 'bad.php', 'content' => '<?php echo "x" ?? ;'])) === 'php_syntax_error' && !file_exists("$site/bad.php"));
    check('bad php allowed when skipped', ok(tool('write_file', ['path' => 'skip.php', 'content' => '<?php (', 'skip_syntax_check' => true])));
    check('syntax check code ok', out(tool('check_php_syntax', ['code' => '<?php echo 1;']))['valid'] === true);
    $syntax = out(tool('check_php_syntax', ['path' => 'skip.php']));
    check('syntax check file error', $syntax['valid'] === false && $syntax['error']['line'] === 1);

    /* Patch */
    file_put_contents("$site/p.php", "<?php\n\$a = 'foo';\n\$b = 'foo';\necho \$a;\n");
    $hash = hash_file('sha256', "$site/p.php");
    check('ambiguous patch', code(tool('patch_file', ['path' => 'p.php', 'edits' => [['old_text' => "'foo'", 'new_text' => "'bar'"]]])) === 'ambiguous_match');
    check('missing patch text', code(tool('patch_file', ['path' => 'p.php', 'edits' => [['old_text' => 'nope', 'new_text' => 'x']]])) === 'no_match');
    check('patch breaking php refused', code(tool('patch_file', ['path' => 'p.php', 'edits' => [['old_text' => 'echo $a;', 'new_text' => 'echo $a']]])) === 'php_syntax_error');
    $dry = out(tool('patch_file', ['path' => 'p.php', 'dry_run' => true, 'edits' => [['old_text' => "\$a = 'foo'", 'new_text' => "\$a = 'bar'"]]]));
    check('patch dry run', $dry['changed'] === true && str_contains($dry['diff'], "-\$a = 'foo';\n+\$a = 'bar';") && hash_file('sha256', "$site/p.php") === $hash);
    $patch = tool('patch_file', ['path' => 'p.php', 'expected_sha256' => $hash, 'edits' => [
        ['old_text' => "\$a = 'foo'", 'new_text' => "\$a = 'bar'"],
        ['old_text' => 'echo $a;', 'new_text' => 'echo $a, $b;']]]);
    check('patch applies', ok($patch) && out($patch)['replacements'] === 2 && str_contains(file_get_contents("$site/p.php"), "\$a = 'bar';\n\$b = 'foo';\necho \$a, \$b;"));
    check('patch replace_all', ok(tool('patch_file', ['path' => 'p.php', 'edits' => [['old_text' => "'", 'new_text' => '"', 'replace_all' => true]]])));
    file_put_contents("$site/crlf.txt", "one\r\ntwo\r\nthree\r\n");
    check('patch keeps crlf', ok(tool('patch_file', ['path' => 'crlf.txt', 'edits' => [['old_text' => "one\ntwo", 'new_text' => "uno\ndos"]]])) && file_get_contents("$site/crlf.txt") === "uno\r\ndos\r\nthree\r\n");

    /* Restore */
    $restoreId = out($patch)['backup_id'];
    $plan = out(tool('restore_backup', ['id' => $restoreId, 'dry_run' => true]));
    check('restore dry run', $plan['actions'][0] === ['action' => 'restore_file', 'path' => 'p.php']);
    $restore = tool('restore_backup', ['id' => $restoreId]);
    check('restore patch', ok($restore) && hash_file('sha256', "$site/p.php") === $hash && !empty(out($restore)['backup_id']));
    check('undo restore', ok(tool('restore_backup', ['id' => out($restore)['backup_id']])) && str_contains(file_get_contents("$site/p.php"), 'echo $a, $b;'));
    check('bad backup id', code(tool('restore_backup', ['id' => '../x'])) === 'invalid_argument');

    /* Search and lines */
    $hits = out(tool('search_content', ['query' => 'ECHO', 'extension' => 'php', 'context' => 1]));
    check('search content', count($hits['matches']) === 1 && $hits['matches'][0]['path'] === 'p.php' && $hits['matches'][0]['line'] === 4 && count($hits['matches'][0]['before']) === 1);
    check('search regex', count(out(tool('search_content', ['query' => '\$[ab] =', 'regex' => true]))['matches']) === 2);
    check('search bad regex', code(tool('search_content', ['query' => '(', 'regex' => true])) === 'invalid_argument');
    check('search case sensitive', out(tool('search_content', ['query' => 'ECHO', 'case_sensitive' => true]))['matches'] === []);
    file_put_contents("$site/log.txt", implode("\n", array_map(fn($i) => "line $i", range(1, 500))) . "\n");
    $lines = out(tool('read_lines', ['path' => 'log.txt', 'start_line' => 10, 'line_count' => 3]));
    check('read lines', $lines['lines'] === ['line 10', 'line 11', 'line 12'] && $lines['total_lines'] === 500 && $lines['end_line'] === 12);
    check('read lines past end', code(tool('read_lines', ['path' => 'log.txt', 'start_line' => 900])) === 'invalid_argument');
    $tail = out(tool('tail_file', ['path' => 'log.txt', 'lines' => 2]));
    check('tail file', $tail['lines'] === ['line 499', 'line 500'] && $tail['first_line'] === 499 && $tail['total_lines'] === 500);

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
    mkdir("$site/haslink");
    symlink('../target.txt', "$site/haslink/l");
    check('recursive delete refuses links', code(tool('delete_directory', ['path' => 'haslink', 'recursive' => true])) === 'symlink_rejected' && is_link("$site/haslink/l"));
    check('move into link refused', code(tool('move_file', ['from' => 'target.txt', 'to' => 'outdir/t.txt'])) === 'symlink_rejected');

    /* Permissions */
    check('chmod root refused', code(tool('set_permissions', ['path' => '.', 'mode' => '755'])) === 'root_protected');
    check('dir 644 refused', code(tool('set_permissions', ['path' => 'd', 'mode' => '644'])) === 'invalid_argument');
    check('file 755 refused', code(tool('set_permissions', ['path' => 'target.txt', 'mode' => '755'])) === 'invalid_argument');
    $perm = out(tool('set_permissions', ['path' => 'd', 'mode' => '750']));
    check('dir 750', $perm['changed'] === true && $perm['previous_mode'] === '755' && (fileperms("$site/d") & 0777) === 0750);
    chmod("$site/d", 0755);

    /* Move */
    file_put_contents("$site/m1.txt", 'one');
    file_put_contents("$site/m2.txt", 'two');
    check('move conflict', code(tool('move_file', ['from' => 'm1.txt', 'to' => 'm2.txt'])) === 'path_conflict');
    $move = tool('move_file', ['from' => 'm1.txt', 'to' => 'm2.txt', 'overwrite' => true]);
    check('move overwrite', ok($move) && !file_exists("$site/m1.txt") && file_get_contents("$site/m2.txt") === 'one');
    check('undo move', ok(tool('restore_backup', ['id' => out($move)['backup_id']])) && file_get_contents("$site/m1.txt") === 'one' && file_get_contents("$site/m2.txt") === 'two');
    check('move folder', ok(tool('move_file', ['from' => 'n', 'to' => 'n2'])) && is_dir("$site/n2/e/s/t"));
    check('move folder into itself', code(tool('move_file', ['from' => 'n2', 'to' => 'n2/e/x'])) === 'invalid_argument');
    check('move root refused', code(tool('move_file', ['from' => '.', 'to' => 'x'])) === 'root_protected');

    /* Delete and restore */
    $del = tool('delete_file', ['path' => 'm1.txt']);
    check('delete file', ok($del) && !file_exists("$site/m1.txt"));
    check('undo delete', ok(tool('restore_backup', ['id' => out($del)['backup_id']])) && file_get_contents("$site/m1.txt") === 'one');
    mkdir("$site/tree/a/b", 0755, true);
    file_put_contents("$site/tree/a/b/deep.txt", 'deep');
    file_put_contents("$site/tree/top.txt", 'top');
    check('non-empty dir refused', code(tool('delete_directory', ['path' => 'tree'])) === 'not_empty');
    check('delete root refused', code(tool('delete_directory', ['path' => '.', 'recursive' => true])) === 'root_protected');
    $delTree = tool('delete_directory', ['path' => 'tree', 'recursive' => true]);
    check('recursive delete', ok($delTree) && !file_exists("$site/tree") && out($delTree)['files'] === 2);
    check('undo recursive delete', ok(tool('restore_backup', ['id' => out($delTree)['backup_id']])) && file_get_contents("$site/tree/a/b/deep.txt") === 'deep' && is_dir("$site/tree/a"));
    check('delete empty dir', ok(tool('delete_directory', ['path' => 'n2/e/s/t'])) && !is_dir("$site/n2/e/s/t"));

    /* Zip */
    $good = tool('extract_zip', ['zip_base64' => zipBase64(['z/sub/b.bin' => "\0\1\2", 'z/c.txt' => 'c']), 'path' => 'd']);
    check('zip extracts', ok($good) && out($good)['files'] === 2 && file_get_contents("$site/d/z/sub/b.bin") === "\0\1\2");
    check('unsafe name', code(tool('extract_zip', ['zip_base64' => zipBase64(['safe1.txt' => 'x', '../evil.txt' => 'x'])])) === 'unsafe_zip');
    check('duplicate entries', code(tool('extract_zip', ['zip_base64' => zipBase64(['dup1.txt' => 'a', 'dup2.txt' => 'b'], ['dup2.txt' => 'dup1.txt'])])) === 'unsafe_zip');
    check('file and folder conflict', code(tool('extract_zip', ['zip_base64' => zipBase64(['safe2' => 'a', 'safe2/x.txt' => 'b'])])) === 'unsafe_zip');
    check('zip dir over existing file', code(tool('extract_zip', ['zip_base64' => zipBase64(['safe3.txt' => 'a', 'target.txt/x.txt' => 'b'])])) === 'path_conflict');
    check('zip file over existing dir', code(tool('extract_zip', ['zip_base64' => zipBase64(['safe4.txt' => 'a', 'd' => 'b'])])) === 'path_conflict');
    check('zip into mcp refused', code(tool('extract_zip', ['zip_base64' => zipBase64(['mcp/x.php' => 'x'])])) === 'protected_path');
    check('zip bad php refused', code(tool('extract_zip', ['zip_base64' => zipBase64(['safe5.txt' => 'a', 'x.php' => '<?php if (']), 'path' => 'd'])) === 'php_syntax_error');
    check('bad zips wrote nothing', glob("$site/safe*") === [] && glob("$site/d/safe*") === [] && !file_exists("$site/dup1.txt") && !file_exists(dirname($site) . '/evil.txt'));
    $deploy = ['d/z/c.txt' => 'c2', 'new/deep/n.txt' => 'n'];
    $dryZip = out(tool('extract_zip', ['zip_base64' => zipBase64($deploy), 'dry_run' => true]));
    check('zip dry run', $dryZip['overwrite'] === ['d/z/c.txt'] && in_array('new/deep', $dryZip['new_directories'], true) && !is_dir("$site/new"));
    $zipRun = tool('extract_zip', ['zip_base64' => zipBase64($deploy)]);
    check('zip overwrite', ok($zipRun) && out($zipRun)['overwritten'] === 1 && file_get_contents("$site/d/z/c.txt") === 'c2');
    check('undo zip', ok(tool('restore_backup', ['id' => out($zipRun)['backup_id']])) && file_get_contents("$site/d/z/c.txt") === 'c' && !file_exists("$site/new"));

    /* Listing pages */
    mkdir("$site/many");
    for ($i = 0; $i < 25; $i++) file_put_contents(sprintf('%s/many/f%02d.log', $site, $i), 'x');
    $first = out(tool('list_files', ['path' => 'many', 'limit' => 10]));
    $second = out(tool('list_files', ['path' => 'many', 'limit' => 10, 'offset' => 20]));
    check('first page', count($first['items']) === 10 && $first['has_more'] === true && $first['next_offset'] === 10);
    check('last page', count($second['items']) === 5 && $second['has_more'] === false && $second['next_offset'] === null);
    check('bad limit', code(tool('list_files', ['limit' => 0])) === 'invalid_argument');
    $shallow = array_column(out(tool('list_files', ['recursive' => false]))['items'], 'path');
    check('not recursive', in_array('many', $shallow, true) && !in_array('many/f00.log', $shallow, true));
    check('list hides mcp and links', !array_intersect(['mcp', 'link.txt', 'outdir'], $shallow));

    /* Search and info */
    $found = out(tool('search_files', ['extension' => '.LOG', 'limit' => 5]));
    check('search extension', count($found['items']) === 5 && $found['truncated'] === true);
    check('search name', count(out(tool('search_files', ['name' => 'F03']))['items']) === 1);
    check('search needs a filter', code(tool('search_files')) === 'invalid_argument');
    $file = out(tool('get_file_info', ['path' => 'target.txt']));
    check('file info', $file['size'] === 4 && $file['type'] === 'file' && $file['sha256'] === hash('sha256', 'keep') && array_key_exists('mime', $file));
    check('directory info', out(tool('get_file_info', ['path' => 'many']))['type'] === 'directory');
    check('info on link refused', code(tool('get_file_info', ['path' => 'link.txt'])) === 'symlink_rejected');

    /* Deployment checks */
    file_put_contents("$site/page.php", '<?php echo "hello world";');
    file_put_contents("$site/warn.php", '<?php echo $nope;');
    $page = out(tool('check_url', ['path' => '/page.php', 'contains' => 'hello']));
    check('check url ok', ($page['status'] ?? 0) === 200 && $page['ok'] === true && $page['contains'] === true);
    $warn = out(tool('check_url', ['path' => '/warn.php']));
    check('check url finds php warning', $warn['ok'] === false && count($warn['php_errors']) === 1 && str_contains($warn['php_errors'][0], 'Warning'));
    check('check url 404', out(tool('check_url', ['path' => '/missing.php', 'expect_status' => 404]))['ok'] === true);
    check('check url other host refused', code(tool('check_url', ['path' => '//evil.example/'])) === 'invalid_argument');

    /* Backups and audit */
    $list = out(tool('list_backups', ['path' => 'p.php']));
    check('list backups by path', count($list['backups']) >= 3 && $list['backups'][0]['paths'] === ['p.php']);
    $log = out(tool('get_audit_log', ['limit' => 500]))['entries'];
    check('audit log', count($log) > 20 && $log[0]['tool'] === 'list_backups' && isset($log[0]['ms']));
    check('audit has no file contents', !str_contains(json_encode($log), 'hello world') && !str_contains(json_encode($log), "سلام"));
    check('audit logs failed login', (bool)array_filter($log, fn($e) => ($e['event'] ?? '') === 'auth_failed'));
    check('data folder is private', (fileperms("$data/audit.log") & 0777) === 0600);

    /* Errors */
    $error = tool('delete_file', ['path' => 'many']);
    check('hint in text', code($error) === 'not_a_file' && str_contains($error['content'][0]['text'], 'delete_directory'));
    check('unknown tool', code(tool('nope')) === 'unknown_tool');

    /* Failed-login limit: two failures so far, the limit is three */
    http('{"jsonrpc":"2.0","id":1,"method":"ping"}', 'wrong-again');
    check('locked out after failed logins', http('{"jsonrpc":"2.0","id":1,"method":"ping"}', $token)[0] === 429);
} finally {
    proc_terminate($server);
    exec('rm -rf ' . escapeshellarg($base));
}

echo 'PHP ' . PHP_VERSION . ": $checks checks, $failures failed\n";
exit($failures === 0 ? 0 : 1);
