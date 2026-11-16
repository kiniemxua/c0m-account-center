#!/usr/bin/env bash
set -Eeuo pipefail

ROOT="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

export DB_HOST="${DB_HOST:-127.0.0.1}"
export DB_PORT="${DB_PORT:-3306}"
export DB_NAME="${DB_NAME:-c0m_test}"
export DB_USER="${DB_USER:-root}"
export DB_PASSWORD="${DB_PASSWORD:-}"

if [[ "$DB_NAME" != "c0m_test" ]]; then
    echo "Refusing to run integration tests against database '$DB_NAME'." >&2
    echo "Use a dedicated disposable database named c0m_test." >&2
    exit 2
fi

if ! php -r 'exit(PHP_VERSION_ID >= 80200 ? 0 : 1);'; then
    echo "PHP 8.2 or newer is required; found $(php -r 'echo PHP_VERSION;')." >&2
    exit 2
fi

for extension in pdo_mysql mbstring openssl fileinfo; do
    if ! php -r 'exit(extension_loaded($argv[1]) ? 0 : 1);' "$extension"; then
        echo "Missing required PHP extension: $extension" >&2
        exit 2
    fi
done

for path in config/database.php config/app.php config/mail.php config/payments.php storage/install.lock storage/mail.log; do
    if [[ -e "$path" ]]; then
        echo "Refusing to overwrite existing runtime file: $path" >&2
        echo "Use a clean checkout/runtime configuration before running the test suite." >&2
        exit 2
    fi
done

php -r '
$dsn = sprintf(
    "mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4",
    getenv("DB_HOST"),
    getenv("DB_PORT"),
    getenv("DB_NAME")
);
$pdo = new PDO($dsn, getenv("DB_USER"), getenv("DB_PASSWORD"), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$count = (int)$pdo->query(
    "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = " . $pdo->quote(getenv("DB_NAME"))
)->fetchColumn();
if ($count !== 0) {
    fwrite(STDERR, "Refusing to run against a non-empty c0m_test database ($count tables found).\n");
    fwrite(STDERR, "Create a fresh, empty disposable database named c0m_test and retry.\n");
    exit(2);
}
echo "Connected to empty disposable database: " . getenv("DB_NAME") . PHP_EOL;
'

# The runner has already verified that these runtime paths did not exist.
# Remove test-generated secrets/configuration even when an individual test fails.
cleanup_runtime_files() {
    rm -f -- \
        config/database.php \
        config/app.php \
        config/mail.php \
        config/payments.php \
        storage/install.lock \
        storage/mail.log
}
trap cleanup_runtime_files EXIT

echo "== PHP syntax validation =="
while IFS= read -r -d '' file; do
    php -l "$file"
done < <(find app public config scripts tests lang -type f -name '*.php' -print0)

for test in \
    tests/unit.php \
    tests/localization.php \
    tests/smtp.php \
    tests/installer.php \
    tests/integration.php \
    tests/rbac-concurrency.php \
    tests/http.php
do
    echo "== $test =="
    php "$test"
done

echo "All c0m-account-center checks passed."
