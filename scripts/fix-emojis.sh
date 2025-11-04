#!/bin/bash

# Fix all emojis in translatable strings
# Replace with UTF8-safe symbols or plain text

echo "Fixing emojis in translatable strings..."

# Mapping of replacements:
# 📊 Dashboard → Report Dashboard
# 🔍 Filter → Filter
# 📋 Columns → Columns
# 💡 Note → Note:
# 📥 Export → ↓ Export
# 🌍 Language → Language
# 🔄 Loading/Reconcile → ↻
# 👥 players → players
# 📚 camps/variations → camps/variations
# 👀 View → View

cd "$(dirname "$0")/.."

# Fix reports.php
sed -i "s/📊 Booking Report Dashboard/Booking Report Dashboard/g" includes/reports.php
sed -i "s/🔍 Filter Options/Filter Options/g" includes/reports.php
sed -i "s/📋 Columns to Display/Columns to Display/g" includes/reports.php
sed -i "s/💡 /Note: /g" includes/reports.php
sed -i "s/📥 /↓ /g" includes/reports.php

# Fix reports-ui.php
sed -i "s/📊 Final Numbers Report/Final Numbers Report/g" includes/reports-ui.php

# Fix rosters.php
sed -i "s/📥 Export All Camps/↓ Export All Camps/g" includes/rosters.php
sed -i "s/📥 Export All Courses/↓ Export All Courses/g" includes/rosters.php
sed -i "s/📥 Export Other Events/↓ Export Other Events/g" includes/rosters.php
sed -i "s/📥 Export All Rosters/↓ Export All Rosters/g" includes/rosters.php
sed -i "s/🔄 Reconcile Rosters/↻ Reconcile Rosters/g" includes/rosters.php
sed -i "s/🔄 Clear Filters/↻ Clear Filters/g" includes/rosters.php
sed -i "s/👥 /Players: /g" includes/rosters.php
sed -i "s/📚 /Camps: /g" includes/rosters.php
sed -i "s/👀 View Roster/View Roster/g" includes/rosters.php
sed -i "s/<span class=\"stat-item\">👥 /<span class=\"stat-item\">Players: /g" includes/rosters.php
sed -i "s/<span class=\"stat-item\">📚 /<span class=\"stat-item\">Variations: /g" includes/rosters.php

# Fix advanced.php
sed -i "s/🔍 Event Signature Verifier/Event Signature Verifier/g" includes/advanced.php
sed -i "s/📚 About Event Signatures:/About Event Signatures:/g" includes/advanced.php
sed -i "s/🌍 Current WPML Language:/Current WPML Language:/g" includes/advanced.php
sed -i "s/📥 Load Selected Event/↓ Load Selected Event/g" includes/advanced.php
sed -i "s/🔍 Test Signature Generation/Test Signature Generation/g" includes/advanced.php
sed -i "s/📊 Test Results/Test Results/g" includes/advanced.php
sed -i "s/💡 Testing Instructions/Testing Instructions/g" includes/advanced.php

echo "✓ All emojis fixed!"
echo ""
echo "Run validation:"
echo "  ./scripts/validate-compatibility.sh"

