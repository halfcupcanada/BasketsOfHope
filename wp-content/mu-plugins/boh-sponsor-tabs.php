<?php
/**
 * Plugin Name: BoH Sponsor Year Tabs
 * Description: Tabbed "2026 / 2025" switcher for the sponsor-logo galleries on
 *              the Sponsorship page. CSS + a tiny vanilla-JS toggle, loaded
 *              only on that page. No-JS and reduced-motion safe.
 */
if (!defined('ABSPATH')) exit;

/** Only load assets on the Sponsorship page. */
function boh_sponsor_tabs_active(): bool {
    return is_page(17) || is_page('sponsor');
}

add_action('wp_head', function () {
    if (!boh_sponsor_tabs_active()) return;
    ?>
<style id="boh-sponsor-tabs-css">
.boh-sponsor-tabwrap{--boh-pink:#d01482;--boh-gold:#f9d135}
.boh-sponsor-tabs{display:flex;gap:.6rem;justify-content:center;flex-wrap:wrap;margin:0 0 2rem}
.boh-sponsor-tabs .boh-tab-btn{font:inherit;font-weight:700;letter-spacing:.01em;cursor:pointer;
  border:2px solid var(--boh-pink);background:#fff;color:var(--boh-pink);
  padding:.55rem 1.45rem;border-radius:999px;line-height:1.1;
  transition:background .15s ease,color .15s ease,box-shadow .15s ease}
.boh-sponsor-tabs .boh-tab-btn:hover{box-shadow:0 2px 12px rgba(208,20,130,.22)}
.boh-sponsor-tabs .boh-tab-btn.is-active{background:var(--boh-pink);color:#fff}
.boh-sponsor-tabs .boh-tab-btn:focus-visible{outline:3px solid var(--boh-gold);outline-offset:2px}
/* Panels hidden unless active; the active one is set in markup + by JS. */
.boh-sponsor-tabwrap .boh-year-panel{display:none}
.boh-sponsor-tabwrap .boh-year-panel.is-active{display:block}
@media (prefers-reduced-motion:reduce){.boh-sponsor-tabs .boh-tab-btn{transition:none}}
</style>
<noscript><style>.boh-sponsor-tabwrap .boh-year-panel{display:block!important}</style></noscript>
    <?php
});

add_action('wp_footer', function () {
    if (!boh_sponsor_tabs_active()) return;
    ?>
<script id="boh-sponsor-tabs-js">
(function(){
  function wire(w){
    var btns=[].slice.call(w.querySelectorAll('.boh-tab-btn'));
    var panels=[].slice.call(w.querySelectorAll('.boh-year-panel'));
    if(!btns.length||!panels.length)return;
    function show(year){
      btns.forEach(function(b){
        var on=b.getAttribute('data-year')===year;
        b.classList.toggle('is-active',on);
        b.setAttribute('aria-selected',on?'true':'false');
      });
      panels.forEach(function(p){
        var m=p.className.match(/boh-year-(\d{4})/);
        p.classList.toggle('is-active',!!m&&m[1]===year);
      });
    }
    btns.forEach(function(b){
      b.addEventListener('click',function(){show(b.getAttribute('data-year'));});
    });
    var def=w.querySelector('.boh-tab-btn.is-active')||btns[0];
    if(def)show(def.getAttribute('data-year'));
  }
  document.querySelectorAll('.boh-sponsor-tabwrap').forEach(wire);
})();
</script>
    <?php
});
