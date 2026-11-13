# Database migrations and recovery

Migrations are ordered SQL files named `NNN_description.sql`. The cPanel installer applies them in lexical order. The current migrations use `CREATE TABLE IF NOT EXISTS` and idempotent seed statements so an interrupted first installation can be retried.

## MySQL/MariaDB DDL behavior

MySQL/MariaDB DDL statements commonly cause implicit commits. Do not assume a migration can be rolled back with a transaction. The current project therefore uses a forward-recovery strategy:

1. Take a database backup before applying schema changes to an existing installation.
2. Apply migrations in numeric order.
3. If an installation stops partway through, inspect the schema and server error log.
4. Re-run only migrations documented as repeatable; do not blindly replay future data migrations.
5. Prefer a new forward-fix migration over editing a migration already used by an installation.
6. Restore the backup if a migration has caused unrecoverable data loss.

There is not yet a migration-version ledger or automated down-migration system. Do not delete existing migration files or manually drop tables to recover an installation.
