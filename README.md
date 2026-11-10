# host-mcp

A single-file PHP [MCP](https://modelcontextprotocol.io) server that lets an AI agent safely manage a website's files, even on cheap shared hosting with no SSH.

Upload one file and add a token. Your agent can then search, read, patch, deploy, check and roll back the site.

## ✨ Features

- **One file, no dependencies.** `mcp/index.php` needs plain PHP 8.1+. There is no Composer, database, Node or background process.
- **Works on shared hosting.** It runs on cPanel, DirectAdmin, Plesk and anything else that runs PHP. It never needs a shell.
- **Safe editing.** Search inside files, read line ranges, and patch exact text with a dry-run diff. Hash checks stop it overwriting changes made since the agent read the file.
- **Undo for everything.** Every write, patch, move, delete and zip deploy is backed up first. `restore_backup` undoes it, and a restore can itself be undone.
- **No broken PHP.** `.php` files are syntax-checked before they are saved, without running them.
- **Deploy checks.** `check_url` loads a page after a change and reports its status and any PHP errors.
- **Hardened.** The token lives outside the website. The server has failed-login lockout, rate limits, an audit log, an IP allow-list, a read-only mode and symlink rejection. Its own folder is protected.
- **Status page.** Open the URL in a browser to see the version, PHP version and extensions.

## 🚀 Quick start

**1. Upload** `mcp/index.php` so it is at `public_html/mcp/index.php`.

**2. Configure.** Create `~/.host-mcp.ini` in your home folder, outside `public_html`:

```bash
openssl rand -hex 32
```

```ini
[host-mcp]
token = "paste-the-output-here"
```

```bash
chmod 600 ~/.host-mcp.ini
```

No SSH? Create the file in your host's file manager, one folder above `public_html`. All options are in [host-mcp.example.ini](host-mcp.example.ini).

**3. Check it.** Open `https://YOUR-DOMAIN/mcp/` in a browser. You should see `"status": "online"` and `"configured": true`.

## 🔌 Connect a client

**Claude Code:**

```bash
claude mcp add --transport http my-site https://YOUR-DOMAIN/mcp/ --header "Authorization: Bearer YOUR_TOKEN"
```

**Any client with an `mcpServers` config**, such as `.mcp.json`:

```json
{
  "mcpServers": {
    "my-site": {
      "type": "http",
      "url": "https://YOUR-DOMAIN/mcp/",
      "headers": { "Authorization": "Bearer ${HOST_MCP_TOKEN}" }
    }
  }
}
```

The transport is Streamable HTTP, protocol `2025-03-26`. A client that supports only stdio needs a local HTTP bridge.

Then ask your agent something like *"Look at my site with host-mcp and fix the broken contact form."* The [how-to guide](docs/HOW-TO.md) has the full workflow.

## 🛠️ Tools

| Tool | What it does |
|---|---|
| `get_server_info` | PHP version, extensions, limits, disk, backups, recent activity and warnings |
| `list_files` | List files and folders, paged, up to 8 levels deep |
| `search_files` | Find files by name or extension |
| `search_content` | Search inside files, like grep, with optional regex and context lines |
| `get_file_info` | Size, MIME type, permissions, date and sha256, including for binary files |
| `read_file` | Read a whole text file up to 1 MB, with its sha256 |
| `read_lines` | Read a line range from a file up to 50 MB |
| `tail_file` | Read the last lines of a log |
| `check_php_syntax` | Check a file or a code snippet for PHP syntax errors |
| `check_url` | Load a page on this site and report its status, timing and PHP errors |
| `write_file` | Create or replace a text file, with an optional dry-run diff |
| `patch_file` | Replace exact text in a file, with a diff and an optional dry run |
| `create_directory` | Create a folder, with its parents if needed |
| `move_file` | Move or rename a file or folder |
| `delete_file` | Delete a file (backed up first) |
| `delete_directory` | Delete a folder, empty or recursive (backed up first) |
| `set_permissions` | `600`, `640` or `644` for files; `700`, `750` or `755` for folders |
| `extract_zip` | Upload a zip, for binary files or many files at once. It is checked first and rolled back if writing fails |
| `list_backups` | List backups, filtered by path if you want |
| `restore_backup` | Undo a change, with an optional dry run |
| `get_audit_log` | Recent tool calls: time, tool, path, result and backup id |

With `read_only = true`, only the read tools are offered.

Full arguments, results and error codes are in the [tool reference](docs/HOW-TO.md#tool-reference).

## 📊 Status page

A `GET` to the endpoint returns JSON. It never changes anything.

**Without a token**, it shows only harmless facts: name, version, protocol, whether a token is configured, PHP version, SAPI, OS family, extensions, features and limits. Set `public_status = false` to show only `{"status": "online"}`.

```bash
curl https://YOUR-DOMAIN/mcp/
```

**With a token**, it adds the managed root, `php.ini` limits, disk space, config summary, backup totals, activity in the last 24 hours and configuration warnings:

```bash
curl -H "Authorization: Bearer YOUR_TOKEN" https://YOUR-DOMAIN/mcp/
```

The token, environment variables, `phpinfo()` output and file contents are never shown.

## 🔒 Security

**The token gives control of the website.** Whoever holds it can write PHP that the server will run, and can read config files that hold database passwords. Treat it like your hosting password.

Built in:

- The token is read from a config file outside the website. It is compared in constant time and must be at least 24 characters
- Token rotation: the old token keeps working until a date you set
- After 10 wrong tokens, an IP address is locked out for 15 minutes. All requests are limited to 300 per minute per IP
- Optional IP allow-list and read-only mode
- All paths stay inside the managed root: `..`, absolute paths and symlinks are rejected
- The server's own folder, its data folder and its config file can't be read or changed through the tools
- Every change is backed up first. Backups are stored outside the website, with `0600` permissions
- An audit log of every call, which never includes file contents
- PHP syntax is checked before `.php` files are written, and permission changes that would lock folders are refused
- Zip uploads are checked in full before anything is written, and rolled back if writing fails
- `Origin` checks, security headers, and request-size limits
- No shell or SQL tool

Your part:

- **Use HTTPS only.** The status page warns you if a request did not use it.
- Set `root` to the smallest folder that works, and keep secrets outside it.
- Add `allowed_ips` if your agent has a fixed address.
- Rotate the token if it may have leaked. Remove `mcp/` when you no longer need it.
- Backups are a safety net, not a replacement for your host's own backups.

## ⚙️ Configuration

Settings are read from the first of these that exists:

1. The file named in the `HOST_MCP_CONFIG` environment variable, or in the `MCP_CONFIG_FILE` constant
2. `.host-mcp.ini` in the folder above the website
3. `~/.host-mcp.ini`
4. `mcp/config.php`, which returns an array with the same keys. Use it when you can't write outside `public_html`. PHP runs it instead of serving it, and the tools can't reach it.

If none exist, the `MCP_TOKEN` constant in `index.php` is used, and the status page warns about it.

| Key | Default | Meaning |
|---|---|---|
| `token` | none | Required, at least 24 characters |
| `previous_token`, `previous_token_expires` | none | The old token, and the last day it works (`YYYY-MM-DD`) |
| `root` | `.` | Managed folder. Relative to the folder above `mcp/`. `~` is home |
| `data_dir` | `~/.host-mcp` | Backups, audit log and counters. Falls back to `mcp/data` |
| `read_only` | `false` | Hide and refuse all write tools |
| `backups` | `true` | Back up before every change |
| `backup_keep_days` | `14` | Delete backups older than this |
| `backup_max_mb` | `200` | Total backup size; the oldest are removed first |
| `audit` | `all` | `all`, `writes` or `off` |
| `max_failed_auth` | `10` | Wrong tokens before a 15-minute lockout; `0` is off |
| `rate_limit` | `300` | Requests per minute per IP; `0` is off |
| `allowed_ips` | empty | Comma-separated IPs or CIDR ranges |
| `public_status` | `true` | Show PHP and feature details on a tokenless `GET` |

When backups are on but no data folder is writable, write tools are refused rather than run without a backup.

## 📋 Requirements

- PHP 8.1 or newer, on Apache, LiteSpeed, nginx or anything else that runs PHP
- HTTPS
- Optional: `zip` for `extract_zip`, `fileinfo` for MIME types, and `curl` or `allow_url_fopen` for `check_url`

## 🧪 Testing with curl

```bash
# Initialize
curl -sS https://YOUR-DOMAIN/mcp/ \
  -H "Authorization: Bearer YOUR_TOKEN" -H "Content-Type: application/json" \
  -d '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-03-26"}}'

# List tools
curl -sS https://YOUR-DOMAIN/mcp/ \
  -H "Authorization: Bearer YOUR_TOKEN" -H "Content-Type: application/json" \
  -d '{"jsonrpc":"2.0","id":2,"method":"tools/list"}'

# Search inside PHP files
curl -sS https://YOUR-DOMAIN/mcp/ \
  -H "Authorization: Bearer YOUR_TOKEN" -H "Content-Type: application/json" \
  -d '{"jsonrpc":"2.0","id":3,"method":"tools/call","params":{"name":"search_content","arguments":{"query":"mail(","extension":"php"}}}'
```

A failed tool call still returns HTTP 200, with `isError: true` and a `structuredContent.error` holding `code`, `message` and `hint`.

## 🤝 Use cases

- Let an agent maintain a WordPress, Laravel or plain PHP site on shared hosting
- Fix a bug on a live site safely: find it, patch it, check the page, and roll back if needed
- Deploy a built site or theme as a zip, rolled back if anything fails
- Read error logs and diagnose a broken page without SSH
- Give a read-only agent access for audits and reviews

## ⬆️ Upgrading from 1.x

1. Move your token from `index.php` into `~/.host-mcp.ini`. The old constant still works, but the status page warns about it.
2. If you changed `$ROOT`, set `root` in the config instead.
3. Replace `mcp/index.php`.

Existing tools keep their names and arguments. What changed:

- `delete_file`, `write_file` and `extract_zip` now make backups. They are refused if no data folder is writable, unless `backups = false`.
- `.php` writes with syntax errors are refused. Pass `skip_syntax_check: true` to override.
- `set_permissions` allows only file modes on files and only folder modes on folders, and refuses the root.
- `search_files` still matches names only. Use `search_content` for text inside files.
- Unexpected errors return `internal_error` without PHP's message. The details go to the audit log.

## 🧑‍💻 Development

```bash
php tests/run.php
```

The tests start the real server under PHP's built-in web server in a temporary folder. GitHub Actions runs them on PHP 8.1, 8.2 and 8.4.
