<?php
/**
 * Plugin Name: BoH - Bulk email scheduler
 * Description: Schedule the three bulk emails (invitation, reminder, event
 *   details) from the Invitations Settings screen - send now or at a chosen
 *   time, each in a modal that shows the recipient list and a live progress
 *   bar, all three able to run in parallel. Guardrails: every start needs an
 *   explicit confirmation, a single "Pause all" emergency stop halts every
 *   running campaign at once, no one is ever sent the same email twice, and all
 *   campaigns share one daily send budget so parallel runs cannot exceed it.
 */
defined( 'ABSPATH' ) || exit;

const BOH_SCHED_OPT   = 'boh_inv_campaigns';     // per-campaign state
const BOH_SCHED_STOP  = 'boh_inv_emergency_stop'; // bool: pause everything
const BOH_SCHED_HOOK  = 'boh_inv_scheduler_tick';
const BOH_SCHED_NONCE = 'boh_inv_sched';

// ── Campaign definitions ───────────────────────────────────────
function boh_sched_campaigns(): array {
	return [
		'invitation' => [
			'label'  => 'Invitations',
			'tpl'    => 'invitation',
			'stamp'  => 'invitation_sent_at',
			'where'  => 'invitation_sent_at IS NULL',
			'to'     => 'people not yet invited',
		],
		'reminder' => [
			'label'  => 'Reminders',
			'tpl'    => 'reminder',
			'stamp'  => 'reminder_sent_at',
			'where'  => "invitation_sent_at IS NOT NULL AND responded_at IS NULL AND reminder_sent_at IS NULL",
			'to'     => "invited guests who haven't replied",
		],
		'details' => [
			'label'  => 'Event details',
			'tpl'    => 'details',
			'stamp'  => 'details_sent_at',
			'where'  => "responded_at IS NOT NULL AND ( party_size IS NULL OR party_size <> 'Not attending' ) AND details_sent_at IS NULL",
			'to'     => "guests who RSVP'd yes",
		],
	];
}

function boh_sched_eligible( string $key, $limit = 0 ) {
	global $wpdb;
	$defs = boh_sched_campaigns();
	if ( ! isset( $defs[ $key ] ) ) return [];
	$t = boh_invitations_table();
	$sql = "SELECT * FROM $t WHERE " . $defs[ $key ]['where'] . " ORDER BY name ASC";
	if ( $limit > 0 ) $sql .= ' LIMIT ' . (int) $limit;
	return $wpdb->get_results( $sql );
}
function boh_sched_eligible_count( string $key ): int {
	global $wpdb;
	$defs = boh_sched_campaigns();
	if ( ! isset( $defs[ $key ] ) ) return 0;
	$t = boh_invitations_table();
	return (int) $wpdb->get_var( "SELECT COUNT(*) FROM $t WHERE " . $defs[ $key ]['where'] );
}

function boh_sched_state(): array {
	$s = get_option( BOH_SCHED_OPT, [] );
	$s = is_array( $s ) ? $s : [];
	foreach ( array_keys( boh_sched_campaigns() ) as $k ) {
		$s[ $k ] = wp_parse_args( $s[ $k ] ?? [], [
			'status' => 'idle', 'scheduled_at' => 0, 'started_at' => 0,
			'finished_at' => 0, 'total' => 0, 'sent' => 0, 'failed' => 0, 'by' => '',
		] );
	}
	return $s;
}
function boh_sched_save( array $s ): void { update_option( BOH_SCHED_OPT, $s, false ); }

// Daily budget shared by all campaigns, counting every email stamped today.
function boh_sched_sent_today(): int {
	global $wpdb;
	$t = boh_invitations_table();
	$d = current_time( 'Y-m-d' );
	return (int) $wpdb->get_var( $wpdb->prepare(
		"SELECT COUNT(*) FROM $t WHERE DATE(invitation_sent_at)=%s OR DATE(reminder_sent_at)=%s OR DATE(details_sent_at)=%s",
		$d, $d, $d ) );
}

// ── The processor: one tick, batched, parallel, capped ─────────
add_filter( 'cron_schedules', function ( $s ) {
	$s['boh_minute'] = [ 'interval' => 60, 'display' => 'Every minute' ];
	return $s;
} );
add_action( 'init', function () {
	if ( ! wp_next_scheduled( BOH_SCHED_HOOK ) ) {
		wp_schedule_event( time() + 60, 'boh_minute', BOH_SCHED_HOOK );
	}
} );
add_action( BOH_SCHED_HOOK, 'boh_sched_tick' );

function boh_sched_tick() {
	if ( get_option( BOH_SCHED_STOP ) ) return;          // emergency stop engaged
	global $wpdb;
	$t      = boh_invitations_table();
	$defs   = boh_sched_campaigns();
	$s      = boh_sched_state();
	$limits = get_option( BOH_INV_OPT_LIMITS );
	$per_day   = max( 1, (int) ( $limits['per_day'] ?? 250 ) );
	$per_batch = max( 1, (int) ( $limits['per_batch'] ?? 15 ) );

	// Flip any due scheduled campaign to running, snapshotting its size.
	$now = time();
	foreach ( $defs as $key => $d ) {
		if ( $s[ $key ]['status'] === 'scheduled' && $s[ $key ]['scheduled_at'] <= $now ) {
			$s[ $key ]['status']     = 'running';
			$s[ $key ]['started_at'] = $now;
			$s[ $key ]['total']      = boh_sched_eligible_count( $key );
			$s[ $key ]['sent']       = 0;
			$s[ $key ]['failed']     = 0;
		}
	}

	$budget = min( $per_batch, max( 0, $per_day - boh_sched_sent_today() ) );
	if ( $budget <= 0 ) { boh_sched_save( $s ); return; }

	foreach ( $defs as $key => $d ) {
		if ( $budget <= 0 ) break;
		if ( $s[ $key ]['status'] !== 'running' ) continue;
		$rows = boh_sched_eligible( $key, $budget );
		if ( ! $rows ) {
			$s[ $key ]['status'] = 'done';
			$s[ $key ]['finished_at'] = $now;
			continue;
		}
		foreach ( $rows as $inv ) {
			if ( $budget <= 0 ) break;
			// manual=true: the per-campaign confirmation is the authorisation;
			// the emergency stop (checked above) is the global brake.
			$ok = boh_invitations_send_email( $inv, $d['tpl'], true );
			if ( $ok ) {
				$wpdb->update( $t,
					[ $d['stamp'] => current_time( 'mysql', true ), 'updated_at' => current_time( 'mysql', true ) ],
					[ 'id' => $inv->id ] );
				$s[ $key ]['sent']++;
			} else {
				$s[ $key ]['failed']++;
			}
			$budget--;
		}
		if ( boh_sched_eligible_count( $key ) === 0 ) {
			$s[ $key ]['status'] = 'done';
			$s[ $key ]['finished_at'] = $now;
		}
	}
	boh_sched_save( $s );
}

// ── AJAX: list, start, cancel, status, stop ────────────────────
function boh_sched_guard() {
	if ( ! current_user_can( BOH_INV_CAP ) ) wp_send_json_error( 'forbidden', 403 );
	check_ajax_referer( BOH_SCHED_NONCE );
}
function boh_sched_progress_payload(): array {
	$s = boh_sched_state();
	$out = [ 'stopped' => (bool) get_option( BOH_SCHED_STOP ), 'campaigns' => [] ];
	foreach ( boh_sched_campaigns() as $key => $d ) {
		$remaining = boh_sched_eligible_count( $key );
		$out['campaigns'][ $key ] = [
			'label'  => $d['label'],
			'status' => $s[ $key ]['status'],
			'total'  => (int) $s[ $key ]['total'],
			'sent'   => (int) $s[ $key ]['sent'],
			'failed' => (int) $s[ $key ]['failed'],
			'remaining'    => $remaining,
			'scheduled_at' => $s[ $key ]['scheduled_at'] ? get_date_from_gmt( gmdate( 'Y-m-d H:i:s', $s[ $key ]['scheduled_at'] ), 'M j, g:i a' ) : '',
		];
	}
	return $out;
}
add_action( 'wp_ajax_boh_sched_status', function () {
	boh_sched_guard();
	wp_send_json_success( boh_sched_progress_payload() );
} );
add_action( 'wp_ajax_boh_sched_list', function () {
	boh_sched_guard();
	$key = sanitize_key( $_POST['campaign'] ?? '' );
	if ( ! isset( boh_sched_campaigns()[ $key ] ) ) wp_send_json_error( 'bad campaign' );
	$rows = boh_sched_eligible( $key, 1000 );
	$list = array_map( function ( $r ) {
		return [ 'name' => $r->name ?: '-', 'email' => $r->email, 'party' => $r->party_size ?: '' ];
	}, $rows );
	wp_send_json_success( [ 'total' => boh_sched_eligible_count( $key ), 'shown' => count( $list ), 'list' => $list ] );
} );
add_action( 'wp_ajax_boh_sched_start', function () {
	boh_sched_guard();
	$key = sanitize_key( $_POST['campaign'] ?? '' );
	if ( ! isset( boh_sched_campaigns()[ $key ] ) ) wp_send_json_error( 'bad campaign' );
	if ( empty( $_POST['confirm'] ) ) wp_send_json_error( 'Please tick the confirmation box first.' );
	$when = sanitize_text_field( $_POST['when'] ?? 'now' );
	$s = boh_sched_state();
	if ( in_array( $s[ $key ]['status'], [ 'running', 'scheduled' ], true ) ) {
		wp_send_json_error( 'That campaign is already ' . $s[ $key ]['status'] . '.' );
	}
	$eligible = boh_sched_eligible_count( $key );
	if ( $eligible <= 0 ) wp_send_json_error( 'No one is eligible for that email right now.' );
	$user = wp_get_current_user();
	if ( $when === 'now' ) {
		$s[ $key ] = [ 'status' => 'running', 'scheduled_at' => 0, 'started_at' => time(),
			'finished_at' => 0, 'total' => $eligible, 'sent' => 0, 'failed' => 0, 'by' => $user->user_login ];
	} else {
		$ts = strtotime( $when ); // the datetime-local value is in site time
		if ( ! $ts ) wp_send_json_error( 'Could not read that date/time.' );
		$gmt = $ts - ( (int) ( get_option( 'gmt_offset' ) * 3600 ) );
		if ( $gmt < time() - 60 ) wp_send_json_error( 'That time is in the past.' );
		$s[ $key ] = [ 'status' => 'scheduled', 'scheduled_at' => $gmt, 'started_at' => 0,
			'finished_at' => 0, 'total' => $eligible, 'sent' => 0, 'failed' => 0, 'by' => $user->user_login ];
	}
	boh_sched_save( $s );
	wp_send_json_success( boh_sched_progress_payload() );
} );
add_action( 'wp_ajax_boh_sched_cancel', function () {
	boh_sched_guard();
	$key = sanitize_key( $_POST['campaign'] ?? '' );
	if ( ! isset( boh_sched_campaigns()[ $key ] ) ) wp_send_json_error( 'bad campaign' );
	$s = boh_sched_state();
	$s[ $key ]['status'] = 'canceled';
	$s[ $key ]['finished_at'] = time();
	boh_sched_save( $s );
	wp_send_json_success( boh_sched_progress_payload() );
} );
add_action( 'wp_ajax_boh_sched_stop', function () {
	boh_sched_guard();
	$engage = ! empty( $_POST['engage'] );
	update_option( BOH_SCHED_STOP, $engage ? 1 : 0, false );
	wp_send_json_success( boh_sched_progress_payload() );
} );

// ── Assets + modal markup on the Settings screen ───────────────
add_action( 'admin_footer', function () {
	$screen = get_current_screen();
	if ( ! $screen || strpos( $screen->id, BOH_INV_MENU_SLUG . '-settings' ) === false ) return;
	$nonce = wp_create_nonce( BOH_SCHED_NONCE );
	?>
	<div id="boh-sched-modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:100000;align-items:flex-start;justify-content:center">
	  <div style="background:#fff;border-radius:8px;max-width:760px;width:92%;margin:4vh 0;max-height:92vh;overflow:auto;box-shadow:0 10px 40px rgba(0,0,0,.3)">
	    <div style="display:flex;justify-content:space-between;align-items:center;padding:16px 22px;border-bottom:1px solid #e0e0e0">
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
	        <p style="margin:0 0 8px"><strong id="boh-sched-count"></strong> will receive this email. <a href="<?php echo esc_url( admin_url( 'admin.php?page=' . BOH_INV_MENU_SLUG . '-templates' ) ); ?>" target="_blank">Edit wording</a></p>
	        <div id="boh-sched-list" style="max-height:220px;overflow:auto;border:1px solid #e0e0e0;border-radius:4px;margin-bottom:16px;font-size:13px"></div>
	        <fieldset style="border:1px solid #e0e0e0;border-radius:4px;padding:12px 14px;margin-bottom:12px">
	          <label style="display:block;margin-bottom:6px"><input type="radio" name="boh-sched-when" value="now" checked> Send now</label>
	          <label style="display:block"><input type="radio" name="boh-sched-when" value="at"> At a set time: <input type="datetime-local" id="boh-sched-at"></label>
	        </fieldset>
	        <p><label><input type="checkbox" id="boh-sched-confirm"> I have reviewed the email and this recipient list, and I want to send it.</label></p>
	        <p id="boh-sched-err" style="color:#b32d2e;display:none"></p>
	        <button type="button" class="button button-primary button-large" id="boh-sched-go">Start</button>
	      </div>
	    </div>
	  </div>
	</div>
	<script>
	(function(){
	  var A=ajaxurl, N='<?php echo esc_js( $nonce ); ?>', cur=null, poll=null;
	  function post(action,data){ data=data||{}; data.action=action; data._ajax_nonce=N;
	    return fetch(A,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:new URLSearchParams(data)}).then(function(r){return r.json();}); }
	  var modal=document.getElementById('boh-sched-modal');
	  function show(){modal.style.display='flex';} function hide(){modal.style.display='none'; if(poll){clearInterval(poll);poll=null;}}
	  document.getElementById('boh-sched-close').onclick=hide;
	  modal.onclick=function(e){ if(e.target===modal) hide(); };
	  function openCampaign(key,label,to){
	    cur=key;
	    document.getElementById('boh-sched-title').textContent='Send: '+label;
	    document.getElementById('boh-sched-sub').textContent='For '+to+'.';
	    document.getElementById('boh-sched-err').style.display='none';
	    document.getElementById('boh-sched-confirm').checked=false;
	    var lst=document.getElementById('boh-sched-list'); lst.innerHTML='Loading recipients…';
	    show(); refresh();
	    post('boh_sched_list',{campaign:key}).then(function(res){
	      if(!res.success){lst.textContent='Could not load list.';return;}
	      document.getElementById('boh-sched-count').textContent=res.data.total+' recipient'+(res.data.total===1?'':'s');
	      var h='<table style="width:100%;border-collapse:collapse">';
	      res.data.list.forEach(function(p){ h+='<tr><td style="padding:4px 10px;border-bottom:1px solid #f0f0f0"><strong>'+esc(p.name)+'</strong></td><td style="padding:4px 10px;border-bottom:1px solid #f0f0f0;color:#555">'+esc(p.email)+'</td><td style="padding:4px 10px;border-bottom:1px solid #f0f0f0;color:#888">'+esc(p.party)+'</td></tr>'; });
	      h+='</table>'; if(res.data.total>res.data.shown){ h+='<p style="padding:8px 10px;color:#888;margin:0">…and '+(res.data.total-res.data.shown)+' more.</p>'; }
	      lst.innerHTML=res.data.total? h : '<p style="padding:10px;color:#888;margin:0">No one is eligible right now.</p>';
	    });
	  }
	  function esc(s){var d=document.createElement('div');d.textContent=s==null?'':s;return d.innerHTML;}
	  document.getElementById('boh-sched-go').onclick=function(){
	    var when=document.querySelector('input[name=boh-sched-when]:checked').value;
	    var at=document.getElementById('boh-sched-at').value;
	    var confirm=document.getElementById('boh-sched-confirm').checked?1:'';
	    var err=document.getElementById('boh-sched-err'); err.style.display='none';
	    if(!confirm){err.textContent='Please tick the confirmation box.';err.style.display='block';return;}
	    post('boh_sched_start',{campaign:cur,when:when==='now'?'now':at,confirm:confirm}).then(function(res){
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
	      else if(c.status==='canceled') txt='Canceled. '+c.sent+' were sent before cancelling.';
	      if(data.stopped) txt='PAUSED by the global stop. '+txt;
	      document.getElementById('boh-sched-progtext').textContent=txt;
	      document.getElementById('boh-sched-cancel').style.display=running?'inline-block':'none';
	    }
	    // reflect state onto the page cards too
	    var badge=document.querySelector('.boh-sched-badge[data-k="'+cur+'"]');
	    if(badge) badge.textContent=c.status==='idle'?'':('· '+c.status);
	  }
	  document.querySelectorAll('.boh-sched-open').forEach(function(b){
	    b.addEventListener('click',function(){ openCampaign(b.dataset.k, b.dataset.label, b.dataset.to); });
	  });
	  var stopBtn=document.getElementById('boh-sched-stopall');
	  if(stopBtn){ stopBtn.addEventListener('click',function(){
	    var engage=stopBtn.dataset.on==='1'?'':'1';
	    post('boh_sched_stop',{engage:engage}).then(function(r){ if(r.success){ location.reload(); } });
	  }); }
	})();
	</script>
	<?php
} );
