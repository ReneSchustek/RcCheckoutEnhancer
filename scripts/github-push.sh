#!/bin/bash
# ============================================================
# GitHub-Export: Pusht nur produktionsrelevante Dateien
# ============================================================
# Erstellt einen temporaeren Branch ohne Entwicklungsartefakte
# und pusht diesen als main nach GitHub.
# Lokaler main-Branch und DevBox bleiben unberuehrt.
# ============================================================
set -euo pipefail

GITHUB_REMOTE="github"
SOURCE_BRANCH="main"
TEMP_BRANCH="_github-export"

# Dateien/Verzeichnisse die NICHT nach GitHub gepusht werden
EXCLUDE_PATHS=(
    "reviews"
    "scripts"
    ".gitignore-template"
)

# --- Pruefungen ---

if ! git remote get-url "$GITHUB_REMOTE" &>/dev/null; then
    echo "Fehler: Remote '$GITHUB_REMOTE' nicht konfiguriert."
    echo "Einrichten mit: git remote add $GITHUB_REMOTE git@github.com:OWNER/REPO.git"
    exit 1
fi

CURRENT_BRANCH=$(git branch --show-current)
if [ "$CURRENT_BRANCH" != "$SOURCE_BRANCH" ]; then
    echo "Fehler: Nicht auf '$SOURCE_BRANCH'. Aktuell: '$CURRENT_BRANCH'"
    exit 1
fi

if ! git diff --quiet || ! git diff --cached --quiet; then
    echo "Fehler: Es gibt uncommittete Aenderungen. Zuerst committen."
    exit 1
fi

# --- Export ---

echo "Erstelle Export-Branch..."
git branch -D "$TEMP_BRANCH" 2>/dev/null || true
git checkout -b "$TEMP_BRANCH"

REMOVED=false
for path in "${EXCLUDE_PATHS[@]}"; do
    if git ls-files --error-unmatch "$path" &>/dev/null; then
        git rm -r --cached --quiet "$path"
        REMOVED=true
        echo "  Entfernt: $path"
    fi
done

if [ "$REMOVED" = true ]; then
    git commit --quiet -m "export: Entwicklungsdateien entfernt"
fi

echo "Pushe nach GitHub..."
git push "$GITHUB_REMOTE" "${TEMP_BRANCH}:${SOURCE_BRANCH}" --force

# --- Aufraeumen ---

git checkout --quiet "$SOURCE_BRANCH"
git branch -D "$TEMP_BRANCH" --quiet

echo ""
echo "GitHub-Push abgeschlossen."
echo "Remote: $(git remote get-url $GITHUB_REMOTE)"
