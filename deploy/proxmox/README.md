# Proxmox deployment — Phase 2

Two sibling LXCs, no Docker (architecture §12): one for the app, one for
PostgreSQL. `create-qrid-stack.sh` builds both from scratch; `deploy.sh`
(installed onto the App LXC) handles every deploy after that.

## What this does and doesn't do

Builds:
- Two Ubuntu 24.04 LXCs (unprivileged), sized modestly (2 cores / 2GB RAM /
  8GB disk each by default — this is a few-thousand-row LAN app)
- PostgreSQL 16 in the DB LXC, with a least-privilege app role and minimal
  tuning scaled off the container's own RAM
- PHP 8.3 + Caddy in the App LXC, the repo cloned and deployed behind
  Caddy's automatic HTTPS (`tls internal` — see **Trusting the certificate**
  below)
- A nightly backup cron in each container, dumping to a directory bind-
  mounted from the Proxmox host (`BACKUP_HOST_DIR`, default
  `/var/lib/vz/qrid-backups`) — genuinely off the LXC, not just off the app
- An optional non-root sudo user, identical username/password on both
  containers, if you choose to set one at the prompt (see **Running it**)

## Ports

| Container | Port | What |
|---|---|---|
| `qrid-db` | 5432 | PostgreSQL, reachable only from the App LXC's IP (`pg_hba.conf`) |
| `qrid-db` | 80 | Plain-HTTP landing page + `/health` — confirms the container itself is up without needing a Postgres client |
| `qrid-app` | 443 | Caddy, HTTPS via `tls internal`. Serves the Laravel app — right now that's the default welcome page and the `/up` health route; this is also where the admin dashboards (Phase 5 onward) will live once built, as ordinary routes on this same port/domain, not a separate one |
| `qrid-app` | 80 | Caddy, redirects to 443 |
| `qrid-db` / `qrid-app` | 22 | SSH, from the base Ubuntu template — not configured by this script either way |

Does **not** do, because it can't from inside a container or shouldn't be
automated at all:
- DHCP reservations or internal DNS records — your router/DNS server, not
  this script
- Bootstrapping the two Superadmin accounts (`id:superadmin-create`) — that's
  Phase 3, which doesn't exist yet on a schema-only Phase 2 stack
- Anything resembling a scheduler or queue worker — architecture §7/§12 rule
  that out entirely. The backup cron is the one deliberate exception, named
  as such in the crontab comment.

## Prerequisites

- A Proxmox VE host you can run this as root on
- Outbound internet access from that host and from the containers it
  creates (to fetch the LXC template, apt packages, Composer, npm packages,
  and to `git clone` the repo)
- Enough free space on your chosen storage pool (`local-lvm` by default) for
  two 8GB container disks

## Running it

One-liner, on the Proxmox host, as root — no local checkout needed. The
script fetches its own sibling files (`provision-db.sh`, `provision-app.sh`,
`deploy.sh`, `backup-db.sh`, `backup-app.sh`) from this same repo/branch at
runtime:

```bash
bash -c "$(curl -fsSL https://raw.githubusercontent.com/alrlchoa/qr-id-generator/main/deploy/proxmox/create-qrid-stack.sh)"
```

**It then asks explicitly** for container IDs, hostnames, CPU/RAM/disk,
storage pools, and the app/DB settings — community-scripts-style prompts,
each showing a default in `[brackets]`; press Enter to accept it, or type a
replacement. It also asks whether to create a non-root sudo user (username,
then a password typed twice with no echo) — leave the username blank to
skip and stay root-only. A summary is shown before anything is created,
with a final `Proceed? [Y/n]`.

Exporting a variable first changes the *default shown at the prompt*
rather than skipping it — useful when you want most fields left alone but
a couple pre-filled:

```bash
export CTID_DB=201 CTID_APP=202
export HOSTNAME_DB=condo-db HOSTNAME_APP=condo-app
export MEM_DB_MB=2048 MEM_APP_MB=2048
export DISK_DB_GB=16 DISK_APP_GB=16
export APP_DOMAIN=qrid.mycondo.internal
export BACKUP_HOST_DIR=/mnt/backup-pool/qrid
bash -c "$(curl -fsSL https://raw.githubusercontent.com/alrlchoa/qr-id-generator/main/deploy/proxmox/create-qrid-stack.sh)"
```

For a fully unattended run — no prompts, everything from env vars/defaults
— set `QRID_NONINTERACTIVE=1`. Prompts are also skipped automatically
whenever stdin isn't a terminal (piped input, cron, CI), so scripted use
doesn't need the variable set explicitly in that case.

Or, from a local clone (useful for testing a branch before it's on `main` —
set `REPO_BRANCH` so both the app checkout and the sibling-script fetch
track it):

```bash
git clone https://github.com/alrlchoa/qr-id-generator.git /tmp/qrid-deploy
cd /tmp/qrid-deploy/deploy/proxmox
REPO_BRANCH=my-branch bash create-qrid-stack.sh
```

It prints container IDs, IPs, generated passwords, and a checklist of what's
left to do by hand at the end — **save that output**, the passwords aren't
stored anywhere else.

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

Once DNS resolves `$APP_DOMAIN` to the App LXC's IP (or you're testing by
IP with `-k`, as above):

```bash
curl https://qrid.internal/up
```

A `200 OK` (empty body) means Laravel booted, migrations ran, and Caddy/PHP-FPM
are wired together correctly. This is Phase 2's primary "done when" signal.

The DB container has its own much simpler check — no cert, no domain, just
a landing page confirming the container is up:

```bash
curl http://<db-ip>/         # human-readable landing page
curl http://<db-ip>/health   # -> "OK", for scripted checks
```

## Redeploying after this

Don't re-run `create-qrid-stack.sh` for routine updates — it's meant for
building the stack once. For a new commit on the tracked branch:

```bash
pct exec <app-ctid> -- /opt/qrid/deploy.sh
```

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

## Config variables

All of these can be set as environment variables before running
`create-qrid-stack.sh`, or edited directly at the top of the script:

| Variable | Default | Notes |
|---|---|---|
| `CTID_DB` / `CTID_APP` | auto-assigned | Leave unset to let Proxmox pick the next free IDs. Either way you'll be prompted to confirm or change it (see **Running it**) unless non-interactive |
| `QRID_NONINTERACTIVE` | unset | Set to `1` to skip every prompt and use env vars/defaults as-is. Prompts are also skipped automatically when stdin isn't a terminal |
| `HOSTNAME_DB` / `HOSTNAME_APP` | `qrid-db` / `qrid-app` | |
| `STORAGE` | `local-lvm` | Proxmox storage pool for container root disks |
| `TEMPLATE_STORAGE` | `local` | Storage pool for the LXC template file. Kept separate from `$STORAGE` because LVM-thin pools like `local-lvm` hold disks but don't support the `vztmpl` content type — only a directory storage does |
| `BRIDGE` | `vmbr0` | Network bridge |
| `CORES_DB` / `MEM_DB_MB` / `DISK_DB_GB` | `2` / `2048` / `8` | |
| `CORES_APP` / `MEM_APP_MB` / `DISK_APP_GB` | `2` / `2048` / `8` | |
| `REPO_URL` / `REPO_BRANCH` | this repo / `main` | The app code deployed into the App LXC |
| `REPO_RAW_BASE` | raw.githubusercontent.com path for `$REPO_BRANCH` | Where this script's own sibling files are fetched from when run as the one-liner. Only override to test unmerged sibling-script changes |
| `APP_DOMAIN` | `qrid.internal` | Needs a real DNS record pointed at the App LXC once you have one |
| `DB_NAME` / `DB_USER` | `qr_id_generator` / `qrid` | Matches the local dev defaults from Phase 0 |
| `BACKUP_HOST_DIR` | `/var/lib/vz/qrid-backups` | Point this at different physical storage than `$STORAGE` if you can |
| `SUDO_USERNAME` / `SUDO_PASSWORD` | unset | Pre-fills the sudo-user prompt (or, non-interactively, creates the user outright). Blank username = no sudo user, root-only |
