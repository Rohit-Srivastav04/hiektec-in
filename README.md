# hiektec.in

Private backup of the live **SA Browser Admin** site hosted on Hostinger.

- **Public live site:** https://hiektec.in (stays on Hostinger — PHP + Supabase, not GitHub Pages)
- **This repo:** private source backup only

## Missing from API backup

Hostinger File Content API refuses secret / protected paths. Still live on the server:

- `config.php` / `portal/config.php` (Supabase credentials) — use `config.example.php`
- `create_autologin_*.php` (one-time helper)
- `.private/.htaccess`

## Local setup

1. Copy `config.example.php` to `config.php` and set Supabase URL + anon key
2. Same for `portal/config.example.php` to `portal/config.php`