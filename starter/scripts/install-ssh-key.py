import os

key = os.environ.get("SSH_KEY", "").strip()
print(f"DEBUG: raw SSH_KEY length={len(key)}, contains real newline={'\\n' in key}, contains literal backslash-n={'\\\\n' in key}")
if "\\n" in key and "\n" not in key:
    key = key.replace("\\n", "\n")

lines = [l.strip() for l in key.replace("\r", "").splitlines() if l.strip()]
print(f"DEBUG: parsed {len(lines)} lines")
for i, l in enumerate(lines):
    prefix = l[:15] if len(l) >= 15 else l
    suffix = l[-15:] if len(l) >= 15 else ""
    print(f"  line {i}: len={len(l)} starts='{prefix}' ends='{suffix}'")

out = "\n".join(lines) + "\n"

ssh_path = os.path.expanduser("~/.ssh/id_ed25519")
with open(ssh_path, "w") as f:
    f.write(out)

