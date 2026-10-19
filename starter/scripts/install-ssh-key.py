import os
import re

key = os.environ.get("SSH_KEY", "").strip()

# If headers are missing, or it's just the base64 payload:
HEADER = "-----BEGIN OPENSSH PRIVATE KEY-----"
FOOTER = "-----END OPENSSH PRIVATE KEY-----"

if HEADER in key:
    # Key already includes header
    if "\\n" in key and "\n" not in key:
        key = key.replace("\\n", "\n")
    lines = [l.strip() for l in key.replace("\r", "").splitlines() if l.strip()]
else:
    # Secret contains only the base64 body or single-line body
    # Remove any whitespace
    raw_body = re.sub(r"\s+", "", key)
    # Chunk into 70 character lines standard for OpenSSH
    chunks = [raw_body[i:i+70] for i in range(0, len(raw_body), 70)]
    lines = [HEADER] + chunks + [FOOTER]

out = "\n".join(lines) + "\n"

ssh_path = os.path.expanduser("~/.ssh/id_ed25519")
with open(ssh_path, "w") as f:
    f.write(out)

print(f"Wrote SSH key with {len(lines)} lines to {ssh_path}")


