#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
FILE="$ROOT/includes/roster-move-admin-target.php"
META_FILE="$ROOT/includes/migration-meta.php"
AUDIT_FILE="$ROOT/includes/roster-move-audit.php"
PHPUNIT="${PHPUNIT:-$ROOT/vendor/bin/phpunit}"

patch_and_run() {
  local name="$1"
  local test_filter="$2"
  local restore=1
  echo "=== $name ==="
  local test_file="$ROOT/tests/Legacy/MovePlayersAdminTargetTest.php"
  if [[ "$name" == "meta_preserve" ]]; then
    cp "$META_FILE" "$META_FILE.bak"
  elif [[ "$name" == "move_audit" ]]; then
    cp "$AUDIT_FILE" "$AUDIT_FILE.bak"
  else
    cp "$FILE" "$FILE.bak"
  fi
  case "$name" in
    private)
      perl -pi -e "s/\\\$result\\['warnings'\\]\\[\\] = 'private';/\\/\\/ private warning reverted;/" "$FILE"
      ;;
    trash)
      perl -pi -e 's/if \(intersoccer_roster_move_post_is_trashed/if (false \&\& intersoccer_roster_move_post_is_trashed/' "$FILE"
      ;;
    activity)
      perl -pi -e 's/if \(\$source_activity_type !== \$target_activity\)/if (false)/' "$FILE"
      ;;
    cancelled)
      perl -pi -e 's/if \(intersoccer_roster_move_post_is_cancelled\(\$variation_id\) \|\| intersoccer_roster_move_post_is_cancelled\(\$parent_id\)\)/if (false)/' "$FILE"
      ;;
    past_end)
      perl -pi -e "s/\\\$result\\['warnings'\\]\\[\\] = 'past_end_date';/\\/\\/ past_end reverted;/" "$FILE"
      ;;
    cross_gender)
      perl -pi -e "s/\\\$assessment\\['warnings'\\]\\[\\] = 'cross_gender';/\\/\\/ cross_gender warning reverted;/" "$FILE"
      ;;
    meta_preserve)
      test_file="$ROOT/tests/Legacy/MovePlayersMigrationMetaTest.php"
      perl -pi -e 's/if \(!intersoccer_migration_is_variation_rewrite_meta_key/if (false \&\& !intersoccer_migration_is_variation_rewrite_meta_key/' "$META_FILE"
      perl -pi -e "s/return 'Course, Girls Only';/return 'Course';/" "$META_FILE"
      perl -pi -e "s/return 'Camp, Girls Only';/return 'Camp';/" "$META_FILE"
      ;;
    move_audit)
      test_file="$ROOT/tests/Legacy/MovePlayersMoveAuditTest.php"
      perl -0777 -pi -e 's/(function intersoccer_roster_move_record_successful_move\([^)]*\)[^{]*\{)\s*/$1 return; /s' "$AUDIT_FILE"
      ;;
    *)
      echo "unknown patch $name" >&2
      exit 1
      ;;
  esac
  set +e
  out=$("$PHPUNIT" --configuration "$ROOT/phpunit.xml" --filter "$test_filter" "$test_file" 2>&1)
  code=$?
  set -e
  if echo "$out" | grep -qE 'FAILURES!|ERRORS!'; then
    echo "fails: yes"
  elif [[ $code -ne 0 ]]; then
    echo "fails: yes"
  else
    echo "fails: no"
  fi
  if [[ "$name" == "meta_preserve" ]]; then
    mv "$META_FILE.bak" "$META_FILE"
  elif [[ "$name" == "move_audit" ]]; then
    mv "$AUDIT_FILE.bak" "$AUDIT_FILE"
  else
    mv "$FILE.bak" "$FILE"
  fi
}

patch_and_run "private" "test_private_target_needs_confirmation"
patch_and_run "trash" "test_trashed_variation"
patch_and_run "activity" "test_wrong_activity_type"
patch_and_run "cancelled" "test_cancelled_meta"
patch_and_run "past_end" "test_past_end_date"
patch_and_run "cross_gender" "test_cross_gender_adds_warning_via_apply_not_block"
patch_and_run "meta_preserve" "MovePlayersMigrationMetaTest"
patch_and_run "move_audit" "MovePlayersMoveAuditTest"
