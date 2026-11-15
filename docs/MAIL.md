# Mail configuration

The Account Center sends verification and password-reset messages through a provider interface. It does not require a self-hosted mail server.

## Development

Copy `config/mail.example.php` to `config/mail.php` and use:

```php
return [
    'mode' => 'development_log',
];
```

Messages are written to `storage/mail.log`. This file contains active one-time links. Use only in local development or an isolated staging environment, restrict storage permissions, and delete the log after testing.

## External SMTP (production)

Configure `config/mail.php` with the SMTP details supplied by CraneMail or another external provider:

```php
return [
    'mode' => 'smtp',
    'host' => 'smtp.provider.example',
    'port' => 587,
    'encryption' => 'tls',
    'username' => 'account@example.com',
    'password' => 'SET_OUTSIDE_GIT',
    'from_email' => 'no-reply@example.com',
    'from_name' => 'c0m.me Account Center',
    'helo' => 'accounts.example.com',
    'timeout' => 15,
];
```

- Port 587 normally uses STARTTLS (`encryption => tls`).
- Port 465 normally uses implicit TLS (`encryption => ssl`).
- SMTP AUTH is refused when encryption is `none`; do not send credentials over plaintext.
- Username and password must either both be present or both be empty.
- TLS peer and hostname verification are always enabled. `tls_peer_name` is optional (defaults to `host`) and `ca_file` can point to a private CA bundle; keep that CA file outside the public document root.
- Use a sender address/domain authorized by your mail provider.
- Keep `config/mail.php` outside the public document root, set restrictive permissions and never commit credentials.

The local SMTP fixture tests STARTTLS, trusted CA validation, hostname mismatch rejection, AUTH failure, timeout and unavailable-server handling. A successful local SMTP fixture test proves protocol handling against the fixture only. It does not prove that a live provider account, sender domain, DNS records or deliverability are configured correctly.
