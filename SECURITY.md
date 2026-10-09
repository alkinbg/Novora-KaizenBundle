# Security policy

Novora KaizenBundle reads application logs and stores investigations locally.
It is intended for trusted administrators and does not eliminate the host
application's responsibility to protect operational data.

- Protect /_kaizen behind the host application's authentication and ROLE_ADMIN.
- Never log credentials or personal data. Display redaction is best-effort
  and cannot sanitize every possible secret.
- Restrict var/kaizen permissions to the PHP worker; never publish it as an
  HTTP-accessible directory.
- The application does not send telemetry to third-party services.

For suspected security vulnerabilities, avoid opening a public issue with
exploitable details or secrets. Contact the repository maintainer privately,
or use GitHub private vulnerability reporting if enabled.
