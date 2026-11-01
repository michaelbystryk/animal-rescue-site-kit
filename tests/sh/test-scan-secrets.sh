#!/usr/bin/env bash
set -euo pipefail

project_root="$(cd "$(dirname "$0")/../.." && pwd)"
scanner="$project_root/bin/scan-secrets.sh"
test_file="$project_root/tests/sh/test-scan-secrets.sh"
scratch="$(mktemp -d)"
trap 'rm -rf "$scratch"' EXIT
shell_bin="$(command -v bash)"
git_bin="$(command -v git)"
python_bin="$(command -v python3)"
mkdir -p "$scratch/tools"
# Isolate optional tools so these tests also pass on machines with neither tool.
ln -s "$shell_bin" "$scratch/tools/bash"
ln -s "$git_bin" "$scratch/tools/git"
ln -s "$python_bin" "$scratch/tools/python3"
export GIT_CONFIG_NOSYSTEM=1
export GIT_CONFIG_GLOBAL="$scratch/gitconfig"
export GIT_AUTHOR_NAME='Scanner fixture'
export GIT_AUTHOR_EMAIL='fixture@example.invalid'
export GIT_COMMITTER_NAME="$GIT_AUTHOR_NAME"
export GIT_COMMITTER_EMAIL="$GIT_AUTHOR_EMAIL"
unset SCAN_OVERLAP_SOURCE || true
count=0
checks=0

new_repo() {
    count=$((count + 1))
    repo="$scratch/repo-$count"
    mkdir -p "$repo/bin" "$repo/tests/sh"
    "$git_bin" -C "$repo" init -q
    cp "$scanner" "$repo/bin/scan-secrets.sh"
    cp "$test_file" "$repo/tests/sh/test-scan-secrets.sh"
    printf '.scan-secrets.local\n' > "$repo/.gitignore"
    cat > "$repo/.scan-secrets.local" <<'LOCAL'
# Generic fixture rules
example-person
case: Exampleman
org: example-org-name
org: example org ?name
000000
111111
example\.test/donate-handle
allow-section: README.md :: Live reference
LOCAL
}

scan() {
    local expected="$1"
    shift
    local status=0
    output="$(cd "$repo" && PATH="$scratch/tools" "$shell_bin" bin/scan-secrets.sh "$@" 2>&1)" || status=$?
    if { [ "$expected" = pass ] && [ "$status" -ne 0 ]; } ||
       { [ "$expected" = fail ] && [ "$status" -eq 0 ]; }; then
        printf 'FAIL: expected %s, got exit %s in fixture %s\n%s\n' "$expected" "$status" "$count" "$output" >&2
        exit 1
    fi
    checks=$((checks + 1))
}

contains() {
    if ! printf '%s\n' "$output" | grep -F -- "$1" >/dev/null; then
        printf 'FAIL: missing report %s\n%s\n' "$1" "$output" >&2
        exit 1
    fi
}

plant() {
    local value="$1" rule="$2"
    new_repo
    printf 'A harmless first line.\n%s\n' "$value" > "$repo/finding.txt"
    "$git_bin" -C "$repo" add -A
    scan fail
    contains "finding.txt:2: $rule"
}

plant 'phc_FAKE01234567890123456789' 'token: posthog'
for prefix in ghp gho ghu ghs ghr; do
    plant "${prefix}_FAKE01234567890123456789" 'token: github'
done
plant 'AKIAFAKE012345678901' 'token: aws-access-key'
for prefix in sk_live sk_test pk_live pk_test; do
    plant "${prefix}_FAKE01234567890123456789" 'token: payment-key'
done
for name in key secret token password; do
    plant "$name = 'FAKE0123456789012345678901'" 'token: secret-assignment'
done
plant 'PASSWORD: "FAKE0123456789012345678901"' 'token: secret-assignment'
plant 'fixture@example.com' 'personal: email'
plant 'fixture@localhost' 'personal: email'
plant 'fixture@example.invalid.com' 'personal: email'
plant '/Users/fixture/project' 'personal: home-directory-path'
plant '/home/fixture/project' 'personal: home-directory-path'
plant 'Example-Person' 'local: denylist'
plant 'Exampleman' 'local: denylist'
for value in 'Example Org Name' 'example-org-name' 'Example Orgname'; do
    plant "$value" 'local: organization'
done
for value in 000000 111111 'example.test/donate-handle'; do
    plant "$value" 'local: denylist'
done

for name in CLAUDE.md AGENTS.md nested/AGENTS.md .claude/settings.json .codex/config.toml nested/.codex/notes.txt old-transcript.txt conversation.chat.md; do
    new_repo
    mkdir -p "$repo/$(dirname "$name")"
    printf 'Harmless text.\n' > "$repo/$name"
    "$git_bin" -C "$repo" add -A
    scan fail
    contains "$name:1: llm: tracked-file"
done

new_repo
cat > "$repo/README.md" <<'EOF'
# A clean fixture
Contact fixture@example.invalid or fixture@sub.example.test.
The lowercase word exampleman is allowed.
Schema properties '@context' and '@type' are allowed.
https://github.com/someone/animal-rescue-site-kit
## Live reference
Example Org Name and example-org-name are allowed here.
### Nested heading
Example-org-name is still allowed here.
## Another section
Ordinary independent writing.
EOF
mkdir -p "$repo/docs/screenshots/reference"
printf 'Example Org Name example-org-name\n' > "$repo/docs/screenshots/reference/caption.txt"
# Binary contents and untracked files must not trigger text rules.
printf '\000phc_FAKE01234567890123456789' > "$repo/data.bin"
printf 'phc_FAKE01234567890123456789' > "$repo/font.woff2"
printf 'phc_FAKE01234567890123456789' > "$repo/ignored.txt"
"$git_bin" -C "$repo" add bin tests README.md docs data.bin font.woff2
scan pass
contains 'skipped: set SCAN_OVERLAP_SOURCE'
contains 'warning: gitleaks is not installed; install with brew install gitleaks'
contains 'warning: exiftool is not installed'
printf 'example-org-name\n' >> "$repo/README.md"
scan fail
contains 'README.md:12: local: organization'
printf '000000\n' >> "$repo/docs/screenshots/reference/caption.txt"
scan fail
contains 'docs/screenshots/reference/caption.txt:2: local: denylist'
printf '## Live reference\nfixture@example.com\n000000\n' > "$repo/README.md"
scan fail
contains 'README.md:2: personal: email'
contains 'README.md:3: local: denylist'

# Staged scans read index blobs even if the working tree has been cleaned.
new_repo
printf 'Harmless baseline.\n' > "$repo/unchanged.txt"
"$git_bin" -C "$repo" add -A
"$git_bin" -C "$repo" commit -qm 'Fixture baseline'
printf 'example-person\n' > "$repo/unchanged.txt"
scan pass --staged
scan fail
printf 'phc_FAKE01234567890123456789\n' > "$repo/staged.txt"
"$git_bin" -C "$repo" add staged.txt
printf 'Clean working copy.\n' > "$repo/staged.txt"
scan fail --staged
contains 'staged.txt:1: token: posthog'
scan fail
contains 'staged.txt:1: token: posthog'
"$git_bin" -C "$repo" rm --cached -q -f staged.txt
scan pass --staged
# Without a local file only the generic rules apply.
new_repo
rm "$repo/.scan-secrets.local"
printf 'example-person example-org-name 000000\n' > "$repo/plain.txt"
"$git_bin" -C "$repo" add -A
scan pass
new_repo
ln -s '/Users/fixture/missing-target' "$repo/link"
"$git_bin" -C "$repo" add -A
scan fail
contains 'link:1: personal: home-directory-path'
scan fail --staged
contains 'link:1: personal: home-directory-path'

# Copied prose matches across case, tags, punctuation and physical line breaks.
source_dir="$scratch/private-source"
mkdir -p "$source_dir/theme/fixture/patterns" "$source_dir/docs/nested"
printf '<p>Amber, badgers carry distant emerald feathers gently home.</p>\n' > "$source_dir/theme/fixture/patterns/sample.php"
printf '<div>Ivory jellyfish keep little moonlit nests over pebbles.</div>\n' > "$source_dir/theme/fixture/patterns/sample.html"
printf 'Quiet rabbits sail toward unusual violet woodland xylophones.\n' > "$source_dir/docs/nested/story.md"
export SCAN_OVERLAP_SOURCE="$source_dir"
for passage in \
    'AMBER badgers <b>carry</b> distant emerald feathers gently home' \
    'Ivory jellyfish keep little moonlit nests over pebbles' \
    'Quiet rabbits sail toward unusual violet woodland xylophones'; do
    plant "$passage" 'copied-text: 8-word-overlap'
done
new_repo
printf 'Preface.\nAmber badgers carry distant\nemerald feathers gently home.\n' > "$repo/copy.txt"
"$git_bin" -C "$repo" add -A
scan fail
contains 'copy.txt:2: copied-text: 8-word-overlap'
new_repo
printf '<p\nclass="example">\nAmber badgers carry distant emerald feathers gently home.\n</p>\n' > "$repo/copy.html"
"$git_bin" -C "$repo" add -A
scan fail
contains 'copy.html:3: copied-text: 8-word-overlap'
new_repo
printf 'Amber badgers carry distant emerald feathers gently.\n' > "$repo/seven.txt"
"$git_bin" -C "$repo" add -A
scan pass
export SCAN_OVERLAP_SOURCE="$scratch/missing-source"
scan pass
contains 'warning: SCAN_OVERLAP_SOURCE does not exist'
unset SCAN_OVERLAP_SOURCE

# Stub optional tools to verify invocation, selected files, redaction and fixes.
cat > "$scratch/tools/gitleaks" <<'PY'
#!/usr/bin/env python3
import json
from pathlib import Path
import sys
args = sys.argv[1:]
assert args[:3] == ['detect', '--no-banner', '--redact']
assert '--no-git' in args
root = Path(args[args.index('--source') + 1])
findings = []
for path in root.rglob('*'):
    if path.is_file():
        assert path.name not in {'scan-secrets.sh', 'test-scan-secrets.sh'}
        if b'OPTIONAL_TOOL_FINDING' in path.read_bytes():
            findings.append({'File': str(path.relative_to(root)), 'StartLine': 2, 'RuleID': 'fixture-rule'})
Path(args[args.index('--report-path') + 1]).write_text(json.dumps(findings))
sys.exit(1 if findings else 0)
PY
cat > "$scratch/tools/exiftool" <<'PY'
#!/usr/bin/env python3
import json
from pathlib import Path
import sys
assert sys.argv[1:5] == ['-j', '-G0', '-a', '-s']
data = Path(sys.argv[-1]).read_bytes()
metadata = {'File:FileType': 'PNG', 'PNG:ImageWidth': 1}
if b'EXIF_FIXTURE' in data:
    metadata['EXIF:Make'] = 'Fixture camera'
if b'XMP_FIXTURE' in data:
    metadata['XMP:Creator'] = 'Fixture author'
if b'GPS_FIXTURE' in data:
    metadata['QuickTime:GPSCoordinates'] = '1, 1'
print(json.dumps([metadata]))
PY
chmod +x "$scratch/tools/gitleaks" "$scratch/tools/exiftool"
plant OPTIONAL_TOOL_FINDING 'gitleaks: fixture-rule'
new_repo
printf 'Independent text.\n' > "$repo/clean.txt"
"$git_bin" -C "$repo" add -A
scan pass
for extension in jpg jpeg png webp heic; do
    for metadata in EXIF XMP GPS; do
        new_repo
        printf '\000%s_FIXTURE' "$metadata" > "$repo/image.$extension"
        "$git_bin" -C "$repo" add -A
        scan fail
        contains "image.$extension:1: image-metadata: EXIF/XMP/GPS"
        contains "fix: exiftool -all= -overwrite_original image.$extension"
    done
done
new_repo
printf '\000Clean image bytes' > "$repo/clean.png"
"$git_bin" -C "$repo" add -A
scan pass
printf '\000EXIF_FIXTURE' > "$repo/clean.png"
scan pass --staged
"$git_bin" -C "$repo" add clean.png
printf '\000Clean working bytes' > "$repo/clean.png"
scan fail --staged
contains 'clean.png:1: image-metadata: EXIF/XMP/GPS'

printf 'Secret scanner: %s checks passed.\n' "$checks"
