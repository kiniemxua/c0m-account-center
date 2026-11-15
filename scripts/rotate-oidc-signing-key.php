<?php
declare(strict_types=1);

define('BASE_PATH', dirname(__DIR__));

spl_autoload_register(static function (string $class): void {
    $prefix = 'App\\';
    if (!str_starts_with($class, $prefix)) return;
    $file = BASE_PATH . '/app/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($file)) require $file;
});

$storage = BASE_PATH . '/storage';
if (!is_dir($storage) && !mkdir($storage, 0750, true) && !is_dir($storage)) {
    fwrite(STDERR, "OIDC signing storage is unavailable.\n");
    exit(1);
}
$lockPath = $storage . '/oauth-signing-rotation.lock';
if (is_link($lockPath)) {
    fwrite(STDERR, "The OIDC rotation lock path must not be a symbolic link.\\n");
    exit(1);
}
$lock = @fopen($lockPath, 'c');
if (!is_resource($lock) || !flock($lock, LOCK_EX | LOCK_NB)) {
    fwrite(STDERR, "Another OIDC signing-key rotation is already running.\n");
    exit(1);
}
@chmod($storage . '/oauth-signing-rotation.lock', 0600);

try {
    $activePath = $storage . '/oauth-signing-private.pem';
    if (is_link($activePath)) {
        throw new RuntimeException('The active signing-key path must not be a symbolic link.');
    }

    if (!is_file($activePath)) {
        throw new RuntimeException('No active signing key exists yet; allow the application to generate its first key before rotating.');
    }
    $oldKid = \App\Core\OidcSigner::keyId();
    $oldPem = @file_get_contents($activePath);
    if (!is_string($oldPem) || $oldPem === '') {
        throw new RuntimeException('The current signing key is unreadable.');
    }
    $oldPrivate = openssl_pkey_get_private($oldPem);
    $oldDetails = $oldPrivate ? openssl_pkey_get_details($oldPrivate) : false;
    if (!is_array($oldDetails) || ($oldDetails['type'] ?? null) !== OPENSSL_KEYTYPE_RSA
        || (int)($oldDetails['bits'] ?? 0) < 2048 || !isset($oldDetails['key'])) {
        throw new RuntimeException('The current signing key is not a valid RSA-2048+ key.');
    }

    $newPrivate = openssl_pkey_new([
        'private_key_type' => OPENSSL_KEYTYPE_RSA,
        'private_key_bits' => 2048,
    ]);
    if (!$newPrivate || !openssl_pkey_export($newPrivate, $newPem)) {
        throw new RuntimeException('Could not generate the replacement RSA signing key.');
    }
    $newDetails = openssl_pkey_get_details($newPrivate);
    if (!is_array($newDetails) || !isset($newDetails['key'])) {
        throw new RuntimeException('Could not inspect the replacement signing key.');
    }
    $newKid = substr(hash('sha256', $newDetails['key']), 0, 24);
    if (hash_equals($oldKid, $newKid)) {
        throw new RuntimeException('The replacement signing key unexpectedly matches the current key.');
    }

    $previousDirectory = $storage . '/oauth-signing-previous';
    if (!is_dir($previousDirectory) && !mkdir($previousDirectory, 0750, true) && !is_dir($previousDirectory)) {
        throw new RuntimeException('Could not create the previous public-key directory.');
    }
    $previousPath = $previousDirectory . '/' . $oldKid . '.pem';
    if (is_link($previousPath)) {
        throw new RuntimeException('The previous public-key path must not be a symbolic link.');
    }

    // Make retries safe if a prior run persisted the public key but failed
    // before replacing the active private key.
    if (is_file($previousPath)) {
        $existingPem = @file_get_contents($previousPath);
        $existingPublic = is_string($existingPem) ? openssl_pkey_get_public($existingPem) : false;
        $existingDetails = $existingPublic ? openssl_pkey_get_details($existingPublic) : false;
        $existingKid = is_array($existingDetails) && isset($existingDetails['key'])
            ? substr(hash('sha256', $existingDetails['key']), 0, 24)
            : '';
        if (!hash_equals($oldKid, $existingKid)) {
            throw new RuntimeException('A different public key already exists for the current key ID.');
        }
        if (!touch($previousPath, time())) {
            throw new RuntimeException('Could not refresh the previous public-key overlap window.');
        }
    } else {
        oidcAtomicWrite($previousPath, $oldDetails['key'], 0640);
    }

    oidcAtomicWrite($activePath, $newPem, 0600);
    fwrite(STDOUT, "OIDC signing key rotated successfully.\n");
    fwrite(STDOUT, "Previous key ID: {$oldKid}\n");
    fwrite(STDOUT, "Active key ID:   {$newKid}\n");
    fwrite(STDOUT, "The previous public key remains in JWKS for up to 24 hours.\n");
} catch (Throwable $e) {
    fwrite(STDERR, 'OIDC signing-key rotation failed: ' . $e->getMessage() . "\n");
    exit(1);
} finally {
    if (is_resource($lock)) {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

/**
 * Persist a file through a same-directory temporary file and atomic rename.
 */
function oidcAtomicWrite(string $path, string $contents, int $mode): void
{
    $temporary = $path . '.' . bin2hex(random_bytes(8)) . '.tmp';
    try {
        if (file_put_contents($temporary, $contents, LOCK_EX) === false
            || !chmod($temporary, $mode)
            || !rename($temporary, $path)) {
            throw new RuntimeException('Could not securely persist ' . basename($path) . '.');
        }
    } finally {
        if (is_file($temporary)) @unlink($temporary);
    }
}
