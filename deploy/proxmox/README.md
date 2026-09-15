# Proxmox deployment

Two sibling LXCs, no Docker (architecture §12): one for the app, one for
PostgreSQL. `create-qrid-stack.sh` builds both from scratch — and, run
inside the App container, updates the app afterwards (see **Updating**).
Phase 2 built it; Phase 15 gave it community-scripts-style menus, checks
every setting before creating anything, and made re-runs safe.

## What this does and doesn't do

Builds:
- Two Ubuntu 24.04 LXCs (unprivileged), sized modestly (2 cores / 2GB RAM /
  8GB disk each by default — this is a few-thousand-row LAN app), tagged
  `qrid-db` / `qrid-app` in the Proxmox UI
- PostgreSQL 16 in the DB LXC, with a least-privilege app role and minimal
  tuning scaled off the container's own RAM
- PHP 8.3 + Caddy in the App LXC, the repo cloned and deployed behind
  Caddy's automatic HTTPS (`tls internal` — see **Trusting the certificate**
  below)
- A nightly backup cron in each container, dumping to a directory bind-
  mounted from the Proxmox host (`BACKUP_HOST_DIR`, default
  `/var/lib/vz/qrid-backups`) — genuinely off the LXC, not just off the app
- An optional non-root sudo user, identical username/password on both
  containers, if you choose to set one (Advanced install)
- A Notes panel on each container's Summary page in the Proxmox UI: what it
  is, its address, and how to update it. No passwords.

## Ports

| Container | Port | What |
|---|---|---|
| `qrid-db` | 5432 | PostgreSQL, reachable only from the App LXC's IP (`pg_hba.conf`) |
| `qrid-db` | 80 | Plain-HTTP landing page + `/health` — confirms the container itself is up without needing a Postgres client |
| `qrid-app` | 443 | Caddy, HTTPS via `tls internal`, serving the Laravel app |
| `qrid-app` | 80 | Caddy, redirects to 443 |
| `qrid-db` / `qrid-app` | 22 | SSH, from the base Ubuntu template — not configured by this script either way |

Does **not** do, because it can't from inside a container or shouldn't be
automated at all:
- DHCP reservations — your router, not this script. The app is served by IP
  only (no domain, no internal DNS to set up), so the App LXC's IP needs to
  stay fixed
- Bootstrapping the two Superadmin accounts. This happens in the browser:
  visit `https://<app-ip>/setup` and create both accounts (architecture
  §12). Deliberately not automated — the operator chooses both passwords —
  but see **Claim the system immediately** below for when to do it
- Anything resembling a scheduler or queue worker — architecture §7/§12 rule
  that out entirely. The backup cron is the one deliberate exception.

## Prerequisites

- A Proxmox VE host you can run this as root on
- Outbound internet access from that host and from the containers it
  creates (to fetch the LXC template, apt packages, Composer, npm packages,
  and to `git clone` the repo)
- Free space for two 8GB container disks on a storage that can hold
  container disks — the script checks this before creating anything

## Running it

One-liner, on the Proxmox host, as root — no local checkout needed. The
script fetches its own helper files (`qrid.func`, `provision-db.sh`,
`provision-app.sh`, `deploy.sh`, `backup-db.sh`, `backup-app.sh`) from this
same repo/branch at runtime:

```bash
bash -c "$(curl -fsSL https://raw.githubusercontent.com/alrlchoa/qr-id-generator/main/deploy/proxmox/create-qrid-stack.sh)"
```

It opens a menu:

- **Default install** — picks everything for you: the next free container
  IDs, `local-lvm` / `local` / `vmbr0` on a stock install (otherwise the
  active storage with the most free space and the first bridge), 2 cores /
  2GB / 8GB each, generated root passwords, no sudo user. You only confirm
  a summary.
- **Advanced install** — every setting as a dialog: container IDs,
  hostnames, cores, RAM and disk for each container; storage, template
  storage and bridge picked from lists of what this host actually has; the
  database and role names; the backup directory; an optional sudo user (or
  your own root password); and whether to show every command's output.
  Each answer is checked as you type it — a used container ID, RAM beyond
  what the host has, a name Postgres won't accept — and you're asked again
  instead of finding out ten minutes in.

Either way, every setting passes one set of checks before anything is
created, and the final screen shows exactly what will be built — including
which containers already exist and will be resumed.

While it builds, each step is one line with a spinner, ending in ✔ or ✖.
Every command's output goes to a log under `/var/log/qrid/` on the host
(root-only — it can contain generated passwords when a step fails). On a
failure you see the failing step and the last lines of that log.

At the end it prints container IDs, IPs, generated passwords, and a
checklist of what's left to do by hand — **save that output**, the
passwords aren't stored anywhere else.

### Presetting values, and unattended runs

Any setting can be set as an environment variable first. It becomes the
value Default uses and the default Advanced shows. `var_*` is the
community-scripts naming; the Phase 2 names still work, so older notes
and automation don't break (full table under **Settings** below):

```bash
export var_db_ctid=201 var_app_ctid=202
export var_db_ram=4096 var_app_disk=16
export var_backup_dir=/mnt/backup-pool/qrid
bash -c "$(curl -fsSL https://raw.githubusercontent.com/alrlchoa/qr-id-generator/main/deploy/proxmox/create-qrid-stack.sh)"
```

For a fully unattended run — no menu, no dialogs — set
`QRID_NONINTERACTIVE=1`. The menu is also skipped automatically whenever
there's no terminal (piped input, cron, CI). The same checks still run
first, and every problem is listed at once.

Or, from a local clone (useful for testing a branch before it's on `main` —
set `REPO_BRANCH` so both the app checkout and the helper-file fetch
track it):

```bash
git clone https://github.com/alrlchoa/qr-id-generator.git /tmp/qrid-deploy
cd /tmp/qrid-deploy/deploy/proxmox
REPO_BRANCH=my-branch bash create-qrid-stack.sh
```

### If a run fails, or you run it again

Re-running is safe. Containers this script created carry the tags
`qrid-db` / `qrid-app`, and a re-run resumes against them instead of
recreating them (stacks built before Phase 15 have no tags and are
recognised by hostname). Anything else already at a chosen ID is refused.
A re-run also sets fresh passwords and re-applies provisioning — the
summary always shows the passwords that are actually in effect.

When a run fails partway, it offers to remove the containers **that run
itself created** — never one that existed before it. Choose No to keep them
for inspection; running the script again picks up where it stopped. To
remove one by hand: `pct destroy <id> --purge`.

## Claim the system immediately

**A freshly deployed stack is unclaimed.** It serves the first-run setup
wizard (architecture §12) to anyone who reaches the App LXC's IP, and **the
first person to complete it becomes both Superadmins**. There is no password
protecting it, because no account exists yet to check against.

That window is inherent to browser-based bootstrap. It is acceptable only
because this system is LAN/VPN-only and never public (§1), and the single
thing that closes it is completing the wizard:

```bash
# From any machine on the LAN, right after the script finishes:
#   https://<app-ip>/setup
```

Deploy and walk away and you have left an unclaimed system on the network.
Once both accounts exist, `/setup` returns 404 permanently and every attempt
to reach it is written to `security_events` as `setup_wizard_blocked`.

Console break-glass remains for a locked-out or emptied Superadmin tier:

```bash
pct exec <app-ctid> -- bash -lc 'cd /opt/qrid/app && php artisan id:superadmin-create <username>'
```

## Trusting the certificate

Caddy's `tls internal` issues a certificate from its own locally-generated
CA — there is no public CA involved and none is needed for a LAN-only
system (architecture §1). Until a client trusts that CA, browsers will show
a warning (not a broken connection — the encryption is real, just
self-signed).

To fetch Caddy's root CA from the App LXC and trust it on a client machine:

```bash
# From the Proxmox host:
pct exec <app-ctid> -- cat /var/lib/caddy/.local/share/caddy/pki/authorities/local/root.crt
```

Import that into your OS/browser's trusted root store. For a quick sanity
check without doing that yet, `curl -k https://<app-ip>/up` (the `-k` flag
skips certificate verification) is enough to confirm the app itself is
answering before you deal with trust.

## Verifying `/up`

The app is served by IP only — no domain, no DNS to set up. Once you've
trusted Caddy's internal CA (above):

```bash
curl https://<app-ip>/up
```

Or skip cert trust for now and bypass verification instead:

```bash
curl -k https://<app-ip>/up
```

A `200 OK` (empty body) means Laravel booted, migrations ran, and Caddy/PHP-FPM
are wired together correctly.

The DB container has its own much simpler check — no cert, no domain, just
a landing page confirming the container is up:

```bash
curl http://<db-ip>/         # human-readable landing page
curl http://<db-ip>/health   # -> "OK", for scripted checks
```

## Updating

To roll out the latest commit on the tracked branch, run **the same
one-liner inside the App container**. It recognises where it is and
updates instead of building:

```bash
# On the Proxmox host, open a shell in the App container...
pct enter <app-ctid>
# ...then, inside it:
bash -c "$(curl -fsSL https://raw.githubusercontent.com/alrlchoa/qr-id-generator/main/deploy/proxmox/create-qrid-stack.sh)"
```

It asks Yes (quiet) / Yes (verbose) / No, then runs `/opt/qrid/deploy.sh`
— pull, build, migrate, restart PHP — and reports the commit it moved from
and to. The log goes to `/var/log/qrid/` inside the container.

Without a terminal it updates quietly, so this works straight from the host
too. `deploy.sh` can still be run directly, as before:

```bash
pct exec <app-ctid> -- /opt/qrid/deploy.sh
```

Run inside the DB container, or on any machine that is neither the
Proxmox host nor the App container, the one-liner refuses and says where
to run it instead. Don't re-run the build on the host for routine updates —
it works, but it resets every password.

## The restore drill (do this — it's part of Phase 2's definition of done)

A backup that has never been restored isn't verified (architecture §12).
After the nightly cron has produced at least one dump:

```bash
# On the Proxmox host, from the DB LXC's backup mount:
pct exec <db-ctid> -- bash -c '
    ls -la /mnt/backup
    sudo -u postgres createdb qr_id_generator_restore_test
    sudo -u postgres pg_restore -d qr_id_generator_restore_test /mnt/backup/qr_id_generator-<timestamp>.dump
    sudo -u postgres psql -d qr_id_generator_restore_test -c "\dt"
    sudo -u postgres dropdb qr_id_generator_restore_test
'
```

Confirm the restored database's tables and row counts look right before
considering Phase 2 actually done — not just "the cron job exists."

## Settings

Set any of these as environment variables before running
`create-qrid-stack.sh`. The `var_*` name wins when both are set.

| Variable | Phase 2 name | Default | Notes |
|---|---|---|---|
| `var_db_ctid` / `var_app_ctid` | `CTID_DB` / `CTID_APP` | next free IDs | The app defaults to the ID after the database's (or, on a re-run, the app container already there) |
| `var_db_hostname` / `var_app_hostname` | `HOSTNAME_DB` / `HOSTNAME_APP` | `qrid-db` / `qrid-app` | |
| `var_db_cpu` / `var_db_ram` / `var_db_disk` | `CORES_DB` / `MEM_DB_MB` / `DISK_DB_GB` | `2` / `2048` / `8` | RAM in MB, disk in GB |
| `var_app_cpu` / `var_app_ram` / `var_app_disk` | `CORES_APP` / `MEM_APP_MB` / `DISK_APP_GB` | `2` / `2048` / `8` | |
| `var_storage` | `STORAGE` | `local-lvm`, else the roomiest | Storage for the container disks (must support `rootdir`) |
| `var_template_storage` | `TEMPLATE_STORAGE` | `local`, else the roomiest | Storage for the LXC template file (must support `vztmpl` — LVM-thin pools like `local-lvm` don't) |
| `var_bridge` | `BRIDGE` | `vmbr0`, else the first bridge | |
| `var_db_name` / `var_db_user` | `DB_NAME` / `DB_USER` | `qr_id_generator` / `qrid` | Letters, digits and underscores only |
| `var_backup_dir` | `BACKUP_HOST_DIR` | `/var/lib/vz/qrid-backups` | On the Proxmox host. Point it at different physical storage than the container disks if you can |
| `var_sudo_user` / `var_sudo_password` | `SUDO_USERNAME` / `SUDO_PASSWORD` | unset | Blank = no sudo user, root-only. Can't be `root` |
| `var_root_password` | — | generated | Root's password on both containers. Blank = a generated one per container, shown at the end |
| `var_verbose` | `QRID_VERBOSE` | `no` | `yes` streams every command's output instead of only logging it |
| `QRID_NONINTERACTIVE` | | unset | `1` skips the menu and every dialog. Also automatic with no terminal |
| `REPO_URL` / `REPO_BRANCH` | | this repo / `main` | The app code deployed into the App LXC |
| `REPO_RAW_BASE` | | raw.githubusercontent.com path for `$REPO_BRANCH` | Where the helper files are fetched from when run as the one-liner. Only override to test unmerged changes to them |
