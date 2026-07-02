# de_cipico_saldap — LDAP Authentication for CiviCRM Standalone

Authenticate CiviCRM Standalone users against an LDAP directory (OpenLDAP, Active Directory, etc.). Automatically creates local user accounts and maps LDAP group membership to CiviCRM roles.

[![Build Status](https://jenkins.fpsvisionary.com/buildStatus/icon?job=list%2Fde_cipico_saldap%2Fmain)](https://jenkins.fpsvisionary.com/job/list/job/de_cipico_saldap/job/main/)

## Requirements

- CiviCRM 6.13+ (Standalone)
- PHP 8.1+ with `ldap` extension
- An LDAP server (OpenLDAP, AD, etc.) accessible from the CiviCRM pod

## Installation

```bash
cv ext:enable de_cipico_saldap
```

## Configuration

Go to **Administer → System Settings → LDAP Server Settings**.

### Connection Settings

| Setting | Example | Description |
|---|---|---|
| LDAP Server URI | `ldap.antano.io` | Hostname with optional `ldap://` or `ldaps://` scheme |
| LDAP Port | `389` or `636` | Port (636 auto-detects LDAPS) |
| Bind DN | `cn=readonly,ou=system,dc=example,dc=com` | Service account for user lookup |
| Bind Password | *(password field)* | Password for the service account |
| Base DN | `ou=people,dc=example,dc=com` | Search base for user entries |
| Use TLS | ✓ | Enable StartTLS (only for `ldap://`, not `ldaps://`) |

### User Filter

Controls how users are found. `%s` is replaced with the login username.

- **OpenLDAP** (default): `(&(objectClass=person)(uid=%s))`
- **Active Directory**: `(&(objectClass=user)(sAMAccountName=%s))`

### Authorization

| Setting | Description |
|---|---|
| Required Group DN | Full DN of a group. Only members of this group can log in. Leave empty to allow all. |

### Auto-Provisioning

When enabled, a CiviCRM user + contact is automatically created on first successful LDAP login.

| Setting | Default | Description |
|---|---|---|
| Auto-Create Users | ✓ | Create CiviCRM user + contact on first LDAP login |
| Sync Contact on Every Login | ✓ | Update name/email from LDAP on each subsequent login. Disable to only sync on initial creation. |
| Mail Attribute | `mail` | LDAP attribute for email |
| First Name Attribute | `givenName` | LDAP attribute for given name |
| Last Name Attribute | `sn` | LDAP attribute for surname |

> **Sync Contact on Every Login:** When enabled (default), every LDAP login
> overwrites the CiviCRM contact's first name, last name, and email with
> values from the LDAP directory. Disable this if you want to manage contact
> data manually in CiviCRM after initial creation.

### Role Mapping

Map LDAP groups to CiviCRM roles. Use the **Add Row** button to create mappings.

| LDAP Group | CiviCRM Role |
|---|---|
| `employees` | Staff (3) |
| `admins` | Administrator (2) |

Each row auto-constructs the full group DN as `cn=<name>,ou=groups,<domain>`.

## LDAP Group → DN Construction

When you enter just the group name (e.g. `employees`), the extension builds the full DN as:

```
cn=employees,ou=groups,dc=ldapmaster,dc=fpsvisionary,dc=com
```

The domain base (`dc=...`) is extracted from the configured *Base DN* setting.

## How It Works

### Authentication Flow

```
User submits login form
  → User::Login API
    → Security::checkPassword() checks local hashed_password
    → If that fails, login fails
```

```
User authenticates via API / REST / HTTP headers
  → civi.authx.checkCredential event
    → [priority -150] LdapAuthenticator tries LDAP:
        1. Connect to LDAP server
        2. Bind with service account
        3. Search for user by filter
        4. Re-bind as user to verify password
        5. Check group membership (if configured)
        6. Fetch all user's group memberships
        7. Auto-create CiviCRM user + contact
        8. Sync CiviCRM roles based on group mappings
      → accept credential → user is logged in
    → [priority -200] basicUser (local DB fallback)
```

> **Note:** The web login form uses `Security::checkPassword()` (local DB).
> On first LDAP login via API, the actual password hash is stored locally,
> so subsequent web logins also work.

### Contact Sync Behavior

- **First login:** A new CiviCRM contact is created with name/email from LDAP.
- **Subsequent logins:** If *Sync Contact on Every Login* is enabled (default),
  the existing contact's name and email are overwritten with current LDAP values.
  If disabled, contact data is only set on creation and never changed afterwards.

### Local Password Cache

On successful LDAP authentication, the user's password is hashed with CiviCRM's
built-in algorithm and stored locally. This enables:

1. **API/AuthX logins** — intercepted by our `civi.authx.checkCredential` listener
2. **Web login form** — checks local hash which matches the LDAP password

If the LDAP password changes, the next AuthX API login updates the local hash.

## Testing

### Test Connection

From the settings page, click **Test Connection** to verify:

1. Server reachability
2. TLS / StartTLS
3. Service account bind
4. User search filter
5. Group membership check (if configured)

### Test User Login

From the test page (`/civicrm/saldap/test`), enter an LDAP username + password
to verify the full authentication flow for that specific user, including group
checks and role resolution.

## Troubleshooting

| Symptom | Likely Cause |
|---|---|
| `Can't contact LDAP server` | Wrong host/port, network issue, TLS misconfiguration |
| `Invalid credentials` during LDAP bind | Wrong password or LDAP account requires different auth method |
| `No such object` on search | Wrong Base DN — use `ldapsearch` to find the correct OU |
| User can't log in via web form | First login must happen via API/AuthX to cache the password hash |
| Role not assigned | Group name doesn't match, or group not found in `member` attribute |
| `htmlspecialchars()` error on settings page | Extension not fully enabled — run `cv ext:disable de_cipico_saldap && cv ext:enable de_cipico_saldap` |

## API (Programmatic Configuration)

The extension exposes a `Saldap` APIv4 entity with `get` and `set` actions
for configuring all LDAP settings in a single call.

### Read current settings

```bash
cv api4 Saldap.get
```

### Write all settings at once

Pipe JSON via stdin:

```bash
cat <<'SETTINGS' | cv api4 Saldap.set --in=json
{
  "saldap_ldap_host": "ldap.example.com",
  "saldap_ldap_port": 636,
  "saldap_ldap_base_dn": "dc=example,dc=com",
  "saldap_ldap_bind_dn": "cn=readonly,ou=system,dc=example,dc=com",
  "saldap_ldap_bind_password": "s3cret",
  "saldap_ldap_tls": true,
  "saldap_ldap_auto_create_user": true,
  "saldap_ldap_sync_contact": false,
  "saldap_ldap_user_filter": "(&(objectClass=person)(uid=%s))",
  "saldap_ldap_group_dn": "cn=admins,ou=groups,dc=example,dc=com",
  "saldap_ldap_attr_mail": "mail",
  "saldap_ldap_attr_first_name": "givenName",
  "saldap_ldap_attr_last_name": "sn",
  "saldap_ldap_role_mappings": "cn=employees,ou=groups,dc=example,dc=com|3"
}
SETTINGS
```

Or save the JSON to a file and use `--in`:

```bash
cv api4 Saldap.set --in=config.json
```

### Set individual values

Only the parameters you provide are updated — omitted ones keep their current value.

```bash
cv api Saldap.set saldap_ldap_host=ldap.example.com
cv api Saldap.set saldap_ldap_port=636 saldap_ldap_tls=1
cv api Saldap.set saldap_ldap_group_dn="cn=admins,ou=groups,dc=example,dc=com"
```

All 14 settings are supported — see `settings/Saldap.setting.php` for details.

> **Role mappings format:** Each line is a full group DN followed by `|` and the role ID.
> Use `\n` between multiple mappings in JSON, e.g.
> `"cn=employees,ou=groups,dc=example,dc=com|3\ncn=admins,ou=groups,dc=example,dc=com|2"`.
> Short names like `employees|3` will **not** match — the left side must match the
> full DN as returned by the LDAP server (e.g. from `fetchUserGroups()`).

## Development

Generated with [civix](https://docs.civicrm.org/dev/en/latest/extensions/civix/).

```bash
# Generate a new service
civix generate:service LdapAuthenticator --naming=civi

# Generate a form
civix generate:form LdapTest civicrm/saldap/test
```

## License

AGPL-3.0
