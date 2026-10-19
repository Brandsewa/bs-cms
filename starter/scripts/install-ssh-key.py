import os

key = os.environ.get("SSH_KEY", "").strip()
if "\\n" in key and "\n" not in key:
    key = key.replace("\\n", "\n")

lines = key.replace("\r", "").splitlines()
out = "\n".join([l.strip() for l in lines if l.strip()]) + "\n"

ssh_path = os.path.expanduser("~/.ssh/id_ed25519")
with open(ssh_path, "w") as f:
    f.write(out)
