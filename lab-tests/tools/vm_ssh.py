#!/usr/bin/env python3
"""Tiny SSH helper for the two lab VMs.

Usage:
    py -3 vm_ssh.py <host> <command...>
    py -3 vm_ssh.py --sudo <host> <command...>

The password is read from DSH_VM_PASS and is never printed.
"""
import os
import shlex
import sys

import paramiko

sys.stdout.reconfigure(encoding="utf-8", errors="replace")
sys.stderr.reconfigure(encoding="utf-8", errors="replace")

args = sys.argv[1:]
use_sudo = False
if args and args[0] == "--sudo":
    use_sudo = True
    args = args[1:]

if len(args) < 2:
    raise SystemExit("usage: vm_ssh.py [--sudo] <host> <command...>")

host = args[0]
command = " ".join(args[1:])
password = os.environ.get("DSH_VM_PASS")
if not password:
    raise SystemExit("DSH_VM_PASS is not set")

client = paramiko.SSHClient()
client.set_missing_host_key_policy(paramiko.AutoAddPolicy())
client.connect(
    hostname=host,
    username=os.environ.get("DSH_VM_USER", "example_user"),
    password=password,
    timeout=15,
    banner_timeout=15,
    auth_timeout=15,
    look_for_keys=False,
    allow_agent=False,
)

if use_sudo:
    command = "sudo -S -p '' bash -lc " + shlex.quote(command)

stdin, stdout, stderr = client.exec_command(command, timeout=1800)
if use_sudo:
    stdin.write(password + "\n")
    stdin.flush()

out = stdout.read().decode("utf-8", "replace")
err = stderr.read().decode("utf-8", "replace")
rc = stdout.channel.recv_exit_status()
client.close()

if out:
    sys.stdout.write(out)
if err:
    sys.stderr.write(err)
sys.exit(rc)

