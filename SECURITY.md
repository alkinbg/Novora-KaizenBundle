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

For suspected security vulnerabilities, **do not open a public issue** with
exploit details or credentials. Contact the maintainer privately at
**alkinbg@gmail.com**, or use GitHub's [private vulnerability reporting](https://github.com/alkinbg/Novora-KaizenBundle/security/advisories/new)
if it is enabled for this repository.

Include the affected version, impact and minimal sanitized reproduction.
No guaranteed response time is promised. Supported release line: **1.x**
after the first tagged stable release.
