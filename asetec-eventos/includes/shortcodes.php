<?php
if (!defined('ABSPATH')) exit;

/**
 * [asetec_event_board event_code="PA2025" per_page="50"]
 * Tabla pública con búsqueda, filtros y export CSV.
 */
function asetec_sc_event_board($atts=[]) {
  $a = shortcode_atts([
    'event_code' => '',
    'per_page'   => 50,
  ], $atts);

  $event_code = sanitize_text_field($a['event_code']);
  $per_page   = max(1, intval($a['per_page']));
  if (!$event_code) return '<em>Falta <code>event_code</code>.</em>';

  $list_url   = esc_url_raw( rest_url('asetec/v1/board') );
  $export_url = esc_url_raw( rest_url('asetec/v1/board/export') );

  ob_start(); ?>
  <div class="asetec-board" data-event="<?php echo esc_attr($event_code); ?>">
    <div class="asetec-board-controls" style="display:flex; gap:8px; align-items:center; flex-wrap:wrap; margin-bottom:10px;">
      <input type="text" id="ab-search" placeholder="Buscar por cédula o nombre" style="padding:6px; min-width:260px;">
      <select id="ab-status" style="padding:6px;">
        <option value="all">Todos</option>
        <option value="no_qr">No solicitó QR</option>
        <option value="qr">Solicitó QR</option>
        <option value="checked_in">Check-in</option>
        <option value="qr_no_checkin">Solicitó, sin Check-in</option>
        <option value="cap_available">Con cupo disponible</option>

      </select>
      <button id="ab-export" class="button">Exportar CSV</button>
    </div>

    <table class="widefat fixed striped" id="ab-table">
    <thead>
      <tr>
        <th style="width:140px;">Cédula</th>
        <th>Nombre</th>
        <th style="text-align:center;">No solicitó QR</th>
        <th style="text-align:center;">Solicitó QR</th>
        <th style="text-align:center;">Check-in</th>
        <th style="text-align:center;">Cupo</th>
      </tr>
    </thead>

      <tbody></tbody>
    </table>

    <div id="ab-pager" style="margin-top:10px; display:flex; gap:6px; align-items:center; flex-wrap:wrap;"></div>
  </div>

  <script>
  (function(){
    const listURL   = <?php echo json_encode($list_url); ?>;
    const exportURL = <?php echo json_encode($export_url); ?>;
    const EVENT     = <?php echo json_encode($event_code); ?>;
    const PER_PAGE  = <?php echo (int)$per_page; ?>;

    const $search = document.getElementById('ab-search');
    const $status = document.getElementById('ab-status');
    const $tbody  = document.querySelector('#ab-table tbody');
    const $pager  = document.getElementById('ab-pager');
    const $export = document.getElementById('ab-export');

    let page = 1, typingTimer = null;
    const mark = b => (b ? '✓' : '–');

    function fetchData() {
      const q = $search.value.trim();
      const s = $status.value;

      const url = new URL(listURL, window.location.origin);
      url.searchParams.set('event_code', EVENT);
      url.searchParams.set('page', page);
      url.searchParams.set('per_page', PER_PAGE);
      if (q) url.searchParams.set('q', q);
      if (s && s !== 'all') url.searchParams.set('status', s);

      fetch(url.toString())
        .then(r => r.json())
        .then(data => {
          $tbody.innerHTML = '';
          (data.items || []).forEach(it => {
            const tr = document.createElement('tr');
            const cupo = (it.solicito_qr ? `${it.consumed||0}/${it.capacity||0}` : '–');
            tr.innerHTML = `
              <td>${it.cedula}</td>
              <td>${it.nombre}</td>
              <td style="text-align:center">${mark(it.no_qr)}</td>
              <td style="text-align:center">${mark(it.solicito_qr)}</td>
              <td style="text-align:center">${mark(it.check_in)}</td>
              <td style="text-align:center">${cupo}</td>
            `;

            $tbody.appendChild(tr);
          });

          const total = data.total || 0;
          const pages = Math.max(1, Math.ceil(total / PER_PAGE));
          $pager.innerHTML = '';
          for (let p=1; p<=pages; p++) {
            const btn = document.createElement('button');
            btn.className = 'button' + (p === page ? ' button-secondary' : '');
            btn.textContent = p;
            btn.addEventListener('click', () => { page = p; fetchData(); });
            $pager.appendChild(btn);
          }

          const eurl = new URL(exportURL, window.location.origin);
          eurl.searchParams.set('event_code', EVENT);
          if (q) eurl.searchParams.set('q', q);
          if (s && s !== 'all') eurl.searchParams.set('status', s);
          $export.onclick = () => { window.open(eurl.toString(), '_blank'); };
        })
        .catch(err => {
          console.error('ASETEC board fetch error', err);
          $tbody.innerHTML = '<tr><td colspan="5"><em>Error al cargar datos.</em></td></tr>';
          $pager.innerHTML = '';
        });
    }

    $status.addEventListener('change', () => { page = 1; fetchData(); });
    $search.addEventListener('input', () => {
      clearTimeout(typingTimer);
      typingTimer = setTimeout(() => { page = 1; fetchData(); }, 250);
    });

    fetchData();
  })();
  </script>
  <?php
  return ob_get_clean();
}
add_shortcode('asetec_event_board', 'asetec_sc_event_board');


add_shortcode('asetec_checkin', function($atts){
  if (!is_user_logged_in() || !current_user_can('manage_options')) {
    return '<div style="padding:12px;background:#fff3cd;border:1px solid #ffeeba;border-radius:6px">Debes iniciar sesión como staff.</div>';
  }

  $a = shortcode_atts(['event_code'=>'' ], $atts);
  if ($a['event_code']==='') {
    return '<div style="padding:12px;background:#f8d7da;border:1px solid #f5c2c7;border-radius:6px">Falta <code>event_code</code> en el shortcode.</div>';
  }

  $nonce  = wp_create_nonce('wp_rest');
  $api    = esc_url_raw( rest_url('asetec/v1') );
  $event  = esc_js($a['event_code']);

  ob_start(); ?>
  <div id="asetec-ci" style="max-width:980px;margin:12px auto;font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif">
    <h2 style="margin:0 0 12px">Check-in — <strong><?php echo esc_html($a['event_code']); ?></strong></h2>

    <div style="display:flex;gap:10px;margin-bottom:10px">
      <button class="button button-primary" data-tab="qr">Escanear QR</button>
      <button class="button" data-tab="cedula">Buscar por cédula</button>
      <span style="margin-left:auto;color:#666;font-size:12px">Sugerencia: en PC puedes usar lector USB (modo teclado) sobre el campo de token.</span>
    </div>

    <div id="tab-qr" class="tab-pane" style="border:1px solid #ddd;border-radius:8px;padding:12px">
      <div style="display:flex;gap:16px;flex-wrap:wrap">
        <div style="flex:1;min-width:280px">
          <div style="display:flex;gap:8px;align-items:center;margin-bottom:8px">
            <label for="ci-camera">Cámara:</label>
            <select id="ci-camera" style="flex:1;padding:6px;border:1px solid #ccc;border-radius:6px"><option>Cargando…</option></select>
            <button id="ci-cam-start" class="button">Iniciar</button>
            <button id="ci-cam-stop" class="button">Detener</button>
          </div>
          <div id="qr-reader" style="width:100%;min-height:260px;border:1px dashed #bbb;border-radius:8px"></div>
          <div id="qr-status" style="margin-top:6px;color:#666;font-size:12px"></div>

          <div style="margin:10px 0;text-align:center;color:#666">o ingresa manual:</div>
          <div style="display:flex;gap:8px">
            <input id="ci-token" type="text" placeholder="token o URL con ?tid=" style="flex:1;padding:8px;border:1px solid #ccc;border-radius:6px">
            <input id="ci-qty" type="number" min="1" value="1" style="width:90px;padding:8px;border:1px solid #ccc;border-radius:6px">
            <button id="ci-lookup" class="button button-primary">Buscar</button>
          </div>
        </div>

        <div style="flex:1;min-width:280px">
          <div id="ci-result" style="border:1px solid #ddd;border-radius:8px;padding:12px;min-height:180px">
            <em>Escanea un QR o ingresa un token para ver el ticket…</em>
          </div>
        </div>
      </div>
    </div>

    <div id="tab-cedula" class="tab-pane" style="display:none;border:1px solid #ddd;border-radius:8px;padding:12px">
      <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
        <input id="ci-cedula" type="text" placeholder="Cédula (sin guiones)" style="flex:1;min-width:220px;padding:8px;border:1px solid #ccc;border-radius:6px">
        <input id="ci-qty2" type="number" min="1" value="1" style="width:90px;padding:8px;border:1px solid #ccc;border-radius:6px">
        <button id="ci-ced-lookup" class="button button-primary">Buscar</button>
      </div>
      <div id="ci-ced-result" style="margin-top:10px;border:1px solid #ddd;border-radius:8px;padding:12px;min-height:140px">
        <em>Ingresa una cédula para recuperar el ticket…</em>
      </div>
    </div>
  </div>

  <script src="https://unpkg.com/html5-qrcode@2.3.10/html5-qrcode.min.js"></script>
  <script>
  (function(){
    const API   = "<?php echo esc_js($api); ?>";
    const NONCE = "<?php echo esc_js($nonce); ?>";
    const EVENT = "<?php echo esc_js($event); ?>";

    // Tabs
    const tabBtns = document.querySelectorAll('#asetec-ci [data-tab]');
    const panes = { qr: document.getElementById('tab-qr'), cedula: document.getElementById('tab-cedula') };
    tabBtns.forEach(b => b.onclick = () => {
      tabBtns.forEach(x => x.classList.remove('button-primary'));
      b.classList.add('button-primary');
      panes.qr.style.display = (b.dataset.tab==='qr'?'block':'none');
      panes.cedula.style.display = (b.dataset.tab==='cedula'?'block':'none');
    });

    // UI elements
    const $camSel  = document.getElementById('ci-camera');
    const $camStart= document.getElementById('ci-cam-start');
    const $camStop = document.getElementById('ci-cam-stop');
    const $qrBox   = document.getElementById('qr-reader');
    const $status  = document.getElementById('qr-status');
    const $token   = document.getElementById('ci-token');
    const $qty     = document.getElementById('ci-qty');
    const $lookup  = document.getElementById('ci-lookup');
    const $res     = document.getElementById('ci-result');

    const $ced    = document.getElementById('ci-cedula');
    const $qty2   = document.getElementById('ci-qty2');
    const $cedBtn = document.getElementById('ci-ced-lookup');
    const $cedRes = document.getElementById('ci-ced-result');

    // Token helpers
    function extractToken(text){
      try { const u=new URL(text); const tid=u.searchParams.get('tid'); if(tid) return tid; } catch(e){}
      return (text||'').trim();
    }
    function beepOK(){ try{ new Audio('data:audio/wav;base64,UklGRiQAAABXQVZFZm10IBAAAAABAAEAESsAACJWAAACABYAAAABAA==').play(); }catch(e){} }

    // ====== QR cámara ======
    let scanner = null;
    async function listCams(){
      try{
        const cams = await Html5Qrcode.getCameras();
        $camSel.innerHTML = '';
        cams.forEach((c,i)=>{
          const opt=document.createElement('option');
          opt.value=c.id; opt.textContent=c.label || ('Cámara '+(i+1));
          $camSel.appendChild(opt);
        });
        // auto-selección: si móvil intenta cámara trasera
        if (/Mobi|Android/i.test(navigator.userAgent) && cams.length>1) {
          const back = cams.find(c => /back|trasera|rear/i.test(c.label));
          if (back) $camSel.value = back.id;
        }
      }catch(e){
        $camSel.innerHTML = '<option>No hay cámaras</option>';
      }
    }
    async function startCam(){
      if (scanner) return;
      scanner = new Html5Qrcode("qr-reader");
      const camId = $camSel.value;
      $status.textContent = 'Iniciando cámara…';
      try{
        await scanner.start(
          { deviceId: { exact: camId } },
          { fps: 10, qrbox: 250 },
          onScanSuccess,
          onScanFailure
        );
        $status.textContent = 'Cámara activa';
      }catch(e){
        $status.textContent = 'No se pudo iniciar la cámara';
      }
    }
    async function stopCam(){
      if (!scanner) return;
      try{ await scanner.stop(); scanner.clear(); }catch(e){}
      scanner = null;
      $status.textContent = 'Cámara detenida';
    }
    function onScanSuccess(decodedText){
      const tok = extractToken(decodedText);
      $token.value = tok;
      $status.textContent = 'QR leído: ' + (tok ? tok.slice(0,16)+'…' : '');
      doLookup(tok);
      beepOK();
    }
    function onScanFailure(err){ /* silencio */ }

    $camStart.onclick = startCam;
    $camStop.onclick  = stopCam;
    listCams(); // cargar lista al abrir

    // ====== Lookup por token (QR o lector USB) ======
    async function doLookup(tok){
      if (!tok) { $res.innerHTML='<em>Token vacío</em>'; return; }
      $res.innerHTML = '<em>Buscando…</em>';
      const url = `${API}/checkin/lookup?event_code=${encodeURIComponent(EVENT)}&token=${encodeURIComponent(tok)}`;
      const r = await fetch(url, { credentials:'same-origin' });
      const j = await r.json();
      if (!r.ok) { $res.innerHTML = `<div style="color:#b00020">Error: ${j.message||'No encontrado'}</div>`; return; }
      const t=j.ticket, rem=j.remaining;
      $res.innerHTML = `
        <div><strong>Entrada:</strong> #${t.entry_number}</div>
        <div><strong>Asociado:</strong> ${t.nombre} — cédula ${t.cedula}</div>
        <div><strong>Cupo:</strong> ${t.consumed}/${t.capacity} &nbsp; <small>${rem} restantes</small></div>
        <div style="margin-top:10px"><button id="ci-confirm" class="button button-primary">
          Confirmar ingreso (${Math.max(1, parseInt($qty.value||1))})
        </button></div>
      `;
      document.getElementById('ci-confirm').onclick = () => doConfirm({ token: tok });
    }
    async function doConfirm(ident){
      const qty = Math.max(1, parseInt(($qty.value||$qty2.value||1)));
      const r = await fetch(`${API}/checkin`, {
        method:'POST', credentials:'same-origin',
        headers:{ 'Content-Type':'application/json','X-WP-Nonce':NONCE },
        body: JSON.stringify(Object.assign({ event_code: EVENT, qty }, ident))
      });
      const j = await r.json();
      if (!r.ok) {
        const box = panes.qr.style.display!=='none' ? $res : $cedRes;
        box.innerHTML = `<div style="color:#b00020"><strong>Error:</strong> ${j.message||'No se pudo registrar'}</div>`;
        return;
      }
      const box = panes.qr.style.display!=='none' ? $res : $cedRes;
      box.innerHTML = `
        <div style="color:#0a7f2e"><strong>${j.message}</strong></div>
        <div><strong>Entrada:</strong> #${j.ticket.entry_number}</div>
        <div><strong>Asociado:</strong> ${j.ticket.nombre} — cédula ${j.ticket.cedula}</div>
        <div><strong>Cupo:</strong> ${j.ticket.consumed}/${j.ticket.capacity} &nbsp; <small>${j.remaining} restantes</small></div>
      `;
      beepOK();
    }
    $lookup.addEventListener('click', ()=> doLookup(extractToken($token.value)));
    $token.addEventListener('keydown', e => { if (e.key==='Enter') { e.preventDefault(); $lookup.click(); } });

    // ====== Lookup por cédula ======
    async function doLookupCed(){
      const ced = ($ced.value||'').trim();
      if (!ced) { $cedRes.innerHTML='<em>Ingresa una cédula</em>'; return; }
      $cedRes.innerHTML = '<em>Buscando…</em>';
      const url = `${API}/checkin/lookup-by-cedula?event_code=${encodeURIComponent(EVENT)}&cedula=${encodeURIComponent(ced)}`;
      const r = await fetch(url, { credentials:'same-origin' });
      const j = await r.json();
      if (!r.ok) { $cedRes.innerHTML = `<div style="color:#b00020">Error: ${j.message||'No encontrado'}</div>`; return; }
      const t=j.ticket, rem=j.remaining;
      $cedRes.innerHTML = `
        <div><strong>Entrada:</strong> #${t.entry_number}</div>
        <div><strong>Asociado:</strong> ${t.nombre} — cédula ${t.cedula}</div>
        <div><strong>Cupo:</strong> ${t.consumed}/${t.capacity} &nbsp; <small>${rem} restantes</small></div>
        <div style="margin-top:10px;display:flex;gap:8px;align-items:center">
          <button id="ci-confirm2" class="button button-primary">Confirmar ingreso (${Math.max(1, parseInt($qty2.value||1))})</button>
          <span style="color:#666;font-size:12px">*Sin QR</span>
        </div>
      `;
      document.getElementById('ci-confirm2').onclick = () => doConfirm({ ticket_id: t.id });
    }
    $cedBtn.onclick = doLookupCed;
    $ced.addEventListener('keydown', e => { if (e.key==='Enter'){ e.preventDefault(); doLookupCed(); } });

    // Si es móvil, intenta iniciar cámara al abrir la pestaña QR
    if (/Mobi|Android/i.test(navigator.userAgent)) {
      // pequeño delay para que cargue la lista
      setTimeout(()=>{ if (!$camSel.value) listCams(); startCam(); }, 400);
    }
  })();
  </script>
  <?php
  return ob_get_clean();
});