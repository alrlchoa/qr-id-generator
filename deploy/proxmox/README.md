# Proxmox deployment

Each Condo ID **stack** is two sibling LXCs, no Docker (architecture §12):
one for the app, one for PostgreSQL. One Proxmox host can hold several
stacks — one per condo, say — each fully separate. `create-qrid-stack.sh`
builds a stack, re-runs one, and — run inside an App container — updates
the app (see **Updating**). Phase 2 built it; Phase 15 gave it
community-scripts-style menus, multiple stacks per host, checks every
setting before creating anything, and made re-runs safe.

## What this does and doesn't do

Builds, per stack:
- Two Ubuntu 24.04 LXCs (unprivileged), sized modestly (2 cores / 2GB RAM /
  8GB disk each by default — this is a few-thousand-row LAN app), tagged in
  the Proxmox UI with the stack's name
- PostgreSQL 16 in the DB LXC, with a least-privilege app role and minimal
  tuning scaled off the container's own RAM
- PHP 8.3 + Caddy in the App LXC, the repo cloned and deployed behind
  Caddy's automatic HTTPS (`tls internal` — see **Trusting the certificate**
  below)
- A nightly backup cron in each container, dumping to a directory on the
  Proxmox host bind-mounted into it — its own directory per stack, so
  stacks never mix or prune each other's backups
- An optional non-root sudo user, identical username/password on both
  containers, if you choose to set one (Advanced install)
- A Notes panel on each container's Summary page in the Proxmox UI: which
  stack it belongs to, its address, and how to update it. No passwords.

## Ports

Per stack:

| Container | Port | What |
|---|---|---|
| database | 5432 | PostgreSQL, reachable only from its own App LXC's IP (`pg_hba.conf`) |
| database | 80 | Plain-HTTP landing page + `/health` — confirms the container itself is up without needing a Postgres client |
| app | 443 | Caddy, HTTPS via `tls internal`, serving the Laravel app |
| app | 80 | Caddy, redirects to 443 |
| both | 22 | SSH, from the base Ubuntu template — not configured by this script either way |

Does **not** do, because it can't from inside a container or shouldn't be
automated at all:
- DHCP reservations — your router, not this script. The app is served by IP
  only (no domain, no internal DNS to set up), so each App LXC's IP needs to
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
- Free space for two 8GB container disks per stack, on a storage that can
  hold container disks — the script checks this before creating anything

## Running it

One-liner, on the Proxmox host, as root — no local checkout needed. The
script fetches its own helper files (`qrid.func`, `provision-db.sh`,
`provision-app.sh`, `deploy.sh`, `backup-db.sh`, `backup-app.sh`) from this
same repo/branch at runtime:

```bash
bash -c "$(curl -fsSL https://raw.githubusercontent.com/alrlchoa/qr-id-generator/main/deploy/proxmox/create-qrid-stack.sh)"
```

It opens a menu:

- **New stack — Default install** — picks everything for you: the stack's
  name (see below), the next free container IDs, `local-lvm` / `local` /
  `vmbr0` on a stock install (otherwise the active storage with the most
  free space and the first bridge), 2 cores / 2GB / 8GB each, generated
  root passwords, no sudo user. You only confirm a summary.
- **New stack — Advanced install** — every setting as a dialog, starting
  with the stack's name: container IDs, hostnames, cores, RAM and disk for
  each container; storage, template storage and bridge picked from lists
  of what this host actually has; the database and role names; the backup
  directory; an optional sudo user (or your own root password); and whether
  to show every command's output. Each answer is checked as you type it — a
  used container ID, RAM beyond what the host has, a name Postgres won't
  accept — and you're asked again instead of finding out ten minutes in.
- **Re-run an existing stack** — shown once this host has a stack. See
  **If a run fails, or you run it again** below.

Whichever you pick, every setting passes one set of checks before anything
is created, and the final screen shows exactly what will happen — which
containers are new and which already exist and will be resumed.

While it runs, each step is one line with a spinner, ending in ✔ or ✖.
Every command's output goes to a log under `/var/log/qrid/` on the host
(root-only — it can contain generated passwords when a step fails). On a
failure you see the failing step and the last lines of that log.

At the end it prints the stack's name, container IDs, IPs, generated
passwords, and a checklist of what's left to do by hand — **save that
output**, the passwords aren't stored anywhere else.

## Several stacks on one host

Every stack has a name. It's stored as a Proxmox tag (`qrid-stack-<name>`)
on both of its containers, alongside `qrid-db` or `qrid-app`, and it keeps
stacks apart:

| | First stack on a host | Every later stack |
|---|---|---|
| Name (Default install) | `main` | `stack2`, `stack3`, … — or your own in Advanced, e.g. `tower-a` |
| Hostnames | `qrid-db` / `qrid-app` | `qrid-<name>-db` / `qrid-<name>-app` |
| Backups on the host | `/var/lib/vz/qrid-backups/{db,app}` | `/var/lib/vz/qrid-backups-<name>/{db,app}` |

`main` keeps exactly the layout from before stacks had names, so a stack
built by the Phase 2 script is recognised as `main` — by its default
hostnames, since it has no tags — and tagged the first time you re-run it.

Stack names are 1–20 lowercase letters, digits or hyphens, starting with a
letter. Nothing is shared between stacks: each has its own containers, its
own database, its own backups, its own Superadmins, and its own IP to
reserve and trust.

The script only ever resumes a container as part of its own stack. Pick a
container ID that belongs to another stack and it refuses, naming the
stack; pick one that isn't a Condo ID container at all and it refuses too.

### Presetting values, and unattended runs

Any setting can be set as an environment variable first. It becomes the
value Default uses and the default Advanced shows. `var_*` is the
community-scripts naming; the Phase 2 names still work, so older notes
and automation don't break (full table under **Settings** below):

```bash
export var_instance=tower-a
export var_db_ctid=301 var_app_ctid=302
export var_db_ram=4096 var_app_disk=16
bash -c "$(curl -fsSL https://raw.githubusercontent.com/alrlchoa/qr-id-generator/main/deploy/proxmox/create-qrid-stack.sh)"
```

For a fully unattended run — no menu, no dialogs — set
`QRID_NONINTERACTIVE=1`. The menu is also skipped automatically whenever
there's no terminal (piped input, cron, CI). Unattended, `var_instance`
naming a stack that exists re-runs it; anything else builds a new stack.
The same checks still run first, and every problem is listed at once.

Or, from a local clone (useful for testing a branch before it's on `main` —
set `REPO_BRANCH` so both the app checkout and the helper-file fetch
track it):

```bash
git clone https://github.com/alrlchoa/qr-id-generator.git /tmp/qrid-deploy
cd /tmp/qrid-deploy/deploy/proxmox
REPO_BRANCH=my-branch bash create-qrid-stack.sh
```

### If a run fails, or you run it again

Choose **Re-run an existing stack** and pick the stack — or run
unattended with `var_instance=<name>`. Its container IDs, hostnames,
backup directory and database names are read back from the stack's own
containers, and its containers are resumed, not recreated. Provisioning is
re-applied and every password is reset; the summary always shows the
passwords actually in effect. A stack whose first run died partway (only
its database container exists, say) is finished the same way.

When a run fails partway, it offers to remove the containers **that run
itself created** — never one that existed before it. Choose No to keep them
for inspection; re-running the stack picks up where it stopped. To remove
one by hand: `pct destroy <id> --purge`.

A Phase 2 stack built with **custom** hostnames has neither tags nor the
default hostnames to be recognised by. Re-run it once unattended as
`main`, giving its IDs and hostnames, and it's tagged from then on:

```bash
QRID_NONINTERACTIVE=1 var_instance=main var_db_ctid=201 var_app_ctid=202 \
    var_db_hostname=condo-db var_app_hostname=condo-app \
    bash -c "$(curl -fsSL https://raw.githubusercontent.com/alrlchoa/qr-id-generator/main/deploy/proxmox/create-qrid-stack.sh)"
```

## Claim the system immediately

**A freshly deployed stack is unclaimed.** It serves the first-run setup
wizard (architecture §12) to anyone who reaches its App LXC's IP, and **the
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
self-signed). Each stack's App LXC has its own CA.

To fetch Caddy's root CA from an App LXC and trust it on a client machine:

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

Every container the script builds has an **`update`** command. Open either
container of a stack and type it — it works out which container it's in:

```bash
# On the Proxmox host:
pct enter <ctid>
# ...then, inside the container:
update
```

| In the | `update` does |
|---|---|
| app container | pulls the latest code on the stack's branch (`main`, normally), builds, migrates and restarts PHP (`deploy.sh`), then installs the container's OS package updates |
| database container | installs the container's OS package updates — PostgreSQL's minor releases and security fixes included — and checks PostgreSQL is still running. The data isn't touched; migrations run from the app container's update |

It asks Yes (quiet) / Yes (verbose) / No, then shows one line per step and
reports the commit the app moved from and to. The log goes to
`/var/log/qrid/` inside the container. Without a terminal it goes ahead
quietly, so this works straight from the host too:

```bash
pct exec <ctid> -- update
```

Each stack updates on its own, and so does each container — do the ones
you want updated.

**What's behind it.** `update` downloads the current
`create-qrid-stack.sh` from the stack's branch and runs it in the
container. Before updating anything it refreshes the container's copies of
the Condo ID scripts (`deploy.sh`, the backup script, and `update` itself),
so a fix to any of them reaches existing stacks on their next update, not
only new ones. Package upgrades keep the config files this system edited —
the Caddyfile, PHP-FPM's pool config — instead of stopping to ask; a new
package default lands beside yours as `*.dpkg-dist`. Nothing updates on its
own: an update runs because someone typed it (architecture §7).

`REPO_BRANCH=<branch> update` runs another branch's version once — for
testing a fix before it's merged.

**Containers built before `update` existed** don't have it yet. Run the
one-liner inside the container once (it recognises the container the same
way and updates it), or re-run the stack from the host — either installs
`update` for next time:

```bash
bash -c "$(curl -fsSL https://raw.githubusercontent.com/alrlchoa/qr-id-generator/main/deploy/proxmox/create-qrid-stack.sh)"
```

`deploy.sh` can still be run directly, as before:
`pct exec <app-ctid> -- /opt/qrid/deploy.sh`. Don't re-run a stack on the
host for routine updates — it works, but it resets every password.

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
| `var_instance` | `QRID_INSTANCE` | `main`, then `stack2`, `stack3`… | The stack's name. Naming a stack that exists re-runs it |
| `var_db_ctid` / `var_app_ctid` | `CTID_DB` / `CTID_APP` | next free IDs | The app defaults to the ID after the database's |
| `var_db_hostname` / `var_app_hostname` | `HOSTNAME_DB` / `HOSTNAME_APP` | `qrid-db` / `qrid-app` for `main`, else `qrid-<name>-db` / `-app` | |
| `var_db_cpu` / `var_db_ram` / `var_db_disk` | `CORES_DB` / `MEM_DB_MB` / `DISK_DB_GB` | `2` / `2048` / `8` | RAM in MB, disk in GB |
| `var_app_cpu` / `var_app_ram` / `var_app_disk` | `CORES_APP` / `MEM_APP_MB` / `DISK_APP_GB` | `2` / `2048` / `8` | |
| `var_storage` | `STORAGE` | `local-lvm`, else the roomiest | Storage for the container disks (must support `rootdir`) |
| `var_template_storage` | `TEMPLATE_STORAGE` | `local`, else the roomiest | Storage for the LXC template file (must support `vztmpl` — LVM-thin pools like `local-lvm` don't) |
| `var_bridge` | `BRIDGE` | `vmbr0`, else the first bridge | |
| `var_db_name` / `var_db_user` | `DB_NAME` / `DB_USER` | `qr_id_generator` / `qrid` | Letters, digits and underscores only. Re-running a stack, read back from it |
| `var_backup_dir` | `BACKUP_HOST_DIR` | `/var/lib/vz/qrid-backups` for `main`, else `/var/lib/vz/qrid-backups-<name>` | On the Proxmox host; each stack needs its own. Different physical storage than the container disks is better |
| `var_sudo_user` / `var_sudo_password` | `SUDO_USERNAME` / `SUDO_PASSWORD` | unset | Blank = no sudo user, root-only. Can't be `root` |
| `var_root_password` | — | generated | Root's password on both containers. Blank = a generated one per container, shown at the end |
| `var_verbose` | `QRID_VERBOSE` | `no` | `yes` streams every command's output instead of only logging it |
| `QRID_NONINTERACTIVE` | | unset | `1` skips the menu and every dialog. Also automatic with no terminal |
| `REPO_URL` / `REPO_BRANCH` | | this repo / `main` | The app code deployed into the App LXC |
| `REPO_RAW_BASE` | | raw.githubusercontent.com path for `$REPO_BRANCH` | Where the helper files are fetched from when run as the one-liner. Only override to test unmerged changes to them |
