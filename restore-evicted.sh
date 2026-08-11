#!/bin/zsh
# Restore iCloud-evicted (dataless) WordPress files from same-version zips.
# Run:  zsh ~/Documents/wordpress/restore-evicted.sh
#
# Why: ~15k code files under this tree are iCloud "dataless" stubs; reading
# them goes through fileproviderd at ~30 files/min, so WordPress can't render.
# rm + cp from a fresh download of the SAME version needs no iCloud round-trip.
# Zips are already downloaded and unpacked in the Claude session scratchpad;
# if that directory is gone, re-download: wordpress-7.0.2, contact-form-7
# 6.1.6, elementor 4.1.1, give 4.15.3, the-events-calendar 6.16.3,
# wordpress-seo 27.7, astra 4.13.4 — then point SRC at the unzipped dir.
set -u
WP=~/Documents/wordpress
SRC=/private/tmp/claude-501/-Users-t2broot-Documents-wordpress/f7c6b4a1-3c7a-4a42-a4c0-a95813991a3c/scratchpad/zips/src

if [ ! -d "$SRC/wordpress" ]; then
  echo "Source zips not found at $SRC — see comment above." >&2
  exit 1
fi

restore_pair() {
  local live_dir=$1 src_dir=$2
  find "$live_dir" -type f 2>/dev/null | while IFS= read -r f; do
    if ls -lO "$f" 2>/dev/null | grep -q dataless; then
      local rel=${f#$live_dir/} src="$src_dir/${f#$live_dir/}"
      if [ -f "$src" ]; then
        rm -f "$f" && cp -p "$src" "$f" && echo "restored $f"
      else
        echo "no source for $f (left for iCloud)"
      fi
    fi
  done
}

restore_pair "$WP/wp-includes" "$SRC/wordpress/wp-includes"
restore_pair "$WP/wp-admin"    "$SRC/wordpress/wp-admin"
for p in contact-form-7 elementor give the-events-calendar wordpress-seo akismet; do
  [ -d "$SRC/$p" ] && restore_pair "$WP/wp-content/plugins/$p" "$SRC/$p"
done
restore_pair "$WP/wp-content/themes/astra" "$SRC/astra"

# Root-level WP core files (wp-config.php is NOT in the zip and never touched)
for f in "$WP"/*.php "$WP"/license.txt "$WP"/readme.html; do
  [ -f "$f" ] || continue
  if ls -lO "$f" 2>/dev/null | grep -q dataless; then
    base=$(basename "$f")
    [ -f "$SRC/wordpress/$base" ] && rm -f "$f" && cp -p "$SRC/wordpress/$base" "$f" && echo "restored $f"
  fi
done

echo "Done. Remaining dataless (uploads etc. — leave to iCloud):"
find "$WP" -type f -print0 2>/dev/null | xargs -0 ls -lO 2>/dev/null | grep -c dataless
