#!/usr/bin/env python3
"""Upload a file to a lab VM over SFTP.

Usage:
    py -3 vm_scp.py <host> <local> <remote>

Password comes from DSH_VM_PASS.
"""
import os
import sys

import paramiko

if len(sys.argv) != 4:
    raise SystemExit("usage: vm_scp.py <host> <local> <remote>")

host, local, remote = sys.argv[1:]
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
sftp = client.open_sftp()
sftp.put(local, remote)
size = sftp.stat(remote).st_size
sftp.close()
client.close()
print(f"uploaded {local} -> {remote} ({size} bytes)")

