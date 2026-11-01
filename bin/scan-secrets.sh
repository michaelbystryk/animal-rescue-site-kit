#!/usr/bin/env bash
# Scan from the caller's repository, including staged versions of changed files.
set -euo pipefail

case "${1:-}" in
    '') mode=all ;;
    --staged) mode=staged ;;
    -h|--help)
        printf 'Usage: %s [--staged]\nScans tracked working files and staged changes, or staged changes only.\n' "$0"
        exit 0 ;;
    *) printf 'Unknown argument: %s\n' "$1" >&2; exit 2 ;;
esac
if [ "$#" -gt 1 ]; then
    printf 'Usage: %s [--staged]\n' "$0" >&2
    exit 2
fi
if ! command -v python3 >/dev/null 2>&1; then
    printf 'error: python3 is required\n' >&2
    exit 2
fi

python3 -I - "$mode" <<'PY'
import bisect
import html
import json
import os
from pathlib import Path
import re
import shlex
import shutil
import subprocess
import sys
import tempfile


def git(*args):
    return subprocess.check_output(["git", *args], stderr=subprocess.PIPE)


try:
    root = Path(os.fsdecode(git("rev-parse", "--show-toplevel").rstrip(b"\n")))
except subprocess.CalledProcessError:
    print("error: run this scanner inside a Git repository", file=sys.stderr)
    sys.exit(2)
os.chdir(root)
excluded = {"bin/scan-secrets.sh", "tests/sh/test-scan-secrets.sh"}
findings = set()


def report(path, line, rule):
    finding = (path, line, rule)
    if finding not in findings:
        findings.add(finding)
        print(f"{path}:{line}: {rule}")


def paths(output):
    return [os.fsdecode(p) for p in output.split(b"\0") if p]


staged = paths(git("diff", "--cached", "--name-only", "--diff-filter=ACMRT", "-z"))
snapshots = []
selected_names = set(staged)
if sys.argv[1] == "all":
    tracked = set(paths(git("ls-files", "--cached", "-z")))
    selected_names.update(tracked)
    for name in sorted(tracked):
        if name in excluded:
            continue
        path = Path(name)
        # Inspect link text rather than following a link outside the repository.
        if path.is_symlink():
            snapshots.append(("working", name, os.fsencode(os.readlink(path))))
            continue
        if not path.is_file():
            continue
        snapshots.append(("working", name, path.read_bytes()))
for name in sorted(set(staged)):
    if name in excluded:
        continue
    entry = git("ls-files", "--stage", "-z", "--", name)
    if entry.startswith(b"160000 "):
        continue
    snapshots.append(("staged", name, git("show", ":" + name)))

image_suffixes = {".jpg", ".jpeg", ".png", ".webp", ".heic"}
binary_suffixes = image_suffixes | {
    ".gif", ".avif", ".ico", ".woff", ".woff2", ".ttf", ".otf", ".eot",
    ".pdf", ".zip", ".gz", ".mp4", ".mov", ".mp3", ".sqlite",
}


def text_content(name, data):
    if Path(name).suffix.lower() in binary_suffixes or b"\0" in data:
        return None
    controls = sum(byte < 32 and byte not in (9, 10, 12, 13) for byte in data)
    if controls > max(1, len(data) // 100):
        return None
    return data.decode("utf-8", errors="replace")


rules = [
    ("token: posthog", re.compile(r"phc_[A-Za-z0-9]{20,}")),
    ("token: github", re.compile(r"gh[pousr]_[A-Za-z0-9]+")),
    ("token: aws-access-key", re.compile(r"AKIA[0-9A-Z]{16}")),
    ("token: payment-key", re.compile(r"(?:sk|pk)_(?:live|test)_[A-Za-z0-9_\-]+")),
    ("token: secret-assignment", re.compile(
        r"(?:key|secret|token|password)\s*[:=]\s*['\"](?=[A-Za-z0-9_\-]*\d)(?=[A-Za-z0-9_\-]*[A-Za-z])[A-Za-z0-9_\-]{24,}['\"]",
        re.IGNORECASE)),
    ("personal: home-directory-path", re.compile(r"(?:/Users|/home)/[^/\s]+/")),
]
# Optional project-specific rules live in an untracked .scan-secrets.local file.
# One regex per line; "#" starts a comment. Prefixes:
#   org: REGEX            organisation name, allowed in allowed sections
#   case: REGEX           case-sensitive rule (default is case-insensitive)
#   allow-section: FILE :: HEADING   exempt "org:" rules under that README-style heading
local_rules = []
org_rules = []
allowed_sections = []
local_file = root / ".scan-secrets.local"
if local_file.is_file():
    for raw in local_file.read_text(encoding="utf-8", errors="replace").splitlines():
        entry = raw.strip()
        if not entry or entry.startswith("#"):
            continue
        if entry.startswith("allow-section:"):
            target, _, heading = entry[len("allow-section:"):].partition("::")
            allowed_sections.append((target.strip(), heading.strip()))
            continue
        flags = re.IGNORECASE
        target_list = local_rules
        label = "local: denylist"
        while True:
            if entry.startswith("org:"):
                target_list, label = org_rules, "local: organization"
            elif entry.startswith("case:"):
                flags = 0
            else:
                break
            entry = entry.split(":", 1)[1].strip()
        try:
            target_list.append((label, re.compile(entry, flags)))
        except re.error:
            print(f"warning: invalid regex in .scan-secrets.local: {entry}", file=sys.stderr)
email = re.compile(
    r"[A-Z0-9_][A-Z0-9.!#$%&'*+/=?^_`{|}~\-]*@"
    r"([A-Z0-9](?:[A-Z0-9\-]*[A-Z0-9])?"
    r"(?:\.[A-Z0-9](?:[A-Z0-9\-]*[A-Z0-9])?)*)(?![\w./-])", re.IGNORECASE)
allowed_url = re.compile(r"github\.com/[A-Za-z0-9-]+/animal-rescue-site-kit")


def mask_url(line):
    # Preserve offsets while allowing this exact URL anywhere in a file.
    return allowed_url.sub(lambda m: " " * len(m.group()), line)


def scan_text(name, content):
    in_reference = False
    for number, original in enumerate(content.splitlines(), 1):
        line = mask_url(original)
        heading = re.match(r"^##[ \t]+(.*?)[ \t]*#*[ \t]*$", original)
        if heading:
            in_reference = (name, heading.group(1)) in allowed_sections
        for rule, pattern in rules + local_rules:
            if pattern.search(line):
                report(name, number, rule)
        if re.match(r"^\s*(-\s*)?uses:\s", line):
            continue
        for match in email.finditer(line.replace("\\.", ".")):
            if not match.group(1).lower().endswith((".invalid", ".test")):
                report(name, number, "personal: email")
        if not in_reference and not name.startswith("docs/screenshots/reference/"):
            for rule, pattern in org_rules:
                if pattern.search(line):
                    report(name, number, rule)


# Store word offsets so copied text reports the original line, including when
# tags, punctuation or line breaks separate words in the shared passage.
def words(content):
    content = "\n".join(html.unescape(line).replace("\n", " ").replace("\r", " ")
                        for line in content.split("\n"))
    stripped = re.sub(r"<[^>]*>", lambda m: re.sub(r"[^\n]", " ", m.group()), content)
    breaks = [m.start() for m in re.finditer("\n", stripped)]
    result = []
    for match in re.finditer(r"[^\W_]+", stripped, re.UNICODE):
        result.append((match.group().lower(), bisect.bisect_left(breaks, match.start()) + 1))
    return result


source_shingles = set()
source_env = os.environ.get("SCAN_OVERLAP_SOURCE")
if not source_env:
    print("skipped: set SCAN_OVERLAP_SOURCE")
else:
    source = Path(source_env)
    if not source.is_dir():
        print("warning: SCAN_OVERLAP_SOURCE does not exist or is not a directory; overlap check skipped")
    else:
        source_files = set(source.glob("theme/*/patterns/*.php"))
        source_files.update(source.glob("theme/*/patterns/*.html"))
        source_files.update(source.glob("docs/**/*.md"))
        for path in sorted(source_files):
            content = text_content(str(path), path.read_bytes())
            if content is None:
                continue
            # Compare prose only: drop code, styles, scripts, block markup and code blocks.
            content = re.sub(r"(?is)<style\b.*?</style>|<script\b.*?</script>|<!--.*?-->|<\?php.*?(?:\?>|\Z)", " ", content)
            content = re.sub(r"(?s)```.*?```|`[^`\n]*`", " ", content)
            content = re.sub(r"<[^>]*>", " ", content)
            content = re.sub(r"\S*[{};=|/\\]\S*", " ", content)
            tokens = [word for word, _ in words(content)]
            source_shingles.update(tuple(tokens[i:i + 8]) for i in range(len(tokens) - 7))
        print(f"overlap: loaded {len(source_shingles)} source shingles from {len(source_files)} files")

for name in sorted(selected_names - excluded):
    parts = Path(name).parts
    if (Path(name).name in {"CLAUDE.md", "AGENTS.md"}
            or any(part in {".claude", ".codex"} for part in parts[:-1])
            or "transcript" in Path(name).name.lower()
            or name.lower().endswith(".chat.md")):
        report(name, 1, "llm: tracked-file")
for _, name, data in snapshots:
    content = text_content(name, data)
    if content is None:
        continue
    scan_text(name, content)
    if source_shingles:
        # Decode entities on both sides, preserving physical line positions.
        tokens = words(content)
        lines = content.splitlines() or [""]
        for i in range(len(tokens) - 7):
            if tuple(word for word, _ in tokens[i:i + 8]) in source_shingles and "scan-allow: overlap" not in lines[tokens[i][1] - 1]:
                report(name, tokens[i][1], "copied-text: 8-word-overlap")

exiftool = shutil.which("exiftool")
gitleaks = shutil.which("gitleaks")
if not exiftool:
    print("warning: exiftool is not installed; image metadata check skipped (brew install exiftool)")
if not gitleaks:
    print("warning: gitleaks is not installed; install with brew install gitleaks")

with tempfile.TemporaryDirectory(prefix="scan-secrets-") as temporary:
    temp = Path(temporary)
    snapshot_root = temp / "files"
    snapshot_root.mkdir()
    materialized = {}
    for kind, name, data in snapshots:
        # Binary files are inspected for metadata separately, never as text.
        base = snapshot_root if text_content(name, data) is not None else temp / "binary"
        target = base / kind / name
        target.parent.mkdir(parents=True, exist_ok=True)
        target.write_bytes(data)
        materialized[str(target)] = name
        if exiftool and Path(name).suffix.lower() in image_suffixes:
            result = subprocess.run([exiftool, "-j", "-G0", "-a", "-s", str(target)],
                                    capture_output=True, text=True)
            try:
                metadata = json.loads(result.stdout)[0]
                if result.returncode or any(key.endswith(":Error") for key in metadata):
                    raise ValueError("exiftool could not read image")
            except (ValueError, IndexError, KeyError, TypeError):
                report(name, 1, "image-metadata: inspection-failed")
                continue
            if any(key.split(":", 1)[0] in {"EXIF", "XMP", "GPS", "MakerNotes"}
                   or "gps" in key.lower()
                   for key in metadata):
                report(name, 1, "image-metadata: EXIF/XMP/GPS")
                print(f"  fix: exiftool -all= -overwrite_original {shlex.quote(name)}")
    if gitleaks:
        # Directory mode limits the optional scan to the selected snapshots,
        # excludes scanner fixtures, and keeps historical commits out of scope.
        leak_report = temp / "gitleaks.json"
        result = subprocess.run([
            gitleaks, "detect", "--no-banner", "--redact", "--no-git",
            "--source", str(snapshot_root), "--report-format", "json",
            "--report-path", str(leak_report),
        ], capture_output=True, text=True)
        leaks = []
        if leak_report.exists():
            try:
                leaks = json.loads(leak_report.read_text()) or []
            except (ValueError, TypeError):
                report("gitleaks", 1, "tool-error: invalid report")
        for leak in leaks:
            leak_path = str(leak.get("File", "unknown"))
            if leak_path not in materialized:
                leak_path = str(snapshot_root / leak_path)
            name = materialized.get(leak_path, "gitleaks")
            report(name, int(leak.get("StartLine", 1)), "gitleaks: " + str(leak.get("RuleID", "secret")))
        if result.returncode and not leaks:
            # Never echo tool logs, which may include values despite redaction.
            report("gitleaks", 1, f"tool-error: exit {result.returncode}")

if findings:
    print(f"scan-secrets: FAILED ({len(findings)} findings)")
    sys.exit(1)
print("scan-secrets: passed")
PY
