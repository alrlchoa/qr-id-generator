# qr-id-generator

Change for the better

**Condo ID System** — issues ID cards to a condominium's residents and staff,
and lets the guardhouse verify any card by scanning its QR code. Built with
Laravel, Livewire and PostgreSQL, and meant for a condo's own local network:
it is never exposed to the public internet.

## Install on Proxmox (helper script)

The Proxmox helper script builds a complete, working system — a **stack** —
in two containers: one for the app, one for its PostgreSQL database.

**You need:**

- A Proxmox VE host, and a root shell on it (the host's **Shell** button in
  the Proxmox web UI works)
- Internet access for the host and for the containers it creates
- About 16 GB free on your container storage (two 8 GB disks)

**Run this in the Proxmox host's shell, as root:**

```bash
bash -c "$(curl -fsSL https://raw.githubusercontent.com/alrlchoa/qr-id-generator/main/deploy/proxmox/create-qrid-stack.sh)"
```

A menu opens:

- **New stack — Default install** picks every setting for you: container IDs,
  storage, network, 2 CPU cores / 2 GB RAM / 8 GB disk per container. You
  only confirm a summary. Choose this if you're unsure.
- **New stack — Advanced install** asks for each setting instead, and checks
  each answer as you type it.

Nothing is created until every setting has been checked. The build then
takes several minutes, showing one line per step. When it finishes it
prints the containers' addresses, their generated passwords, and a
checklist — **save that output**; the passwords aren't stored anywhere else.

**Right after it finishes:**

1. **Claim the system immediately.** Open `https://<app-ip>/setup` in a
   browser on your network and create the two Superadmin accounts. Until
   you do, anyone on the network who opens that address can claim the
   system.
2. **Reserve the app's IP address** in your router's DHCP settings. The app
   is reached by its IP, so the address must not change.
3. **Trust the app's certificate.** The app uses its own self-signed
   certificate, so browsers warn on the first visit. The detailed guide
   below shows how to trust it.

One Proxmox host can run several stacks — one per condo, say. Run the same
command again and choose **New stack** for another. Each stack gets its own
name, containers, database and backups. **Re-run an existing stack** repairs
or finishes one that's already there.

## Update

Every container the script builds has an **`update`** command. On the
Proxmox host, open a shell in a container and type it:

```bash
pct enter <container-id>
```

```bash
update
```

`update` works out which container it's in:

| Container | What `update` does |
|---|---|
| App | Pulls the latest code from `main`, rebuilds, applies database migrations, restarts the app, then installs operating-system updates |
| Database | Installs operating-system updates, PostgreSQL's included, and checks the database is still running |

Update both containers of a stack. The container IDs are in the Proxmox web
UI, and each container's **Summary → Notes** says which stack it belongs to
and which one it is.

To update without opening a shell, run this from the Proxmox host instead:

```bash
pct exec <container-id> -- update
```

**Containers built before the `update` command existed** don't have it yet.
Open the container and run the install command above once, inside it. It
recognises the container, updates it, and installs `update` for next time.

Nothing updates on its own — an update happens only when someone runs it.

## More detail

[`deploy/proxmox/README.md`](deploy/proxmox/README.md) covers everything
else: every setting and how to preset it, running several stacks, re-running
after a failure, trusting the certificate, verifying the install, backups
and the restore drill, and what `update` does behind the scenes.
