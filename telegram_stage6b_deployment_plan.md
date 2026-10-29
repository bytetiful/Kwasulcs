# KWASU LCS Telegram Stage 6B deployment plan

Preparation date: 22 September 2026. This is a manual runbook only. No production host, production database, or Telegram API operation was performed while preparing it.

## Approved deployment manifest

The archive must contain exactly these 27 files and no tests, fixtures, `.env`, `.git`, local databases, `private_uploads`, broad cleanup changes, or image deletions:

```text
telegram_notifications_migration.sql
includes/notifications/fanout.php
includes/notifications/signed_links.php
includes/notifications/types.php
includes/telegram/bot_api.php
includes/telegram/config.php
includes/telegram/connections.php
includes/telegram/linking.php
includes/telegram/live.php
includes/telegram/notifications.php
includes/telegram/queue.php
includes/telegram/rate_limit.php
includes/telegram/settings.php
includes/telegram/webhook.php
telegram_connect.php
telegram_connection_status.php
telegram_webhook.php
telegram_admin.php
go.php
settings.php
assets/telegram-settings.css
assets/telegram-settings.js
notify_cron.php
includes/reminders.php
includes/assignment_test.php
includes/online_quiz.php
includes/online_quiz_integration.php
```

`config.php` has no Telegram-stage diff and must not be copied. Environment examples are reference only. Privacy files are unrelated. WhatsApp files and tables remain untouched.

## Migration

`telegram_notifications_migration.sql` is additive and MariaDB 10.4-compatible. It uses `CREATE TABLE IF NOT EXISTS`, InnoDB, `utf8mb4`, and a signed `INT` foreign key matching the existing `users.id`.

Preflight:

```bash
mysql --host=mysql-kwasulcs.alwaysdata.net --user=kwasulcs --password --database=kwasulcs_lcs <<'SQL'
SELECT VERSION() AS database_version, DATABASE() AS database_name;
SELECT TABLE_NAME, ENGINE, TABLE_COLLATION FROM information_schema.TABLES
 WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='users';
SELECT COLUMN_NAME,COLUMN_TYPE,IS_NULLABLE,COLUMN_KEY,EXTRA FROM information_schema.COLUMNS
 WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='users' AND COLUMN_NAME='id';
SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN
 ('telegram_connections','telegram_link_tokens','telegram_notification_preferences','telegram_delivery_jobs','telegram_webhook_updates')
 ORDER BY TABLE_NAME;
SQL
```

Stop if `users.id` is not signed `INT`, `users` is not InnoDB, the selected database is wrong, or unexpected Telegram tables have not been reviewed. Apply once:

```bash
mysql --host=mysql-kwasulcs.alwaysdata.net --user=kwasulcs --password --database=kwasulcs_lcs \
  < /home/kwasulcs/telegram-stage6b-staging/telegram_notifications_migration.sql
```

Postflight:

```bash
mysql --host=mysql-kwasulcs.alwaysdata.net --user=kwasulcs --password --database=kwasulcs_lcs <<'SQL'
SELECT TABLE_NAME,ENGINE,TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE()
 AND TABLE_NAME IN ('telegram_connections','telegram_link_tokens','telegram_notification_preferences','telegram_delivery_jobs','telegram_webhook_updates') ORDER BY TABLE_NAME;
SELECT 'telegram_connections' table_name,COUNT(*) row_count FROM telegram_connections
UNION ALL SELECT 'telegram_link_tokens',COUNT(*) FROM telegram_link_tokens
UNION ALL SELECT 'telegram_notification_preferences',COUNT(*) FROM telegram_notification_preferences
UNION ALL SELECT 'telegram_delivery_jobs',COUNT(*) FROM telegram_delivery_jobs
UNION ALL SELECT 'telegram_webhook_updates',COUNT(*) FROM telegram_webhook_updates;
SHOW CREATE TABLE telegram_connections;
SHOW CREATE TABLE telegram_link_tokens;
SHOW CREATE TABLE telegram_notification_preferences;
SHOW CREATE TABLE telegram_delivery_jobs;
SHOW CREATE TABLE telegram_webhook_updates;
SQL
```

All five tables should initially contain zero rows.

## Timestamped backup

Run on AlwaysData before migration or deployment:

```bash
set -eu
BACKUP_ROOT=/home/kwasulcs/deployment-backups
BACKUP_STAMP=$(date -u +%Y%m%dT%H%M%SZ)
BACKUP_DIR="$BACKUP_ROOT/telegram-stage6b-$BACKUP_STAMP"
test ! -e "$BACKUP_DIR"
install -d -m 700 "$BACKUP_DIR"
tar -C /home/kwasulcs -czf "$BACKUP_DIR/www-before.tar.gz" www
chmod 600 "$BACKUP_DIR/www-before.tar.gz"
mysqldump --host=mysql-kwasulcs.alwaysdata.net --user=kwasulcs --password \
  --single-transaction --quick --routines --triggers --events --hex-blob \
  --default-character-set=utf8mb4 --result-file="$BACKUP_DIR/kwasulcs_lcs-before.sql" kwasulcs_lcs
gzip -9 "$BACKUP_DIR/kwasulcs_lcs-before.sql"
chmod 600 "$BACKUP_DIR/kwasulcs_lcs-before.sql.gz"
printf '%s\n' "$BACKUP_DIR"
```

The application backup contains `.env`; retain mode `600` in the private backup directory.

## Package and upload

Run locally:

```bash
cd /Applications/XAMPP/xamppfiles/htdocs/LMS-portal
COPYFILE_DISABLE=1 tar -czf /Users/anatulu-ihemobinna/Desktop/kwasu-telegram-stage6b.tar.gz \
  telegram_notifications_migration.sql \
  includes/notifications/fanout.php includes/notifications/signed_links.php includes/notifications/types.php \
  includes/telegram/bot_api.php includes/telegram/config.php includes/telegram/connections.php \
  includes/telegram/linking.php includes/telegram/live.php includes/telegram/notifications.php \
  includes/telegram/queue.php includes/telegram/rate_limit.php includes/telegram/settings.php includes/telegram/webhook.php \
  telegram_connect.php telegram_connection_status.php telegram_webhook.php telegram_admin.php go.php settings.php \
  assets/telegram-settings.css assets/telegram-settings.js notify_cron.php includes/reminders.php \
  includes/assignment_test.php includes/online_quiz.php includes/online_quiz_integration.php
tar -tzf /Users/anatulu-ihemobinna/Desktop/kwasu-telegram-stage6b.tar.gz
```

Verify 27 members, then upload manually:

```bash
scp /Users/anatulu-ihemobinna/Desktop/kwasu-telegram-stage6b.tar.gz \
  kwasulcs@ssh-kwasulcs.alwaysdata.net:/home/kwasulcs/
```

## Staging and copy

Run on AlwaysData:

```bash
set -eu
ARCHIVE=/home/kwasulcs/kwasu-telegram-stage6b.tar.gz
STAGE_DIR=/home/kwasulcs/telegram-stage6b-staging
APP_ROOT=/home/kwasulcs/www
test -f "$ARCHIVE"
test ! -e "$STAGE_DIR"
install -d -m 700 "$STAGE_DIR"
tar -tzf "$ARCHIVE"
test "$(tar -tzf "$ARCHIVE" | wc -l | tr -d ' ')" = 27
if tar -tzf "$ARCHIVE" | grep -Eq '(^/|(^|/)\.\.(/|$)|(^|/)\.env$|(^|/)private_uploads(/|$)|(^|/)tests(/|$)|(^|/)\.git(/|$))'; then
  echo 'Unsafe or out-of-scope archive member detected.' >&2
  exit 1
fi
tar -xzf "$ARCHIVE" -C "$STAGE_DIR"
find "$STAGE_DIR" -type f -name '*.php' -print0 | xargs -0 -n1 /usr/bin/php -l
```

Derive the exact 26-file runtime list from the already validated archive, excluding only the SQL migration. Compare each staged file with `/home/kwasulcs/www`, then dry-run and perform the controlled copy:

```bash
APP_FILES=/home/kwasulcs/telegram-stage6b-app-files.txt
tar -tzf "$ARCHIVE" | grep -v '^telegram_notifications_migration\.sql$' > "$APP_FILES"
test "$(wc -l < "$APP_FILES" | tr -d ' ')" = 26
while IFS= read -r FILE; do
  if test -f "$APP_ROOT/$FILE"; then diff -u "$APP_ROOT/$FILE" "$STAGE_DIR/$FILE" || true; else printf 'NEW %s\n' "$FILE"; fi
done < "$APP_FILES"
rsync -a --relative --itemize-changes --dry-run --files-from="$APP_FILES" "$STAGE_DIR/" "$APP_ROOT/"
rsync -a --relative --itemize-changes --files-from="$APP_FILES" "$STAGE_DIR/" "$APP_ROOT/"
while IFS= read -r FILE; do case "$FILE" in *.php) /usr/bin/php -l "$APP_ROOT/$FILE" ;; esac; done < "$APP_FILES"
```

`.env` and `private_uploads` are absent from both the archive and copy list.

## Production environment merge

Edit the private `.env` interactively or use the AlwaysData environment UI. Do not type secrets into command history. Merge only:

```dotenv
TELEGRAM_ENABLED=0
TELEGRAM_BOT_TOKEN=<REAL_BOT_TOKEN>
TELEGRAM_BOT_USERNAME=TrustyGee_Bot
TELEGRAM_WEBHOOK_SECRET=<REAL_WEBHOOK_SECRET>
TELEGRAM_WEBHOOK_URL=https://kwasulcs.alwaysdata.net/telegram_webhook.php
TELEGRAM_PILOT_USER_IDS=<OWNER_LCS_USER_ID>
TELEGRAM_MAX_MESSAGES_PER_SECOND=25
TELEGRAM_MAX_ATTEMPTS=5
TELEGRAM_LINK_TOKEN_TTL_SECONDS=600
```

Verify the existing values remain:

```dotenv
LCS_BASE_URL=https://kwasulcs.alwaysdata.net
LCS_LINK_SIGNING_KEY=<EXISTING_SECURE_VALUE—DO_NOT_REPLACE>
```

Preserve DB, SMTP, AI, OAuth, privacy, WhatsApp, and secure-quiz settings. `TELEGRAM_ENABLED=0` remains unchanged throughout the pilot.

## Pilot user lookup

Use existing LCS account facts and require exactly one expected row:

```bash
mysql --host=mysql-kwasulcs.alwaysdata.net --user=kwasulcs --password --database=kwasulcs_lcs <<'SQL'
SELECT id,full_name,matric_no,email,role FROM users
WHERE email='<OWNER_LCS_EMAIL>' OR matric_no='<OWNER_LCS_MATRIC_NO>' ORDER BY id;
SQL
```

Put only that numeric `users.id` into `TELEGRAM_PILOT_USER_IDS`. Never use a Telegram username as pilot identity.

## Static validation before API access

```bash
/usr/bin/php /home/kwasulcs/www/telegram_admin.php --readiness
/usr/bin/php /home/kwasulcs/www/notify_cron.php --health
/usr/bin/php -r 'require "/home/kwasulcs/www/includes/whatsapp.php"; $s=whatsapp_configuration_status(); echo json_encode(["enabled"=>$s["enabled"],"provider"=>$s["provider"],"send_ready"=>$s["send_ready"],"webhook_ready"=>$s["webhook_ready"]], JSON_PRETTY_PRINT), PHP_EOL;'
curl -fsS -o /dev/null -w 'home %{http_code}\n' https://kwasulcs.alwaysdata.net/
curl -sS -o /dev/null -w 'settings %{http_code}\n' https://kwasulcs.alwaysdata.net/settings.php
curl -sS -o /dev/null -w 'telegram_admin_http %{http_code}\n' https://kwasulcs.alwaysdata.net/telegram_admin.php
```

Readiness must show global off, exactly one pilot, safe restriction, matching expected bot/webhook, and all readiness booleans true. `telegram_admin.php` over HTTP must be 403. Verify all five Telegram table counts are zero, WhatsApp readiness is unchanged, login works, Settings loads, and Telegram says Not Connected.

## Live activation — run only after explicit approval

```bash
/usr/bin/php /home/kwasulcs/www/telegram_admin.php --readiness
/usr/bin/php /home/kwasulcs/www/telegram_admin.php --get-me --confirm-live
/usr/bin/php /home/kwasulcs/www/telegram_admin.php --set-webhook --confirm-live
/usr/bin/php /home/kwasulcs/www/telegram_admin.php --webhook-info --confirm-live
```

Stop on any identity, URL, readiness, or provider error.

## Linking and test send

1. Owner signs into production LCS and opens Settings.
2. Confirm Telegram says Not Connected; select Connect Telegram.
3. Open the generated `t.me/TrustyGee_Bot?start=...` URL and press Start.
4. The private webhook update consumes the one-time token and binds the connection.
5. Settings changes to Connected.
6. Query `telegram_connections` and verify exactly one row for the numeric pilot user; invite no other user.

After separate approval:

```bash
/usr/bin/php /home/kwasulcs/www/telegram_admin.php --send-test --user-id=<OWNER_LCS_USER_ID> --confirm-live
/usr/bin/php /home/kwasulcs/www/notify_cron.php --run
```

Expect exactly one owner-only message from `@TrustyGee_Bot`, bounded HTML, an Open LCS button, authenticated signed `go.php` continuation, one sent ledger row, a provider message ID, no duplicate idempotency key, and normal worker health.

## Rollback

Remove the webhook before clearing credentials. The CLI rechecks bot identity and retains pending updates:

```bash
/usr/bin/php /home/kwasulcs/www/telegram_admin.php --delete-webhook --confirm-live
/usr/bin/php /home/kwasulcs/www/telegram_admin.php --webhook-info --confirm-live
```

Then set `TELEGRAM_ENABLED=0` and clear `TELEGRAM_PILOT_USER_IDS` in the private environment. Remove or rotate Telegram secrets through the private editor/UI, never shell history. Leave WhatsApp configuration untouched.

If code rollback is required, restore only the approved paths from the timestamped application backup:

```bash
ROLLBACK_BACKUP=/home/kwasulcs/deployment-backups/telegram-stage6b-<TIMESTAMP>/www-before.tar.gz
ROLLBACK_DIR=/home/kwasulcs/telegram-stage6b-rollback-<TIMESTAMP>
APP_ROOT=/home/kwasulcs/www
APP_FILES=/home/kwasulcs/telegram-stage6b-app-files.txt
ROLLBACK_FILES=/home/kwasulcs/telegram-stage6b-rollback-files.txt
test -f "$ROLLBACK_BACKUP"
test ! -e "$ROLLBACK_DIR"
install -d -m 700 "$ROLLBACK_DIR"
tar -xzf "$ROLLBACK_BACKUP" -C "$ROLLBACK_DIR"
while IFS= read -r FILE; do test -f "$ROLLBACK_DIR/www/$FILE" && printf '%s\n' "$FILE"; done < "$APP_FILES" > "$ROLLBACK_FILES"
rsync -a --relative --itemize-changes --dry-run --files-from="$ROLLBACK_FILES" "$ROLLBACK_DIR/www/" "$APP_ROOT/"
rsync -a --relative --itemize-changes --files-from="$ROLLBACK_FILES" "$ROLLBACK_DIR/www/" "$APP_ROOT/"
```

Files introduced for the first time by Telegram will not exist in the old backup; they can safely remain inert after the environment is disabled. Preserve sent/history rows and leave the five additive Telegram tables dormant. Do not drop WhatsApp tables or restore the whole database for an ordinary Telegram rollback. The database dump is reserved for a separately approved maintenance restore if the migration itself causes an unexpected database-wide problem.

## Risks and stop conditions

- Production schema drift must stop the migration.
- Replacing `config.php` or `.env` wholesale can break existing services.
- A wrong numeric pilot ID can expose linking to another LCS user.
- Replacing `LCS_LINK_SIGNING_KEY` invalidates existing signed links.
- Bot identity mismatch must stop before webhook registration.
- Do not add another scheduler; `notify_cron.php --run` remains authoritative.
- A one-user pilot does not establish global or high-volume readiness.
- WhatsApp must remain available and `TELEGRAM_ENABLED=0` throughout the pilot.
