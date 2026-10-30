# Authelia sign-in

phpIP supports optional Authelia sign-in using Laravel Socialite and Authelia's
OpenID Connect authorization-code flow with PKCE (S256). Each hosting organization
configures its own Authelia server and OIDC client through `.env`. Password login
remains available.

Authelia controls who may authorize the phpIP client. After a successful sign-in,
phpIP creates a local account if necessary. Its permissions come from phpIP, not
from Authelia group names or other user-provided profile fields.

## 1. Register phpIP in Authelia

Configure an OIDC **confidential client** with:

| Setting | Value |
| --- | --- |
| Client ID | An organization-specific ID, such as `phpip` |
| Redirect URI | `https://phpip.example.org/auth/authelia/callback` |
| Grant type / response type | `authorization_code` / `code` |
| Scopes | `openid`, `profile` |
| PKCE | Required, `S256` |
| Token endpoint authentication | `client_secret_post` |
| Userinfo response format | JSON (`userinfo_signed_response_alg: none`) |

Use an Authelia authorization policy that permits only the intended users or
groups. For example, on current Authelia versions the relevant configuration is:

```yaml
identity_providers:
  oidc:
    # Keep your existing OIDC signing keys and other server configuration.
    authorization_policies:
      phpip:
        default_policy: deny
        rules:
          - policy: two_factor
            subject: 'group:phpip-users'
    clients:
      - client_id: phpip
        client_name: phpIP
        client_secret: 'REPLACE_WITH_HASH_OF_CLIENT_SECRET'
        public: false
        authorization_policy: phpip
        require_pkce: true
        pkce_challenge_method: S256
        redirect_uris:
          - https://phpip.example.org/auth/authelia/callback
        scopes:
          - openid
          - profile
        response_types:
          - code
        grant_types:
          - authorization_code
        token_endpoint_auth_method: client_secret_post
        userinfo_signed_response_alg: none
```

Merge this into your existing Authelia configuration, adapting policy syntax to
your installed version. Do not replace your server's OIDC keys or unrelated
clients. Generate a strong client secret and hash it using Authelia's supported
tooling (for example, `authelia crypto hash generate pbkdf2 --variant sha512`).
Authelia stores the hash; phpIP needs the original secret. See the
[Authelia OIDC client documentation](https://www.authelia.com/configuration/identity-providers/openid-connect/clients/).

Do not use a policy that admits every Authelia user unless every such user should
have access to this phpIP instance. An account is provisioned only after the token
exchange and authenticated userinfo request succeed for this client.

## 2. Configure phpIP

Install dependencies and apply the account-link migration:

```sh
composer install
php artisan migrate --force
```

Set these values in the hosting organization's `.env`:

```dotenv
APP_URL=https://phpip.example.org
SESSION_SECURE_COOKIE=true

AUTHELIA_ENABLED=true
AUTHELIA_BASE_URL=https://auth.example.org
AUTHELIA_CLIENT_ID=phpip
AUTHELIA_CLIENT_SECRET=REPLACE_WITH_ORIGINAL_CLIENT_SECRET
AUTHELIA_REDIRECT_URI=https://phpip.example.org/auth/authelia/callback
AUTHELIA_AUTO_PROVISION=true
AUTHELIA_DEFAULT_ROLE=DBRO
```

`AUTHELIA_BASE_URL` is the public HTTPS base URL of Authelia, not the discovery or
authorization endpoint. The redirect URI must match the registered URI exactly.
phpIP must be able to reach the server's `/api/oidc/token` and
`/api/oidc/userinfo` endpoints over verified HTTPS. Configure trusted proxies
appropriately when Laravel sits behind a TLS-terminating reverse proxy. Keep
client secrets out of source control and logs.

Then clear cached configuration, or rebuild it if that is your deployment practice:

```sh
php artisan config:clear
# Production deployments may use: php artisan config:cache
```

Restart long-running PHP workers or development servers after configuration
changes. The login screen now offers **Sign in with Authelia**. Setting
`AUTHELIA_ENABLED=false` hides the option and disables its routes.

## 3. Choose local permissions

`AUTHELIA_DEFAULT_ROLE` applies only when a new account is created:

| Role | Access |
| --- | --- |
| `DBRO` (default) | Read-only access to the portfolio, including all matters |
| `DBRW` | Staff read/write access |
| `CLI` | Client-scoped access; associate the account with its client/company in phpIP |

`DBA` cannot be assigned through automatic provisioning. Grant administrative
permissions explicitly in phpIP, or link Authelia to an existing administrator.
Changing the default does not change existing accounts. Authelia groups do not
grant or update phpIP roles. If one installation hosts clients who must not see
each other's matters, use `CLI` and configure their phpIP client relationships.

Automatically created accounts have no password and use an internal login of the
form `sso_…` to fit phpIP's 16-character login field. The profile name is used for
display. The stable, case-sensitive Authelia subject (`sub`) and issuer identify
the account; usernames and email addresses are never used to claim an existing
account. Repeated sign-ins reuse the same account and preserve its permissions.

## 4. Link an existing account (optional)

To retain an existing user's matters, role, and company scope, an administrator
can link the user's Authelia OIDC `sub` before their first SSO sign-in:

```sh
php artisan authelia:link existing_phpip_login AUTHELIA_SUBJECT
```

Obtain the subject from your Authelia OIDC administration records or a securely
inspected userinfo response for this client. It is not necessarily the username,
and may be client-specific. Repeating the command is safe; assigning an already
linked subject to a different account is refused. To deliberately relink it:

```sh
php artisan authelia:link old_phpip_login AUTHELIA_SUBJECT --unlink
php artisan authelia:link new_phpip_login AUTHELIA_SUBJECT
```

Set `AUTHELIA_AUTO_PROVISION=false` if only explicitly linked accounts should be
allowed. To revoke application access, change the user's Authelia authorization
policy; unlinking alone allows a new account to be created when provisioning is
enabled. Removing a linked actor's local login also prevents it from signing in.
Changes to Authelia authorization apply to new sign-ins; existing phpIP sessions
last until local logout or session expiry. Logging out of phpIP does not log the
browser out of Authelia or other applications.

## Validation

Use an authorized user to verify first sign-in and repeated sign-in, confirm its
local role, and verify that a user outside the Authelia policy is denied. Check
password login separately. Failed/cancelled sign-ins return a generic error;
restart the sign-in if its state expires after ten minutes.

The automated integration tests exercise the real Socialite provider against
mocked token/userinfo endpoints, including state, PKCE, provisioning, identity
isolation, permissions, network failure, and existing password login:

```sh
DB_DATABASE=phpip_test php artisan migrate --force
vendor/bin/phpunit tests/Feature/AutheliaLoginTest.php
```

Use your configured test database (the repository's PHPUnit configuration uses
`phpip_test`). No live Authelia instance or real client secret is needed for these
automated tests.
