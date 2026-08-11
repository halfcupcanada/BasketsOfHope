#!/bin/zsh
# Apply the Aug-2026 feedback edits to the LIVE server (boh.halfcup.ca =
# root@137.184.169.98, Caddy + WP at /var/www/html).
# Run from this Mac:  zsh ~/Documents/wordpress/deploy-feedback-to-live.sh
#
# What it does (everything is backed up on the server first):
#   1. Uploads the edited theme functions.php + style.css
#      (2-slide hero, RSVP retitle + no spots counter, full-bleed sub-page
#       hero, pale-yellow donate cards, boards around sponsorship forms)
#   2. About page: replaced with deploy-4.32 content minus the [boh_stats] band
#   3. Home page:  replaced with deploy-4.32 content plus the [boh_stats] band
#   4. Sponsor page: bright #f9d135 -> pale #fef4c7 (in-place, form untouched)
#   5. Flushes WP cache and verifies over the public URL
set -e
HOST=root@137.184.169.98
WEB=/var/www/html
THEME=$WEB/wp-content/themes/astra-boh
LOCAL_THEME=~/Documents/wordpress/wp-content/themes/astra-boh
CONTENT=/private/tmp/claude-501/-Users-t2broot-Documents-wordpress/f7c6b4a1-3c7a-4a42-a4c0-a95813991a3c/scratchpad/content
STAMP=$(date +%Y%m%d-%H%M%S)

[ -f "$CONTENT/about.html" ] || { echo "Prepared content not found at $CONTENT — rerun in the Claude session that built it." >&2; exit 1; }

echo "── sanity check: server pages look like deploy-4.32"
ssh $HOST "cd $WEB
  H=\$(wp post list --post_type=page --name=home --field=ID --allow-root)
  A=\$(wp post list --post_type=page --name=about --field=ID --allow-root)
  SP=\$(wp post list --post_type=page --name=sponsor --field=ID --allow-root)
  echo ids: home=\$H about=\$A sponsor=\$SP
  wp post get \$H --field=post_content --allow-root | grep -q boh-hero-headrow || { echo 'home content unexpected — aborting' >&2; exit 2; }
  wp post get \$A --field=post_content --allow-root | grep -q boh_page_hero   || { echo 'about content unexpected — aborting' >&2; exit 2; }
"

echo "── 1. backup + upload theme files"
ssh $HOST "mkdir -p /root/boh-backups/$STAMP && cp -p $THEME/functions.php $THEME/style.css /root/boh-backups/$STAMP/"
cat $LOCAL_THEME/functions.php | ssh $HOST "cat > $THEME/functions.php"
cat $LOCAL_THEME/style.css     | ssh $HOST "cat > $THEME/style.css"
ssh $HOST "chown www-data:www-data $THEME/functions.php $THEME/style.css; php -l $THEME/functions.php"

echo "── 2-4. page content"
ssh $HOST "cd $WEB
  H=\$(wp post list --post_type=page --name=home --field=ID --allow-root)
  A=\$(wp post list --post_type=page --name=about --field=ID --allow-root)
  SP=\$(wp post list --post_type=page --name=sponsor --field=ID --allow-root)
  for id in \$H \$A \$SP; do
    wp post get \$id --field=post_content --allow-root > /root/boh-backups/$STAMP/page-\$id.html
  done
  wp post get \$SP --field=post_content --allow-root | sed 's/f9d135/fef4c7/g' | wp post update \$SP - --allow-root
"
AID=$(ssh $HOST "cd $WEB && wp post list --post_type=page --name=about --field=ID --allow-root")
HID=$(ssh $HOST "cd $WEB && wp post list --post_type=page --name=home --field=ID --allow-root")
cat $CONTENT/about.html | ssh $HOST "cd $WEB && wp post update $AID - --allow-root"
cat $CONTENT/home.html  | ssh $HOST "cd $WEB && wp post update $HID - --allow-root"
ssh $HOST "cd $WEB && wp cache flush --allow-root || true"

echo "── 5. verify over the public URL"
sleep 2
echo "hero slides (want 1..2 only):"
curl -s https://boh.halfcup.ca/ | grep -o 'boh-hero-slide--[0-9]' | sort -u
echo "home has stats band (want >=1):    $(curl -s https://boh.halfcup.ca/ | grep -c '2,847')"
echo "about stats band (want 0):         $(curl -s https://boh.halfcup.ca/about/ | grep -c '2,847')"
echo "sponsor bright yellow (want 0):    $(curl -s https://boh.halfcup.ca/sponsor/ | grep -c 'f9d135')"
echo "sponsor pale yellow (want >=1):    $(curl -s https://boh.halfcup.ca/sponsor/ | grep -c 'fef4c7')"
echo "rsvp new title (want 1):           $(curl -s https://boh.halfcup.ca/rsvp/ | grep -c 'Let us know if you can join us')"
echo "rsvp spots counter (want 0):       $(curl -s https://boh.halfcup.ca/rsvp/ | grep -c 'spots left')"
echo "Done. Backups on server: /root/boh-backups/$STAMP/"
