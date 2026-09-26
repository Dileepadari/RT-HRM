#!/usr/bin/env bash
#
# Tripwires for defects this repository has actually had. Each one failed at
# some point; each is here so it fails loudly next time instead of quietly.
#
# Usage: ops/hygiene.sh

set -uo pipefail
cd "$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

FAILURES=0
check() {
    local label="$1"; shift
    if "$@"; then
        printf 'ok   %s\n' "$label"
    else
        printf 'FAIL %s\n' "$label"
        FAILURES=$((FAILURES + 1))
    fi
}

text_files() {
    git ls-files | grep -vE '\.(jpg|jpeg|png|gif|webp|ico|jar|apk|aab|keystore)$'
}

# The dashboard fetched every endpoint from an absolute path naming the one
# directory it happened to be deployed under. Anywhere else the page rendered
# but every fetch 404'd, so the charts were empty and the connection badge sat
# on Disconnected with a live reading in the database.
no_hardcoded_deploy_path() {
    ! git ls-files 'server/*' | xargs grep -n "['\"\`]/hr2/" 2>/dev/null | grep .
}

# org.gradle.java.home pinned one machine's JDK by absolute path, so the build
# could not start anywhere that path did not exist. The Java version belongs in
# a toolchain, which Gradle resolves or downloads.
no_pinned_jdk_path() {
    ! grep -q '^org\.gradle\.java\.home' gradle.properties
}

toolchain_declared() {
    grep -q 'jvmToolchain(' app/build.gradle.kts
}

# Without the foojay resolver, a machine with no matching JDK fails with
# "Toolchain download repositories have not been configured" - and that is
# never the machine of whoever removes this line.
toolchain_resolver_configured() {
    grep -q 'foojay-resolver' settings.gradle.kts
}

# textContent does not parse markup. Assigning SVG source to it renders the
# source as visible text, which is what the pause button used to do.
no_markup_in_textcontent() {
    ! git ls-files 'server/*' | xargs grep -n "textContent *= *['\"\`]<" 2>/dev/null | grep .
}

# Credentials and the Firebase config must never be committed.
no_secrets_committed() {
    ! git ls-files | grep -qE '(^|/)(\.env|google-services\.json)$'
}

# Every endpoint has to carry a gate, including ones added later. Nine of them
# once answered anyone who knew the URL. requireReadAccess() admits the API key
# or a dashboard session; validateApiKey() demands the key outright.
all_endpoints_gated() {
    local missing=0 f
    for f in $(git ls-files 'server/api/*.php'); do
        grep -qE 'requireReadAccess\(\)|validateApiKey\(\)' "$f" || { echo "  no gate in $f"; missing=1; }
    done
    [ "$missing" -eq 0 ]
}

# House style: no em dashes, en dashes or emoji in anything tracked.
no_decorative_glyphs() {
    ! text_files | xargs grep -nP '[\x{2013}\x{2014}\x{1F000}-\x{1FAFF}\x{2600}-\x{27BF}\x{FE0F}]' 2>/dev/null | grep .
}

# Every image a README points at has to exist. Matches both Markdown
# ![](path) and the HTML <img src="path"> the tables use.
readme_images_resolve() {
    local missing=0 img found=0
    for img in $( { grep -ohE '\]\(([^)]+\.(jpg|jpeg|png|gif))\)' README.md README_HI.md 2>/dev/null | sed -E 's/^\]\(//; s/\)$//';
                    grep -ohE 'src="([^"]+\.(jpg|jpeg|png|gif))"' README.md README_HI.md 2>/dev/null | sed -E 's/^src="//; s/"$//'; } | sort -u ); do
        found=$((found + 1))
        [ -f "$img" ] || { echo "  missing image: $img"; missing=1; }
    done
    # A check that matched nothing is not a check that passed.
    [ "$found" -gt 0 ] || { echo "  no image references found at all"; missing=1; }
    [ "$missing" -eq 0 ]
}

check "no hardcoded /hr2/ deployment path"      no_hardcoded_deploy_path
check "no machine-pinned org.gradle.java.home"  no_pinned_jdk_path
check "jvmToolchain declared"                   toolchain_declared
check "foojay toolchain resolver configured"    toolchain_resolver_configured
check "no markup assigned to textContent"       no_markup_in_textcontent
check "no .env or google-services.json tracked" no_secrets_committed
check "every API endpoint carries a gate"       all_endpoints_gated
check "no em dashes, en dashes or emoji"        no_decorative_glyphs
check "README images all resolve"               readme_images_resolve

echo
if [ "$FAILURES" -eq 0 ]; then
    echo "hygiene: all checks passed"
else
    echo "hygiene: ${FAILURES} check(s) failed"
fi
exit "$FAILURES"
