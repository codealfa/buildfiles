#!/bin/bash

# ==============================================================================
# pending_work.sh — Overview of pending work and releases across repositories
# ==============================================================================

set -euo pipefail

# Parse flags
OUTPUT_HTML=0
FORCE_REFRESH=0
for arg in "$@"; do
    case "$arg" in
        --html) OUTPUT_HTML=1 ;;
        --force) FORCE_REFRESH=1 ;;
        --help|-h)
            cat <<'USAGE'
pending_work.sh — Overview of pending work and releases across repositories

Scans Git working copies under ~/Projects (akeeba, j4-akeeba, dionysopoulos)
and open GitHub issues to produce a consolidated status report.

Report sections:
  Dirty             Repos with uncommitted changes
  Outdated          Repos whose latest tag is older than 21 days (no new work)
  Pending Releases  Repos with unreleased changelog entries (includes excerpts)
  Open GitHub Issues  Open issues in org:akeeba and user:nikosdion

Usage:
  ./pending_work.sh [OPTIONS]

Options:
  --html    Output the report as a standalone HTML 5 document
  --force   Bypass the 6-hour GitHub issues cache and fetch fresh data
  --help    Show this help message
USAGE
            exit 0
            ;;
    esac
done

PROJECTS_DIR="$HOME/Projects"
ALLOWED_ORGS="akeeba|j4-akeeba|dionysopoulos"
EXCLUDED_REPOS="tpl_andromeda|angie|angie2|usagestats|fef|fef-1.x|hexbound|tpl_cassiopeia_twentytwo|devskills"
OUTDATED_DAYS=21

# ANSI colors
RED='\033[1;31m'
YELLOW='\033[1;33m'
GREEN='\033[1;32m'
CYAN='\033[1;36m'
MAGENTA='\033[1;35m'
BOLD='\033[1m'
DIM='\033[2m'
RESET='\033[0m'

# ==============================================================================
# Step 1: Build the list of actionable directories
# ==============================================================================

mapfile -t actionable_dirs < <(
    find "$PROJECTS_DIR" -mindepth 3 -maxdepth 3 -type f -name "CHANGELOG*" \
        | while read -r f; do
            dir="$(dirname "$f")"
            # Extract org and repo name from path
            rel="${dir#$PROJECTS_DIR/}"
            org="$(echo "$rel" | cut -d/ -f1)"
            repo="$(echo "$rel" | cut -d/ -f2)"

            # Filter by allowed orgs
            if ! echo "$org" | grep -qE "^($ALLOWED_ORGS)$"; then
                continue
            fi

            # Filter out excluded repos
            if echo "$repo" | grep -qE "^($EXCLUDED_REPOS)$"; then
                continue
            fi

            echo "$dir"
        done \
        | sort -u
)

if [[ ${#actionable_dirs[@]} -eq 0 ]]; then
    echo "No actionable directories found."
    exit 0
fi

# ==============================================================================
# Step 2: Classify each directory
# ==============================================================================

dirty_dirs=()
updated_dirs=()
outdated_dirs=()

declare -A latest_tags
declare -A tag_dates
declare -A changelog_versions
declare -A changelog_excerpts

get_changelog_file() {
    local dir="$1"
    if [[ -f "$dir/CHANGELOG.md" ]]; then
        echo "$dir/CHANGELOG.md"
    elif [[ -f "$dir/CHANGELOG" ]]; then
        echo "$dir/CHANGELOG"
    else
        echo ""
    fi
}

# Extract the first (latest) version from a CHANGELOG file.
# Handles formats like:
#   "SoftwareName 1.2.3"  (plain or after <?php die() ?>)
#   "# SoftwareName 1.2.3"
#   "## 1.2.3"
get_changelog_version() {
    local file="$1"
    grep -oP '(\d+\.\d+\.\d+)' "$file" | head -1
}

# Extract the changelog excerpt for the first (upcoming) version.
# This includes the version header line and all following content lines
# until the next version header or end of meaningful content.
get_changelog_excerpt() {
    local file="$1"
    local version="$2"

    if [[ -z "$version" ]]; then
        echo ""
        return
    fi

    local in_section=0
    local result=""
    local is_md=0

    if [[ "$file" == *.md ]]; then
        is_md=1
    fi

    while IFS= read -r line; do
        # Skip <?php die() ?> lines
        if [[ "$line" == "<?php"* ]]; then
            continue
        fi

        if [[ $in_section -eq 0 ]]; then
            # Look for the line containing our version
            if echo "$line" | grep -qP "(^|\s)${version//./\\.}(\s|$)"; then
                in_section=1
                result="$line"
            fi
        else
            # Check if we've hit the next version header
            if [[ $is_md -eq 1 ]]; then
                # Markdown: next version starts with # followed by text containing a version number
                if echo "$line" | grep -qP '^#\s+.*\d+\.\d+\.\d+'; then
                    break
                fi
            else
                # Plain CHANGELOG: next version is a line with a version number pattern
                # but we need to distinguish from changelog entries
                # Version lines typically are like "SoftwareName X.Y.Z" or just "X.Y.Z"
                # and are followed by a line of ====
                if echo "$line" | grep -qP '^\S.*\d+\.\d+\.\d+\s*$' && [[ "$line" != *"#"* ]] && [[ "$line" != *"~"* ]] && [[ "$line" != *"+"* ]] && [[ "$line" != *"!"* ]]; then
                    break
                fi
            fi

            # Skip separator lines (=====)
            if echo "$line" | grep -qP '^={3,}'; then
                continue
            fi

            # Accumulate content (skip empty lines at the beginning)
            if [[ -n "$line" ]] || [[ -n "$result" && "$result" != *$'\n' ]]; then
                result="$result"$'\n'"$line"
            fi
        fi
    done < "$file"

    # Trim trailing empty lines
    echo "$result" | sed -e :a -e '/^\n*$/{$d;N;ba' -e '}'
}

now_epoch=$(date +%s)
cutoff_epoch=$((now_epoch - OUTDATED_DAYS * 86400))

for dir in "${actionable_dirs[@]}"; do
    # --- Check dirty status ---
    if git -C "$dir" status --porcelain 2>/dev/null | grep -qE '^[ MADRCU?!]{1,2} '; then
        dirty_dirs+=("$dir")
    fi

    # --- Get latest tag ---
    latest_tag=$(git -C "$dir" tag --sort=-creatordate 2>/dev/null | head -1)

    if [[ -z "$latest_tag" ]]; then
        latest_tag_version="0.0.1"
        tag_epoch=946684800  # 2000-01-01 00:00:00 UTC
    else
        # Strip leading 'v' if present
        latest_tag_version="${latest_tag#v}"
        tag_date_str=$(git -C "$dir" log -1 --format='%ai' "$latest_tag" 2>/dev/null)
        tag_epoch=$(date -d "$tag_date_str" +%s 2>/dev/null || echo 946684800)
    fi

    latest_tags["$dir"]="${latest_tag:-none}"
    tag_dates["$dir"]="$tag_epoch"

    # --- Get changelog version ---
    changelog_file=$(get_changelog_file "$dir")
    if [[ -n "$changelog_file" ]]; then
        cl_version=$(get_changelog_version "$changelog_file")
        cl_version="${cl_version:-0.0.0}"
    else
        cl_version="0.0.0"
    fi
    changelog_versions["$dir"]="$cl_version"

    # --- Classify ---
    is_updated=0
    is_outdated=0

    if [[ "$latest_tag_version" != "$cl_version" ]]; then
        is_updated=1
    fi

    if [[ "$tag_epoch" -lt "$cutoff_epoch" ]]; then
        is_outdated=1
    fi

    if [[ $is_updated -eq 1 && $is_outdated -eq 1 ]]; then
        # Pending release: both updated and outdated
        # Pre-compute the changelog excerpt
        if [[ -n "$changelog_file" ]]; then
            excerpt=$(get_changelog_excerpt "$changelog_file" "$cl_version")
        else
            excerpt=""
        fi
        changelog_excerpts["$dir"]="$excerpt"
        # Will be added to pending_releases below
    elif [[ $is_updated -eq 1 ]]; then
        updated_dirs+=("$dir")
    elif [[ $is_outdated -eq 1 ]]; then
        outdated_dirs+=("$dir")
    fi
done

# Build pending releases array (both updated and outdated)
pending_releases=()
for dir in "${actionable_dirs[@]}"; do
    latest_tag_version="${latest_tags[$dir]#v}"
    [[ "${latest_tags[$dir]}" == "none" ]] && latest_tag_version="0.0.1"
    cl_version="${changelog_versions[$dir]}"
    tag_epoch="${tag_dates[$dir]}"

    if [[ "$latest_tag_version" != "$cl_version" ]] && [[ "$tag_epoch" -lt "$cutoff_epoch" ]]; then
        pending_releases+=("$dir")
    fi
done

# ==============================================================================
# Step 3: Fetch open GitHub issues (cached for 6 hours)
# ==============================================================================

CACHE_DIR="${XDG_CACHE_HOME:-$HOME/.cache}/pending_work"
CACHE_FILE="$CACHE_DIR/github_issues.json"
CACHE_MAX_AGE=$((6 * 3600))

mkdir -p "$CACHE_DIR"

use_cache=0
if [[ $FORCE_REFRESH -eq 0 && -f "$CACHE_FILE" ]]; then
    cache_mtime=$(stat -c %Y "$CACHE_FILE" 2>/dev/null || echo 0)
    if (( now_epoch - cache_mtime < CACHE_MAX_AGE )); then
        use_cache=1
    fi
fi

if [[ $use_cache -eq 1 ]]; then
    github_issues_json=$(cat "$CACHE_FILE")
else
    # Fetch from both org:akeeba and user:nikosdion, merge and deduplicate.
    # Both queries apply the same filters: open issues in non-archived repos.
    issues_akeeba=$(gh search issues --state=open --archived=false \
        --owner=akeeba --json number,title,repository,createdAt --limit=200 2>/dev/null || echo '[]')
    issues_nikosdion=$(gh search issues --state=open --archived=false \
        --owner=nikosdion --json number,title,repository,createdAt --limit=200 2>/dev/null || echo '[]')

    # Merge, deduplicate by repo+number, sort by createdAt descending
    github_issues_json=$(printf '%s\n%s' "$issues_akeeba" "$issues_nikosdion" \
        | jq -s 'add | unique_by(.repository.nameWithOwner + "#" + (.number|tostring)) | sort_by(.createdAt) | reverse')

    echo "$github_issues_json" > "$CACHE_FILE"
fi

# Parse issues into parallel arrays
mapfile -t issue_numbers < <(echo "$github_issues_json" | jq -r '.[].number')
mapfile -t issue_titles < <(echo "$github_issues_json" | jq -r '.[].title')
mapfile -t issue_repos < <(echo "$github_issues_json" | jq -r '.[].repository.nameWithOwner')
mapfile -t issue_dates < <(echo "$github_issues_json" | jq -r '.[].createdAt')

# ==============================================================================
# Step 4: Format tag date for display (Europe/Athens timezone)
# ==============================================================================

format_tag_date() {
    local epoch="$1"
    TZ="Europe/Athens" date -d "@$epoch" "+%d/%m/%Y %H:%M:%S"
}

format_iso_date() {
    local iso="$1"
    local epoch
    epoch=$(date -d "$iso" +%s 2>/dev/null || echo 0)
    TZ="Europe/Athens" date -d "@$epoch" "+%d/%m/%Y"
}

# ==============================================================================
# Step 5: Output the report
# ==============================================================================

rel_path() {
    echo "${1#$PROJECTS_DIR/}"
}

# Determine priority emoji from changelog excerpt:
#   🚨 if top priority (lines starting with "! ")
#   ‼️  if high priority (lines with "# [HIGH]")
#   empty otherwise
pending_emoji() {
    local excerpt="$1"
    if echo "$excerpt" | grep -qP '^! '; then
        echo "🚨"
    elif echo "$excerpt" | grep -qP '^# \[HIGH\]'; then
        echo "‼️"
    else
        echo ""
    fi
}

pending_emoji_html() {
    local excerpt="$1"
    if echo "$excerpt" | grep -qP '^! '; then
        echo "&#x1F6A8; "
    elif echo "$excerpt" | grep -qP '^# \[HIGH\]'; then
        echo "&#x203C;&#xFE0F; "
    else
        echo ""
    fi
}

html_escape() {
    local s="$1"
    s="${s//&/&amp;}"
    s="${s//</&lt;}"
    s="${s//>/&gt;}"
    s="${s//\"/&quot;}"
    echo "$s"
}

if [[ $OUTPUT_HTML -eq 1 ]]; then
    # =========================================================================
    # HTML output
    # =========================================================================
    report_date=$(TZ="Europe/Athens" date "+%d/%m/%Y %H:%M:%S")

    cat <<'HTMLHEAD'
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Pending Work Report</title>
<style>
  :root {
    --bg: #0d1117; --fg: #e6edf3; --muted: #8b949e;
    --red: #f85149; --yellow: #d29922; --green: #3fb950;
    --cyan: #58a6ff; --magenta: #bc8cff;
    --card-bg: #161b22; --border: #30363d;
  }
  @media (prefers-color-scheme: light) {
    :root {
      --bg: #ffffff; --fg: #1f2328; --muted: #656d76;
      --red: #cf222e; --yellow: #9a6700; --green: #1a7f37;
      --cyan: #0969da; --magenta: #8250df;
      --card-bg: #f6f8fa; --border: #d0d7de;
    }
  }
  * { margin: 0; padding: 0; box-sizing: border-box; }
  body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Helvetica, Arial, sans-serif;
         background: var(--bg); color: var(--fg); padding: 2rem; line-height: 1.5; }
  h1 { margin-bottom: .25rem; }
  .timestamp { color: var(--muted); margin-bottom: 2rem; font-size: .9rem; }
  section { margin-bottom: 2.5rem; }
  section h2 { font-size: 1.3rem; margin-bottom: .75rem; padding-bottom: .4rem;
               border-bottom: 2px solid var(--border); }
  .dirty h2    { color: var(--red); }
  .outdated h2 { color: var(--yellow); }
  .pending h2  { color: var(--magenta); }
  .empty { color: var(--green); font-style: italic; }
  ul { list-style: none; padding-left: 0; }
  li { background: var(--card-bg); border: 1px solid var(--border); border-radius: 6px;
       padding: .75rem 1rem; margin-bottom: .5rem; }
  .repo-path { font-weight: 600; }
  .dirty .repo-path { color: var(--yellow); }
  .outdated .repo-path { color: var(--cyan); }
  .pending .repo-path { color: var(--magenta); }
  .issues h2 { color: var(--cyan); }
  .issues .repo-path { color: var(--cyan); }
  .issue-number { color: var(--muted); font-weight: 600; }
  .issue-title { font-weight: 600; }
  .issue-meta { color: var(--muted); font-size: .85rem; margin-top: .2rem; }
  .tag { color: var(--muted); font-size: .9rem; margin-top: .25rem; }
  .tag strong { color: var(--fg); }
  .new-version { color: var(--green); font-weight: 600; }
  .arrow { color: var(--muted); }
  details { margin-top: .5rem; }
  summary { cursor: pointer; color: var(--muted); font-size: .85rem; }
  pre.excerpt { background: var(--bg); border: 1px solid var(--border); border-radius: 4px;
                padding: .75rem; margin-top: .4rem; font-size: .85rem; overflow-x: auto;
                white-space: pre-wrap; word-break: break-word; }
</style>
</head>
<body>
HTMLHEAD

    echo "<h1>Pending Work Report</h1>"
    echo "<p class=\"timestamp\">Generated: ${report_date} (Europe/Athens)</p>"

    has_issues_data=0
    [[ ${#issue_numbers[@]} -gt 0 && -n "${issue_numbers[0]:-}" ]] && has_issues_data=1

    html_sections=0

    # --- Dirty (HTML) ---
    if [[ ${#dirty_dirs[@]} -gt 0 ]]; then
        html_sections=$((html_sections + 1))
        echo '<section class="dirty">'
        echo '<h2>&#x1F534; Dirty Working Copies</h2>'
        echo '<ul>'
        for dir in "${dirty_dirs[@]}"; do
            echo "<li><span class=\"repo-path\">&#x1F4DD; $(html_escape "$(rel_path "$dir")")</span></li>"
        done
        echo '</ul>'
        echo '</section>'
    fi

    # --- Outdated (HTML) ---
    if [[ ${#outdated_dirs[@]} -gt 0 ]]; then
        html_sections=$((html_sections + 1))
        echo '<section class="outdated">'
        echo "<h2>&#x1F7E1; Outdated (no changes, tag &gt; ${OUTDATED_DAYS} days old)</h2>"
        echo '<ul>'
        for dir in "${outdated_dirs[@]}"; do
            tag="${latest_tags[$dir]}"
            epoch="${tag_dates[$dir]}"
            formatted_date=$(format_tag_date "$epoch")
            echo "<li>"
            echo "  <span class=\"repo-path\">&#x1F4E6; $(html_escape "$(rel_path "$dir")")</span>"
            echo "  <div class=\"tag\">Tag: <strong>$(html_escape "$tag")</strong> (${formatted_date})</div>"
            echo "</li>"
        done
        echo '</ul>'
        echo '</section>'
    fi

    # --- Pending Releases (HTML) ---
    if [[ ${#pending_releases[@]} -gt 0 ]]; then
        html_sections=$((html_sections + 1))
        echo '<section class="pending">'
        echo '<h2>&#x1F7E3; Pending Releases</h2>'
        echo '<ul>'
        for dir in "${pending_releases[@]}"; do
            tag="${latest_tags[$dir]}"
            epoch="${tag_dates[$dir]}"
            formatted_date=$(format_tag_date "$epoch")
            cl_version="${changelog_versions[$dir]}"
            excerpt="${changelog_excerpts[$dir]:-}"

            prio=$(pending_emoji_html "$excerpt")
            echo "<li>"
            echo "  <span class=\"repo-path\">${prio}&#x1F680; $(html_escape "$(rel_path "$dir")")</span>"
            echo "  <div class=\"tag\">Tag: <strong>$(html_escape "$tag")</strong> (${formatted_date}) <span class=\"arrow\">&rarr;</span> <span class=\"new-version\">$(html_escape "$cl_version")</span></div>"
            if [[ -n "$excerpt" ]]; then
                echo "  <details><summary>Changelog excerpt</summary>"
                echo "  <pre class=\"excerpt\">$(html_escape "$excerpt")</pre>"
                echo "  </details>"
            fi
            echo "</li>"
        done
        echo '</ul>'
        echo '</section>'
    fi

    # --- Open GitHub Issues (HTML) ---
    if [[ $has_issues_data -eq 1 ]]; then
        html_sections=$((html_sections + 1))
        echo '<section class="issues">'
        echo '<h2>&#x1F535; Open GitHub Issues</h2>'
        echo '<ul>'
        for i in "${!issue_numbers[@]}"; do
            num="${issue_numbers[$i]}"
            title="${issue_titles[$i]}"
            repo="${issue_repos[$i]}"
            opened=$(format_iso_date "${issue_dates[$i]}")
            url="https://github.com/${repo}/issues/${num}"
            echo "<li>"
            echo "  <span class=\"issue-number\">#${num}</span> <a href=\"$(html_escape "$url")\" class=\"issue-title\">$(html_escape "$title")</a>"
            echo "  <div class=\"issue-meta\">$(html_escape "$repo") &middot; opened ${opened}</div>"
            echo "</li>"
        done
        echo '</ul>'
        echo '</section>'
    fi

    if [[ $html_sections -eq 0 ]]; then
        echo '<p style="font-size:1.5rem; text-align:center; margin-top:3rem;">&#x26F1;&#xFE0F; I guess you&#x2019;re taking the day off!</p>'
    fi

    echo '</body></html>'
else
    # =========================================================================
    # Terminal (ANSI) output
    # =========================================================================

    has_issues_data=0
    [[ ${#issue_numbers[@]} -gt 0 && -n "${issue_numbers[0]:-}" ]] && has_issues_data=1

    term_sections=0

    # --- Dirty ---
    if [[ ${#dirty_dirs[@]} -gt 0 ]]; then
        term_sections=$((term_sections + 1))
        echo ""
        echo -e "${RED}🔴 Dirty Working Copies${RESET}"
        echo -e "${DIM}$(printf '%.0s─' {1..60})${RESET}"
        for dir in "${dirty_dirs[@]}"; do
            echo -e "  ${YELLOW}📝 $(rel_path "$dir")${RESET}"
        done
    fi

    # --- Outdated ---
    if [[ ${#outdated_dirs[@]} -gt 0 ]]; then
        term_sections=$((term_sections + 1))
        echo ""
        echo -e "${YELLOW}🟡 Outdated (no changes, tag > ${OUTDATED_DAYS} days old)${RESET}"
        echo -e "${DIM}$(printf '%.0s─' {1..60})${RESET}"
        for dir in "${outdated_dirs[@]}"; do
            tag="${latest_tags[$dir]}"
            epoch="${tag_dates[$dir]}"
            formatted_date=$(format_tag_date "$epoch")
            echo -e "  ${CYAN}📦 $(rel_path "$dir")${RESET}"
            echo -e "     Tag: ${BOLD}${tag}${RESET}  ${DIM}(${formatted_date})${RESET}"
        done
    fi

    # --- Pending Releases ---
    if [[ ${#pending_releases[@]} -gt 0 ]]; then
        term_sections=$((term_sections + 1))
        echo ""
        echo -e "${MAGENTA}🟣 Pending Releases (updated + outdated)${RESET}"
        echo -e "${DIM}$(printf '%.0s─' {1..60})${RESET}"
        for dir in "${pending_releases[@]}"; do
            tag="${latest_tags[$dir]}"
            epoch="${tag_dates[$dir]}"
            formatted_date=$(format_tag_date "$epoch")
            cl_version="${changelog_versions[$dir]}"
            excerpt="${changelog_excerpts[$dir]:-}"

            prio=$(pending_emoji "$excerpt")
            prio_prefix=""
            [[ -n "$prio" ]] && prio_prefix="${prio} "
            echo -e "  ${MAGENTA}${prio_prefix}🚀 $(rel_path "$dir")${RESET}"
            echo -e "     Tag: ${BOLD}${tag}${RESET}  ${DIM}(${formatted_date})${RESET}  →  ${GREEN}${cl_version}${RESET}"
            if [[ -n "$excerpt" ]]; then
                echo -e "     ${DIM}┌─ Changelog excerpt:${RESET}"
                while IFS= read -r line; do
                    echo -e "     ${DIM}│${RESET} $line"
                done <<< "$excerpt"
                echo -e "     ${DIM}└─${RESET}"
            fi
            echo ""
        done
    fi

    # --- Open GitHub Issues ---
    if [[ $has_issues_data -eq 1 ]]; then
        term_sections=$((term_sections + 1))
        BLUE='\033[1;34m'
        echo ""
        echo -e "${BLUE}🔵 Open GitHub Issues${RESET}"
        echo -e "${DIM}$(printf '%.0s─' {1..60})${RESET}"
        for i in "${!issue_numbers[@]}"; do
            num="${issue_numbers[$i]}"
            title="${issue_titles[$i]}"
            repo="${issue_repos[$i]}"
            opened=$(format_iso_date "${issue_dates[$i]}")
            echo -e "  ${CYAN}#${num}${RESET} ${BOLD}${title}${RESET}"
            echo -e "     ${DIM}${repo} · opened ${opened}${RESET}"
        done
    fi

    if [[ $term_sections -eq 0 ]]; then
        echo ""
        echo -e "⛱️  I guess you're taking the day off!"
    fi
    echo ""
fi
