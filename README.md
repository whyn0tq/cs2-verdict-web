<div align="center">

<h1 align="center">Verdict</h1>

<p align="center">
  <img src="https://img.shields.io/badge/CS2-0d1117?style=for-the-badge" alt="CS2">
  <img src="https://img.shields.io/badge/WEB_PANEL-e5383b?style=for-the-badge" alt="Web panel">
  <img src="https://img.shields.io/badge/PHP_5.2%2B-0d1117?style=for-the-badge" alt="PHP 5.2+">
  <img src="https://img.shields.io/badge/MYSQL_%2F_MARIADB-e5383b?style=for-the-badge" alt="MySQL / MariaDB">
  <img src="https://img.shields.io/badge/EN_%2F_RU-0d1117?style=for-the-badge" alt="EN / RU">
  <img src="https://img.shields.io/badge/LIGHT_%26_DARK-e5383b?style=for-the-badge" alt="Light &amp; Dark">
</p>

<p align="center"><b>English</b> · <a href="README.ru.md">Русский</a></p>
</div>

Web panel for the [Verdict](https://github.com/whyn0tq/cs2-verdict) plugin: bans, mutes and admins for CS2 servers from the browser. Runs on ordinary hosting with PHP and MySQL.

- **Material Admin schema.** Tables `sb_bans`, `sb_comms`, `sb_admins`, `sb_srvgroups`, `sb_servers`. An existing Material Admin or SourceBans database can be connected as is, old admin logins and passwords keep working.
- **One database with the plugin.** The panel and the plugin talk only through it — no ports to open on the game server.
- **Own data stays apart.** Panel permissions, 2FA, action log and live data live in separate `vd_*` tables and do not touch other panels or plugins.

## Features

| | |
|---|---|
| Punishments | ban by SteamID and IP, mute, gag, silence: issue, edit, remove, search |
| Admins | flags, immunity, groups, expiry, per-server binding, group limits on ban and mute duration |
| Servers | status, map, players, RCON console, several servers on one panel |
| Live | scoreboard and chat, kick, team change, slay, chat messages (plugin required) |
| Players | Steam avatars and profiles, search by SteamID, profile link or short name |
| Security | TOTP two-factor login, admin action log |
| Other | install wizard, ru / en languages, light and dark theme |

## Requirements

| What | Details |
|---|---|
| PHP | 5.2+, 7.4+ recommended (tested on 8.3) |
| Database | MySQL 5.5.3+ / MariaDB 10+ |
| Web server | Apache with `.htaccess` support |
| Game server | [Verdict plugin](https://github.com/whyn0tq/cs2-verdict) with remote access to the same database |

## Installation

1. Download the panel (*Code* → *Download ZIP* or a release) and upload all files, including `.htaccess`, to your hosting by FTP — to the site root or a subfolder.
2. Set permissions `777` on the `data` folder.
3. Prepare a MySQL database or use an existing one from Material Admin or admin_system.
4. Open the site. The install wizard checks PHP and asks for the install code from `data/install-code.php`.
5. Enter the database details and table prefix (`sb`, the same as `database_prefix` in the plugin's `core.ini`), create the owner account and add the first server.
6. At the end the wizard shows ready-made blocks for `addons/configs/databases.cfg` and `addons/configs/verdict/core.ini`. Copy them to the game server and restart it.

To run the wizard again, delete `data/config.php`.

### Game server access to the database

Enable remote MySQL access in the hosting panel (cPanel — *Remote MySQL*), add the game server IP, and put the external MySQL address instead of `localhost` into `host` of `databases.cfg`. The database user needs full rights: the plugin creates missing tables itself.

## Plugin link

- With `server_id` set in `core.ini`, the plugin writes online, score and chat to the database every few seconds — the server page gets the live scoreboard and chat.
- Kick, team change, slay and chat messages from the panel go through a queue and run within a second.
- Bans, mutes and admins issued in the panel are picked up by the plugin without a restart.
- Without the plugin the panel still shows status and players over A2S, and RCON works if the password is set.

Admins from cs2-admin_system are imported by the plugin: `vd_import as_` in the game server console.

## Update

Back up `data`, upload the new files over the old ones, keep `data`, open the panel — new tables and fields are created automatically.

## Troubleshooting

| Problem | Fix |
|---|---|
| No write access to `data` | set permissions `777` |
| Error 500 or blank page | check the PHP version and the hosting error log |
| Wizard cannot connect | take host, database and user from the hosting; the name often has an account prefix (`user_verdict`) |
| Plugin cannot connect | remote MySQL access is off, or `localhost` is set in `databases.cfg` |
| Server shows offline, plugin works | hosting blocks outgoing UDP — set `server_id` in `core.ini` |
| Files from `data` or `includes` open in the browser | hosting ignores `.htaccess` — contact support |

Turn on HTTPS and two-factor login, and never publish `data/config.php` or the install code.

## Author

**teesa (whyn0tq)** · [GitHub](https://github.com/whyn0tq)
