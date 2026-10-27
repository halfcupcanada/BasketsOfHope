<?php
/**
 * Plugin Name: BoH - Bulk email scheduler
 * Description: Schedule the three bulk emails (invitation, reminder, event
 *   details) from the Invitations Settings screen - send now or at a chosen
 *   time, each in a modal that shows the recipient list (searchable, with
 *   per-person checkboxes), a preview of the email itself, and a live progress
 *   bar. All three can run in parallel. Guardrails: every start needs an
 *   explicit confirmation, a single "Pause all" emergency stop halts every
 *   running campaign at once, no one is sent the same email twice, anyone you
 *   uncheck is skipped, and all campaigns share one daily send budget so
 *   parallel runs cannot exceed it.
 */
defined( 'ABSPATH' ) || exit;

const BOH_SCHED_OPT   = 'boh_inv_campaigns';
const BOH_SCHED_STOP  = 'boh_inv_emergency_stop';
const BOH_SCHED_HOOK  = 'boh_inv_scheduler_tick';
const BOH_SCHED_NONCE = 'boh_inv_sched';

function boh_sched_campaigns(): array {
	return [
		'invitation' => [ 'label' => 'Invitations', 'tpl' => 'invitation', 'stamp' => 'invitation_sent_at',
			'where' => 'invitation_sent_at IS NULL', 'to' => 'people not yet invited' ],
		'reminder'   => [ 'label' => 'Reminders', 'tpl' => 'reminder', 'stamp' => 'reminder_sent_at',
			'where' => "invitation_sent_at IS NOT NULL AND responded_at IS NULL AND reminder_sent_at IS NULL", 'to' => "invited guests who haven't replied" ],
		'details'    => [ 'label' => 'Event details', 'tpl' => 'details', 'stamp' => 'details_sent_at',
			'where' => "responded_at IS NOT NULL AND ( party_size IS NULL OR party_size <> 'Not attending' ) AND details_sent_at IS NULL", 'to' => "guests who RSVP'd yes" ],
	];
}

// Eligible rows, optionally excluding specific invitee ids (the people unchecked).
function boh_sched_exclude_sql( array $exclude ): string {
	$exclude = array_values( array_filter( array_map( 'intval', $exclude ) ) );
	return $exclude ? ' AND id NOT IN (' . implode( ',', $exclude ) . ')' : '';
}
function boh_sched_eligible( string $key, int $limit = 0, array $exclude = [] ) {
	global $wpdb;
	$defs = boh_sched_campaigns();
	if ( ! isset( $defs[ $key ] ) ) return [];
	$t = boh_invitations_table();
	$sql = "SELECT * FROM $t WHERE " . $defs[ $key ]['where'] . boh_sched_exclude_sql( $exclude ) . ' ORDER BY name ASC';
	if ( $limit > 0 ) $sql .= ' LIMIT ' . (int) $limit;
	return $wpdb->get_results( $sql );
}
function boh_sched_eligible_count( string $key, array $exclude = [] ): int {
	global $wpdb;
	$defs = boh_sched_campaigns();
	if ( ! isset( $defs[ $key ] ) ) return 0;
	$t = boh_invitations_table();
	return (int) $wpdb->get_var( "SELECT COUNT(*) FROM $t WHERE " . $defs[ $key ]['where'] . boh_sched_exclude_sql( $exclude ) );
}

function boh_sched_state(): array {
	$s = get_option( BOH_SCHED_OPT, [] );
	$s = is_array( $s ) ? $s : [];
	foreach ( array_keys( boh_sched_campaigns() ) as $k ) {
		$s[ $k ] = wp_parse_args( $s[ $k ] ?? [], [
			'status' => 'idle', 'scheduled_at' => 0, 'started_at' => 0, 'finished_at' => 0,
			'total' => 0, 'sent' => 0, 'failed' => 0, 'by' => '', 'exclude' => [],
		] );
		$s[ $k ]['exclude'] = array_values( array_filter( array_map( 'intval', (array) $s[ $k ]['exclude'] ) ) );
	}
	return $s;
}
function boh_sched_save( array $s ): void { update_option( BOH_SCHED_OPT, $s, false ); }

function boh_sched_sent_today(): int {
	global $wpdb;
	$t = boh_invitations_table();
	$d = current_time( 'Y-m-d' );
	return (int) $wpdb->get_var( $wpdb->prepare(
		"SELECT COUNT(*) FROM $t WHERE DATE(invitation_sent_at)=%s OR DATE(reminder_sent_at)=%s OR DATE(details_sent_at)=%s", $d, $d, $d ) );
}

// ── Processor ──────────────────────────────────────────────────
add_filter( 'cron_schedules', function ( $s ) { $s['boh_minute'] = [ 'interval' => 60, 'display' => 'Every minute' ]; return $s; } );
add_action( 'init', function () { if ( ! wp_next_scheduled( BOH_SCHED_HOOK ) ) wp_schedule_event( time() + 60, 'boh_minute', BOH_SCHED_HOOK ); } );
add_action( BOH_SCHED_HOOK, 'boh_sched_tick' );

function boh_sched_tick() {
	if ( get_option( BOH_SCHED_STOP ) ) return;
	global $wpdb;
	$t = boh_invitations_table();
	$defs = boh_sched_campaigns();
	$s = boh_sched_state();
	$limits = get_option( BOH_INV_OPT_LIMITS );
	$per_day = max( 1, (int) ( $limits['per_day'] ?? 250 ) );
	$per_batch = max( 1, (int) ( $limits['per_batch'] ?? 15 ) );
	$now = time();

	foreach ( $defs as $key => $d ) {
		if ( $s[ $key ]['status'] === 'scheduled' && $s[ $key ]['scheduled_at'] <= $now ) {
			$s[ $key ]['status'] = 'running';
			$s[ $key ]['started_at'] = $now;
			$s[ $key ]['total'] = boh_sched_eligible_count( $key, $s[ $key ]['exclude'] );
			$s[ $key ]['sent'] = 0; $s[ $key ]['failed'] = 0;
		}
	}

	$budget = min( $per_batch, max( 0, $per_day - boh_sched_sent_today() ) );
	if ( $budget <= 0 ) { boh_sched_save( $s ); return; }

	foreach ( $defs as $key => $d ) {
		if ( $budget <= 0 ) break;
		if ( $s[ $key ]['status'] !== 'running' ) continue;
		$rows = boh_sched_eligible( $key, $budget, $s[ $key ]['exclude'] );
		if ( ! $rows ) { $s[ $key ]['status'] = 'done'; $s[ $key ]['finished_at'] = $now; continue; }
		foreach ( $rows as $inv ) {
			if ( $budget <= 0 ) break;
			$ok = boh_invitations_send_email( $inv, $d['tpl'], true );
			if ( $ok ) {
				$wpdb->update( $t, [ $d['stamp'] => current_time( 'mysql', true ), 'updated_at' => current_time( 'mysql', true ) ], [ 'id' => $inv->id ] );
				$s[ $key ]['sent']++;
			} else { $s[ $key ]['failed']++; }
			$budget--;
		}
		if ( boh_sched_eligible_count( $key, $s[ $key ]['exclude'] ) === 0 ) { $s[ $key ]['status'] = 'done'; $s[ $key ]['finished_at'] = $now; }
	}
	boh_sched_save( $s );
}

// ── AJAX ───────────────────────────────────────────────────────
function boh_sched_guard() {
	if ( ! current_user_can( BOH_INV_CAP ) ) wp_send_json_error( 'forbidden', 403 );
	check_ajax_referer( BOH_SCHED_NONCE );
}
function boh_sched_progress_payload(): array {
	$s = boh_sched_state();
	$out = [ 'stopped' => (bool) get_option( BOH_SCHED_STOP ), 'campaigns' => [] ];
	foreach ( boh_sched_campaigns() as $key => $d ) {
		$out['campaigns'][ $key ] = [
			'label' => $d['label'], 'status' => $s[ $key ]['status'],
			'total' => (int) $s[ $key ]['total'], 'sent' => (int) $s[ $key ]['sent'], 'failed' => (int) $s[ $key ]['failed'],
			'remaining' => boh_sched_eligible_count( $key, $s[ $key ]['exclude'] ),
			'scheduled_at' => $s[ $key ]['scheduled_at'] ? get_date_from_gmt( gmdate( 'Y-m-d H:i:s', $s[ $key ]['scheduled_at'] ), 'M j, g:i a' ) : '',
		];
	}
	return $out;
}
add_action( 'wp_ajax_boh_sched_status', function () { boh_sched_guard(); wp_send_json_success( boh_sched_progress_payload() ); } );

add_action( 'wp_ajax_boh_sched_list', function () {
	boh_sched_guard();
	$key = sanitize_key( $_POST['campaign'] ?? '' );
	$defs = boh_sched_campaigns();
	if ( ! isset( $defs[ $key ] ) ) wp_send_json_error( 'bad campaign' );
	$rows = boh_sched_eligible( $key, 3000 );
	$list = array_map( fn( $r ) => [ 'id' => (int) $r->id, 'name' => $r->name ?: '-', 'email' => $r->email, 'party' => $r->party_size ?: '' ], $rows );
	// Preview, personalised with the first eligible recipient (or a demo).
	$sample = $rows ? $rows[0] : (object) [ 'id' => 0, 'name' => 'Sarah Chen', 'email' => 'sarah@example.com', 'company' => '' ];
	$mail = boh_invitations_render_email( $key, $sample );
	$body_html = function_exists( 'boh_email_textify' ) ? boh_email_textify( $mail['body'] ) : nl2br( esc_html( $mail['body'] ) );
	$from_name = defined( 'BOH_MAIL_FROM_NAME' ) ? BOH_MAIL_FROM_NAME : get_bloginfo( 'name' );
	$from_addr = defined( 'BOH_MAIL_FROM' ) ? BOH_MAIL_FROM : '(wp_mail)';
	wp_send_json_success( [
		'total' => boh_sched_eligible_count( $key ), 'shown' => count( $list ), 'list' => $list,
		'preview' => [ 'subject' => $mail['subject'], 'from' => $from_name . ' <' . $from_addr . '>', 'html' => $body_html, 'sample' => $sample->name ?: $sample->email ],
	] );
} );

add_action( 'wp_ajax_boh_sched_start', function () {
	boh_sched_guard();
	$key = sanitize_key( $_POST['campaign'] ?? '' );
	if ( ! isset( boh_sched_campaigns()[ $key ] ) ) wp_send_json_error( 'bad campaign' );
	if ( empty( $_POST['confirm'] ) ) wp_send_json_error( 'Please tick the confirmation box first.' );
	$exclude = array_values( array_filter( array_map( 'intval', explode( ',', (string) ( $_POST['exclude'] ?? '' ) ) ) ) );
	$eligible = boh_sched_eligible_count( $key, $exclude );
	if ( $eligible <= 0 ) wp_send_json_error( 'No recipients are selected.' );
	$s = boh_sched_state();
	if ( in_array( $s[ $key ]['status'], [ 'running', 'scheduled' ], true ) ) wp_send_json_error( 'That campaign is already ' . $s[ $key ]['status'] . '.' );
	$when = sanitize_text_field( $_POST['when'] ?? 'now' );
	$user = wp_get_current_user();
	$base = [ 'finished_at' => 0, 'total' => $eligible, 'sent' => 0, 'failed' => 0, 'by' => $user->user_login, 'exclude' => $exclude ];
	if ( $when === 'now' ) {
		$s[ $key ] = $base + [ 'status' => 'running', 'scheduled_at' => 0, 'started_at' => time() ];
	} else {
		$ts = strtotime( $when );
		if ( ! $ts ) wp_send_json_error( 'Could not read that date/time.' );
		$gmt = $ts - ( (int) ( get_option( 'gmt_offset' ) * 3600 ) );
		if ( $gmt < time() - 60 ) wp_send_json_error( 'That time is in the past.' );
		$s[ $key ] = $base + [ 'status' => 'scheduled', 'scheduled_at' => $gmt, 'started_at' => 0 ];
	}
	boh_sched_save( $s );
	wp_send_json_success( boh_sched_progress_payload() );
} );

add_action( 'wp_ajax_boh_sched_cancel', function () {
	boh_sched_guard();
	$key = sanitize_key( $_POST['campaign'] ?? '' );
	if ( ! isset( boh_sched_campaigns()[ $key ] ) ) wp_send_json_error( 'bad campaign' );
	$s = boh_sched_state();
	$s[ $key ]['status'] = 'canceled'; $s[ $key ]['finished_at'] = time();
	boh_sched_save( $s );
	wp_send_json_success( boh_sched_progress_payload() );
} );
add_action( 'wp_ajax_boh_sched_stop', function () {
	boh_sched_guard();
	update_option( BOH_SCHED_STOP, empty( $_POST['engage'] ) ? 0 : 1, false );
	wp_send_json_success( boh_sched_progress_payload() );
} );

// ── Modal + JS on the Settings screen ──────────────────────────
add_action( 'admin_footer', function () {
	$screen = get_current_screen();
	if ( ! $screen || strpos( $screen->id, BOH_INV_MENU_SLUG . '-settings' ) === false ) return;
	$nonce = wp_create_nonce( BOH_SCHED_NONCE );
	?>
	<div id="boh-sched-modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:100000;align-items:flex-start;justify-content:center">
	  <div style="background:#fff;border-radius:8px;max-width:820px;width:94%;margin:3vh 0;max-height:94vh;overflow:auto;box-shadow:0 10px 40px rgba(0,0,0,.3)">
	    <div style="display:flex;justify-content:space-between;align-items:center;padding:16px 22px;border-bottom:1px solid #e0e0e0;position:sticky;top:0;background:#fff;z-index:1">
	      <h2 id="boh-sched-title" style="margin:0;font-size:18px"></h2>
	      <button type="button" id="boh-sched-close" class="button-link" style="font-size:22px;line-height:1;color:#666;cursor:pointer">&times;</button>
	    </div>
	    <div style="padding:20px 22px">
	      <p id="boh-sched-sub" style="margin-top:0;color:#646970"></p>

	      <div id="boh-sched-progwrap" style="display:none;margin:10px 0 18px">
	        <div style="height:16px;background:#f0f0f1;border-radius:8px;overflow:hidden"><div id="boh-sched-bar" style="height:100%;width:0;background:#d01482;transition:width .4s"></div></div>
	        <p id="boh-sched-progtext" style="margin:8px 0 0;font-size:13px;color:#1d2327"></p>
	        <button type="button" class="button" id="boh-sched-cancel" style="margin-top:8px">Cancel this send</button>
	      </div>

	      <div id="boh-sched-setup">
	        <div style="display:flex;gap:8px;margin-bottom:10px">
	          <button type="button" class="button boh-tab" id="boh-tab-list" data-tab="list" style="font-weight:600">Recipients</button>
	          <button type="button" class="button boh-tab" id="boh-tab-prev" data-tab="prev">Preview email</button>
	        </div>

	        <div id="boh-pane-list">
	          <div style="display:flex;gap:10px;align-items:center;margin-bottom:8px;flex-wrap:wrap">
	            <input type="search" id="boh-sched-search" placeholder="Search name or email" style="flex:1;min-width:180px;padding:5px 8px">
	            <label style="font-size:13px"><input type="checkbox" id="boh-sched-all" checked> Select all</label>
	            <span id="boh-sched-selcount" style="font-size:13px;color:#1d2327;font-weight:600"></span>
	          </div>
	          <div id="boh-sched-list" style="max-height:300px;overflow:auto;border:1px solid #e0e0e0;border-radius:4px;font-size:13px"></div>
	          <p style="margin:8px 0 0;font-size:12px;color:#646970">Everyone is selected by default. Uncheck anyone you want to skip. <a href="<?php echo esc_url( admin_url( 'admin.php?page=' . BOH_INV_MENU_SLUG . '-templates' ) ); ?>" target="_blank">Edit wording</a></p>
	        </div>

	        <div id="boh-pane-prev" style="display:none">
	          <div style="border:1px solid #e0e0e0;border-radius:4px;overflow:hidden">
	            <div style="background:#f6f7f7;padding:10px 14px;border-bottom:1px solid #e0e0e0;font-size:12px;color:#666">
	              <div>From: <strong id="boh-prev-from"></strong></div>
	              <div style="margin-top:4px">Subject: <strong id="boh-prev-subj" style="color:#1d2327"></strong></div>
	              <div style="margin-top:4px;color:#888">Personalised for: <span id="boh-prev-sample"></span></div>
	            </div>
	            <div id="boh-prev-body" style="padding:16px 18px;line-height:1.55;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;font-size:14px;color:#222;background:#fff;max-height:360px;overflow:auto"></div>
	          </div>
	        </div>

	        <fieldset style="border:1px solid #e0e0e0;border-radius:4px;padding:12px 14px;margin:16px 0 12px">
	          <label style="display:block;margin-bottom:6px"><input type="radio" name="boh-sched-when" value="now" checked> Send now</label>
	          <label style="display:block"><input type="radio" name="boh-sched-when" value="at"> At a set time: <input type="datetime-local" id="boh-sched-at"></label>
	        </fieldset>
	        <p><label><input type="checkbox" id="boh-sched-confirm"> I have reviewed the email and this recipient list, and I want to send it to the selected people.</label></p>
	        <p id="boh-sched-err" style="color:#b32d2e;display:none"></p>
	        <button type="button" class="button button-primary button-large" id="boh-sched-go">Start</button>
	      </div>
	    </div>
	  </div>
	</div>
	<script>
	(function(){
	  var A=ajaxurl, N='<?php echo esc_js( $nonce ); ?>', cur=null, poll=null, ROWS=[], TOTAL=0;
	  function post(a,d){ d=d||{}; d.action=a; d._ajax_nonce=N; return fetch(A,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:new URLSearchParams(d)}).then(function(r){return r.json();}); }
	  function esc(s){var e=document.createElement('div');e.textContent=s==null?'':s;return e.innerHTML;}
	  var modal=document.getElementById('boh-sched-modal');
	  function show(){modal.style.display='flex';} function hide(){modal.style.display='none'; if(poll){clearInterval(poll);poll=null;}}
	  document.getElementById('boh-sched-close').onclick=hide;
	  modal.onclick=function(e){ if(e.target===modal) hide(); };

	  function setTab(t){
	    document.getElementById('boh-pane-list').style.display=t==='list'?'block':'none';
	    document.getElementById('boh-pane-prev').style.display=t==='prev'?'block':'none';
	    document.getElementById('boh-tab-list').style.fontWeight=t==='list'?'600':'400';
	    document.getElementById('boh-tab-prev').style.fontWeight=t==='prev'?'600':'400';
	  }
	  document.getElementById('boh-tab-list').onclick=function(){setTab('list');};
	  document.getElementById('boh-tab-prev').onclick=function(){setTab('prev');};

	  function excluded(){ return ROWS.filter(function(r){return !r.checked;}).map(function(r){return r.id;}); }
	  function updateCount(){
	    var sel=ROWS.filter(function(r){return r.checked;}).length;
	    document.getElementById('boh-sched-selcount').textContent='Selected: '+sel+' of '+TOTAL;
	    document.getElementById('boh-sched-all').checked = sel===TOTAL && TOTAL>0;
	  }
	  function renderList(){
	    var q=(document.getElementById('boh-sched-search').value||'').toLowerCase();
	    var box=document.getElementById('boh-sched-list');
	    var html='<table style="width:100%;border-collapse:collapse">';
	    ROWS.forEach(function(r,i){
	      if(q && (r.name+' '+r.email).toLowerCase().indexOf(q)<0) return;
	      html+='<tr><td style="padding:4px 8px;border-bottom:1px solid #f0f0f0;width:26px"><input type="checkbox" data-i="'+i+'" class="boh-rowck" '+(r.checked?'checked':'')+'></td>'+
	        '<td style="padding:4px 8px;border-bottom:1px solid #f0f0f0"><strong>'+esc(r.name)+'</strong></td>'+
	        '<td style="padding:4px 8px;border-bottom:1px solid #f0f0f0;color:#555">'+esc(r.email)+'</td>'+
	        '<td style="padding:4px 8px;border-bottom:1px solid #f0f0f0;color:#888">'+esc(r.party)+'</td></tr>';
	    });
	    html+='</table>';
	    box.innerHTML = ROWS.length? html : '<p style="padding:10px;color:#888;margin:0">No one is eligible right now.</p>';
	    box.querySelectorAll('.boh-rowck').forEach(function(ck){ ck.onchange=function(){ ROWS[+ck.dataset.i].checked=ck.checked; updateCount(); }; });
	    updateCount();
	  }
	  document.getElementById('boh-sched-search').oninput=renderList;
	  document.getElementById('boh-sched-all').onchange=function(){ var c=this.checked; ROWS.forEach(function(r){r.checked=c;}); renderList(); };

	  function openCampaign(key,label,to){
	    cur=key; ROWS=[]; TOTAL=0;
	    document.getElementById('boh-sched-title').textContent='Send: '+label;
	    document.getElementById('boh-sched-sub').textContent='For '+to+'.';
	    document.getElementById('boh-sched-err').style.display='none';
	    document.getElementById('boh-sched-confirm').checked=false;
	    document.getElementById('boh-sched-search').value='';
	    setTab('list');
	    document.getElementById('boh-sched-list').innerHTML='Loading…';
	    show(); refresh();
	    post('boh_sched_list',{campaign:key}).then(function(res){
	      if(!res.success){document.getElementById('boh-sched-list').textContent='Could not load list.';return;}
	      TOTAL=res.data.total;
	      ROWS=res.data.list.map(function(p){return {id:p.id,name:p.name,email:p.email,party:p.party,checked:true};});
	      renderList();
	      document.getElementById('boh-prev-from').textContent=res.data.preview.from;
	      document.getElementById('boh-prev-subj').textContent=res.data.preview.subject;
	      document.getElementById('boh-prev-sample').textContent=res.data.preview.sample;
	      document.getElementById('boh-prev-body').innerHTML=res.data.preview.html;
	      if(res.data.total>res.data.shown){ document.getElementById('boh-sched-list').insertAdjacentHTML('beforeend','<p style="padding:8px 10px;color:#888;margin:0">Showing '+res.data.shown+' of '+res.data.total+'.</p>'); }
	    });
	  }
	  document.getElementById('boh-sched-go').onclick=function(){
	    var when=document.querySelector('input[name=boh-sched-when]:checked').value;
	    var at=document.getElementById('boh-sched-at').value;
	    var confirm=document.getElementById('boh-sched-confirm').checked?1:'';
	    var err=document.getElementById('boh-sched-err'); err.style.display='none';
	    if(!confirm){err.textContent='Please tick the confirmation box.';err.style.display='block';return;}
	    if(ROWS.length && ROWS.filter(function(r){return r.checked;}).length===0){err.textContent='No recipients are selected.';err.style.display='block';return;}
	    post('boh_sched_start',{campaign:cur,when:when==='now'?'now':at,confirm:confirm,exclude:excluded().join(',')}).then(function(res){
	      if(!res.success){err.textContent=res.data||'Could not start.';err.style.display='block';return;}
	      render(res.data);
	    });
	  };
	  document.getElementById('boh-sched-cancel').onclick=function(){ if(cur) post('boh_sched_cancel',{campaign:cur}).then(function(r){ if(r.success) render(r.data); }); };
	  function refresh(){ post('boh_sched_status',{}).then(function(r){ if(r.success) render(r.data); }); if(!poll) poll=setInterval(refresh,3000); }
	  function render(data){
	    if(!cur||!data.campaigns[cur]) return;
	    var c=data.campaigns[cur], running=(c.status==='running'||c.status==='scheduled');
	    document.getElementById('boh-sched-setup').style.display=running?'none':'block';
	    document.getElementById('boh-sched-progwrap').style.display=(c.status==='idle')?'none':'block';
	    if(c.status!=='idle'){
	      var pct=c.total? Math.round(100*c.sent/c.total):0;
	      document.getElementById('boh-sched-bar').style.width=pct+'%';
	      var txt='';
	      if(c.status==='scheduled') txt='Scheduled for '+c.scheduled_at+'. '+c.remaining+' to send.';
	      else if(c.status==='running') txt='Sending… '+c.sent+' of '+c.total+' ('+pct+'%)'+(c.failed?(', '+c.failed+' failed'):'')+'. '+c.remaining+' left.';
	      else if(c.status==='done') txt='Done. '+c.sent+' sent'+(c.failed?(', '+c.failed+' failed'):'')+'.';
	      else if(c.status==='canceled') txt='Canceled. '+c.sent+' sent before cancelling.';
	      if(data.stopped) txt='PAUSED by the global stop. '+txt;
	      document.getElementById('boh-sched-progtext').textContent=txt;
	      document.getElementById('boh-sched-cancel').style.display=running?'inline-block':'none';
	    }
	  }
	  document.querySelectorAll('.boh-sched-open').forEach(function(b){ b.addEventListener('click',function(){ openCampaign(b.dataset.k,b.dataset.label,b.dataset.to); }); });
	  var stopBtn=document.getElementById('boh-sched-stopall');
	  if(stopBtn){ stopBtn.addEventListener('click',function(){ post('boh_sched_stop',{engage:stopBtn.dataset.on==='1'?'':'1'}).then(function(r){ if(r.success) location.reload(); }); }); }
	})();
	</script>
	<?php
} );
