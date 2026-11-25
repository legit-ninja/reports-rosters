#!/bin/bash

# Compile Translation Files (.po → .mo)
# Run before deployment to ensure .mo files are up to date
#
# The French and German Advanced tools drafts are still marked fuzzy
# (wording is not approved yet). msgfmt skips fuzzy entries unless you
# pass --use-fuzzy. Until those drafts are approved, compile with:
#   ./scripts/compile-translations.sh --use-fuzzy
#
# --use-fuzzy applies only to the French and German catalogs (fr_FR,
# fr_CH, de_DE, de_CH). en_CH is always compiled without --use-fuzzy so
# stale fuzzy English drafts cannot appear in the .mo for Swiss English
# admins; they see the source msgid instead. en_CH is kept because the
# locale is in use; dropping it would fall back less predictably.

RED='\033[0;31m'
GREEN='\033[0;32m'
BLUE='\033[0;34m'
YELLOW='\033[1;33m'
NC='\033[0m'

cd "$(dirname "$0")/.."

echo -e "${BLUE}═══════════════════════════════════════${NC}"
echo -e "${BLUE}  Translation Compiler${NC}"
echo -e "${BLUE}═══════════════════════════════════════${NC}"
echo ""

# Check if msgfmt is installed
if ! command -v msgfmt &> /dev/null; then
    echo -e "${RED}❌ ERROR: msgfmt not found!${NC}"
    echo ""
    echo "Install gettext tools:"
    echo "  Ubuntu/Debian: sudo apt-get install gettext"
    echo "  macOS: brew install gettext"
    echo ""
    exit 1
fi

USE_FUZZY=0
for arg in "$@"; do
    case "$arg" in
        --use-fuzzy)
            USE_FUZZY=1
            ;;
        -h|--help)
            echo "Usage: $0 [--use-fuzzy]"
            echo "  --use-fuzzy  Include fuzzy draft translations in the French and"
            echo "               German .mo files. Needed for now while the FR/DE"
            echo "               Advanced tools drafts remain fuzzy and unapproved."
            echo "               en_CH is always compiled without --use-fuzzy."
            exit 0
            ;;
        *)
            echo "Unknown option: $arg" >&2
            echo "Usage: $0 [--use-fuzzy]" >&2
            exit 1
            ;;
    esac
done

# Check if languages directory exists
if [ ! -d "languages" ]; then
    echo -e "${YELLOW}⚠️  No languages directory found${NC}"
    echo "Nothing to compile."
    exit 0
fi

cd languages

# Count .po files
PO_COUNT=$(ls -1 *.po 2>/dev/null | wc -l)
if [ $PO_COUNT -eq 0 ]; then
    echo -e "${YELLOW}⚠️  No .po files found${NC}"
    echo "Nothing to compile."
    exit 0
fi

echo "Found $PO_COUNT translation file(s)"
echo ""

COMPILED=0
ERRORS=0

# Compile each .po file
for po_file in *.po; do
    mo_file="${po_file%.po}.mo"
    
    echo -n "Compiling $po_file → $mo_file ... "
    
    # Never compile English catalogs with --use-fuzzy, even when the
    # flag is passed for the FR/DE drafts.
    use_fuzzy_this=0
    case "$po_file" in
        *fr_FR.po|*fr_CH.po|*de_DE.po|*de_CH.po)
            use_fuzzy_this=$USE_FUZZY
            ;;
    esac
    MSGFMT_OPTS=(-o "$mo_file" "$po_file")
    if [ "$use_fuzzy_this" -eq 1 ]; then
        MSGFMT_OPTS=(--use-fuzzy -o "$mo_file" "$po_file")
    fi

    if msgfmt "${MSGFMT_OPTS[@]}" 2>/dev/null; then
        echo -e "${GREEN}✓${NC}"
        COMPILED=$((COMPILED + 1))
    else
        echo -e "${RED}✗ FAILED${NC}"
        echo "  Error compiling $po_file"
        msgfmt "${MSGFMT_OPTS[@]}"  # Show error
        ERRORS=$((ERRORS + 1))
    fi
done

echo ""
echo "═══════════════════════════════════════"
echo ""

if [ $ERRORS -eq 0 ]; then
    echo -e "${GREEN}✅ Successfully compiled $COMPILED translation file(s)${NC}"
    echo ""
    echo "Generated files:"
    ls -lh *.mo 2>/dev/null | awk '{print "  " $9 " (" $5 ")"}'
    echo ""
    echo "These files will be included in the next deployment."
    echo ""
    exit 0
else
    echo -e "${RED}❌ $ERRORS error(s) occurred${NC}"
    echo ""
    echo "Fix the .po file syntax errors and try again."
    exit 1
fi

