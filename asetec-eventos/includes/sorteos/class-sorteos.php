<?php
if (!defined('ABSPATH')) exit;

class Asetec_Sorteos {
public static function render_shortcode($atts) {
  global $wpdb;

  // Traer eventos para el selector (code + name)
  $ev_table = $wpdb->prefix . 'asetec_events';
  $events = $wpdb->get_results("SELECT code, name FROM {$ev_table} ORDER BY id DESC LIMIT 200", ARRAY_A);
  if (!is_array($events)) $events = [];

  ob_start(); ?>
  <div id="asetec-sorteo-app" style="max-width:960px;margin:12px auto;font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif">
  <h2 style="margin:0 0 12px">🎉 Sorteo de asistentes</h2>

  <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin-bottom:10px">
    <label><strong>Evento:</strong></label>
    <select id="sor-event" style="padding:6px;min-width:220px">
      <option value="">— Seleccioná un evento —</option>
      <?php foreach ($events as $e): ?>
        <option value="<?php echo esc_attr($e['code']); ?>">
          <?php echo esc_html($e['code'] . ' — ' . $e['name']); ?>
        </option>
      <?php endforeach; ?>
    </select>

    <label><strong>Participan:</strong></label>
    <select id="sor-filter" style="padding:6px">
      <option value="all">Todos</option>
      <option value="checked_in">Solo Check-in</option>
      <option value="no_qr">No solicitó QR</option>
      <option value="qr_no_checkin">Solicitó QR y NO asistió</option>
    </select>

    <button id="sor-load" class="button button-primary">Cargar lista</button>

    <label style="display:flex;gap:6px;align-items:center;margin-left:auto">
      <input id="sor-unique" type="checkbox" checked>
      Evitar ganadores repetidos
    </label>
  </div>

  <div id="sor-info" style="margin:6px 0 12px;color:#555"></div>

  <!-- CONTROLES EXTRA -->
  <div id="sor-actions" style="display:none; gap:8px; align-items:center; flex-wrap:wrap; margin:0 0 10px">
    <button id="btn-sortear" class="button button-primary">🎡 Girar ruleta</button>
    <button id="btn-sortear-5" class="button">Girar 5 veces</button>
    <button id="btn-export" class="button">Exportar ganadores (CSV)</button>
    <label style="display:flex;gap:6px;align-items:center">
      <input id="sor-sound" type="checkbox" checked>
      Sonido al ganar 🔊
    </label>
  </div>

  <!-- CONTENEDOR RULETA + CONFETI -->
  <div id="sor-wheel-wrap" style="display:none; position:relative; text-align:center">
    <canvas id="ruletaCanvas" width="520" height="520" style="max-width:100%"></canvas>
    <!-- confeti encima -->
    <canvas id="confettiCanvas" width="520" height="520" style="position:absolute; left:50%; top:0; transform:translateX(-50%); pointer-events:none;"></canvas>
  </div>

  <!-- TARJETA GANADOR -->
  <div id="sor-winner-card" style="display:none; margin-top:12px; padding:14px; border-radius:12px; background:linear-gradient(135deg,#f0fff4,#e6fffa); box-shadow:0 8px 20px rgba(0,0,0,.08);">
    <div style="display:flex; gap:12px; align-items:center;">
      <div id="winner-avatar" style="width:64px;height:64px;border-radius:50%;background:#ffe082;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:22px;color:#6d4c41;box-shadow:inset 0 0 0 3px rgba(0,0,0,.06)">🏆</div>
      <div>
        <div id="winner-name" style="font-size:20px;font-weight:800;color:#0a7f2e">Ganador</div>
        <div id="winner-id" style="color:#444"></div>
        <div id="sor-last" style="margin-top:6px;color:#666;font-size:12px"></div>
      </div>
    </div>
  </div>
</div>


  <script>
(function(){
  const exportURL = <?php echo json_encode( esc_url_raw( rest_url('asetec/v1/board/export') ) ); ?>;
  const listURL   = <?php echo json_encode( esc_url_raw( rest_url('asetec/v1/board') ) ); ?>;

  const $event  = document.getElementById('sor-event');
  const $filter = document.getElementById('sor-filter');
  const $load   = document.getElementById('sor-load');
  const $info   = document.getElementById('sor-info');
  const $wrap   = document.getElementById('sor-wheel-wrap');
  const $actions= document.getElementById('sor-actions');
  const $unique = document.getElementById('sor-unique');
  const $btn1   = document.getElementById('btn-sortear');
  const $btn5   = document.getElementById('btn-sortear-5');
  const $btnExp = document.getElementById('btn-export');
  const $sndChk = document.getElementById('sor-sound');

  const $card  = document.getElementById('sor-winner-card');
  const $winAv = document.getElementById('winner-avatar');
  const $winNm = document.getElementById('winner-name');
  const $winId = document.getElementById('winner-id');
  const $last  = document.getElementById('sor-last');

  const canvas  = document.getElementById('ruletaCanvas');
  const ctx     = canvas.getContext('2d');

  const c2  = document.getElementById('confettiCanvas');
  const ctx2= c2.getContext('2d');

  let participantes = [];     // [{cedula, nombre}]
  let historial     = [];     // ganadores en orden
  let ganadores     = [];     // para export
  let angleOffset   = 0;      // rotación actual
  let spinning      = false;

  // ----------- UTILIDADES -----------
  const initials = (name) => {
    const p = (name || '').trim().split(/\s+/).slice(0,2);
    return p.map(x => x[0] ? x[0].toUpperCase() : '').join('');
  };
  const playSound = () => {
    if (!$sndChk.checked) return;
    const audio = new Audio("data:audio/mp3;base64,//uQZAAAAAAAAAAAAAAAAAAAAAAAWGluZwAAAA8AAAACAAACcQCA...");
    // (clip muy corto; si quieres te paso uno más alegre)
    audio.play().catch(()=>{});
  };

  function downloadCSV(filename, rows){
    const header = ['Nombre','Cédula','Fecha/Hora'];
    const lines = [header].concat(rows.map(r => [r.nombre, r.cedula, r.at]));
    const csv = lines.map(cols =>
      cols.map(v => {
        v = (v==null?'':String(v));
        return /[",\n]/.test(v) ? `"${v.replace(/"/g,'""')}"` : v;
      }).join(',')
    ).join('\n');
    const blob = new Blob([csv], {type:'text/csv;charset=utf-8;'});
    const a = document.createElement('a');
    a.href = URL.createObjectURL(blob);
    a.download = filename;
    document.body.appendChild(a); a.click(); a.remove();
    setTimeout(()=>URL.revokeObjectURL(a.href), 1000);
  }

  // ----------- CONFETI (simple) -----------
  function burstConfetti(ms=1200){
    const W = c2.width, H = c2.height;
    const parts = [];
    for (let i=0;i<120;i++){
      parts.push({
        x: W/2, y: H/3,
        vx: (Math.random()*2-1) * 5,
        vy: (Math.random()*-1) * 6 - 3,
        g: 0.18 + Math.random()*0.1,
        s: 2 + Math.random()*3,
        c: `hsl(${Math.floor(Math.random()*360)},90%,60%)`,
        a: 1
      });
    }
    const t0 = performance.now();
    function draw(now){
      const t = now - t0;
      ctx2.clearRect(0,0,W,H);
      parts.forEach(p=>{
        p.x += p.vx; p.y += p.vy; p.vy += p.g; p.a -= 0.005;
        ctx2.globalAlpha = Math.max(0, p.a);
        ctx2.fillStyle = p.c;
        ctx2.fillRect(p.x, p.y, p.s, p.s);
      });
      ctx2.globalAlpha = 1;
      if (t < ms) requestAnimationFrame(draw); else ctx2.clearRect(0,0,W,H);
    }
    requestAnimationFrame(draw);
  }

  // ----------- DIBUJO RULETA -----------
  function drawWheel(items){
    const total = items.length;
    const r = canvas.width/2;
    const cx = r, cy = r;
    ctx.clearRect(0,0,canvas.width,canvas.height);
    if (!total) return;

    // Fondo gradiente suave
    const grad = ctx.createRadialGradient(cx, cy, r*0.1, cx, cy, r*0.95);
    grad.addColorStop(0, '#fff7e6');
    grad.addColorStop(1, '#ffe0a3');
    ctx.fillStyle = grad;
    ctx.beginPath();
    ctx.arc(cx, cy, r, 0, Math.PI*2);
    ctx.fill();

    const slice = (2*Math.PI)/total;
    let fontSize = 16;
    if (total >= 20)  fontSize = 14;
    if (total >= 60)  fontSize = 12;
    if (total >= 120) fontSize = 10;
    const showEvery = (total > 40) ? Math.ceil(total / 40) : 1;
    const textR = r - 26;
    const innerR = r - 16;

    for (let i=0;i<total;i++){
      const start = angleOffset + i*slice;
      const end   = start + slice;

      // Sector
      ctx.beginPath();
      ctx.moveTo(cx, cy);
      ctx.fillStyle = (i%2 ? '#ffd54f' : '#ffa726');
      ctx.arc(cx, cy, innerR, start, end);
      ctx.fill();

      // Tick
      ctx.save();
      ctx.strokeStyle = 'rgba(0,0,0,.15)';
      ctx.lineWidth = 1;
      ctx.beginPath();
      ctx.arc(cx, cy, innerR, start, start+0.001);
      ctx.stroke();
      ctx.restore();

      // Etiqueta (sólo algunas si hay muchos)
      if (i % showEvery === 0){
        ctx.save();
        ctx.translate(cx, cy);
        ctx.rotate(start + slice/2);
        ctx.textAlign = 'right';
        ctx.fillStyle = '#222';
        ctx.font = `${fontSize}px system-ui, -apple-system, Segoe UI, Roboto, Arial, sans-serif`;
        const raw = items[i].nombre || '';
        const maxChars = (total > 120) ? 12 : (total > 60 ? 16 : 22);
        const label = raw.length > maxChars ? raw.slice(0,maxChars) + '…' : raw;
        ctx.fillText(label, textR, 4);
        ctx.restore();
      }
    }

    // FLECHA GRANDE A LA IZQUIERDA
    ctx.save();
    ctx.translate(cx, cy);
    ctx.rotate(Math.PI); // izquierda
    ctx.fillStyle = '#b71c1c';
    ctx.beginPath();
    ctx.moveTo(r-18, 0);
    ctx.lineTo(r+16, -12);
    ctx.lineTo(r+16,  12);
    ctx.closePath();
    ctx.fill();
    ctx.restore();
  }

  // ----------- CARGA LISTA -----------
  function loadList(){
    const ev = $event.value;
    if (!ev){ alert('Seleccioná un evento.'); return; }
    const status = $filter.value;

    const url = new URL(listURL, window.location.origin);
    url.searchParams.set('event_code', ev);
    url.searchParams.set('page', 1);
    url.searchParams.set('per_page', 5000);
    if (status && status !== 'all') url.searchParams.set('status', status);

    $info.textContent = 'Cargando participantes…';
    fetch(url.toString())
      .then(r => r.json())
      .then(data => {
        participantes = (data.items||[]).map(it => ({ cedula: it.cedula, nombre: it.nombre }));
        $info.textContent = `Participantes cargados: ${participantes.length}`;
        if (participantes.length){
          $wrap.style.display = 'block';
          $actions.style.display = 'flex';
          angleOffset = 0;
          drawWheel(participantes);
        } else {
          $wrap.style.display = 'none';
          $actions.style.display = 'none';
        }
      })
      .catch(() => { $info.textContent = 'Error cargando lista'; });
  }

  // ----------- GIRAR 1 VEZ -----------
  function spin(){
    if (!participantes.length || spinning) return;
    spinning = true;

    const total = participantes.length;
    const slice = (2*Math.PI)/total;

    const idx = Math.floor(Math.random() * total);
    const ganador = participantes[idx];

    // Normalización y delta exacto al centro del sector
    const TAU = 2*Math.PI;
    const norm = a => { a = a % TAU; return (a < 0) ? a + TAU : a; };
    const current = norm(angleOffset);
    const targetCenter = norm(-(idx*slice + slice/2));
    let delta = targetCenter - current;
    if (delta < 0) delta += TAU;

    const turns = 3.5;
    const final = angleOffset + delta + TAU*turns;

    const start = angleOffset;
    const dur   = 1800;
    const t0    = performance.now();
    const easeOutCubic = t => 1 - Math.pow(1 - t, 3);

    function frame(now){
      const t = Math.min(1, (now - t0) / dur);
      angleOffset = start + (final - start) * easeOutCubic(t);
      drawWheel(participantes);
      if (t < 1) {
        requestAnimationFrame(frame);
      } else {
        angleOffset = final;
        drawWheel(participantes);

        // UI ganador
        $card.style.display = 'block';
        $winNm.textContent = ganador.nombre;
        $winId.textContent = `Cédula: ${ganador.cedula}`;
        $winAv.textContent = initials(ganador.nombre) || '🏆';

        // listados
        const ts = new Date().toLocaleString();
        ganadores.push({ nombre: ganador.nombre, cedula: ganador.cedula, at: ts });
        historial.unshift(ganador);
        $last.textContent = 'Últimos: ' + historial.slice(0,5).map(x=>x.nombre).join(', ');

        if ($unique.checked){
          participantes.splice(idx, 1);
        }

        // confeti + sonido
        burstConfetti(1400);
        playSound();

        spinning = false;
      }
    }
    requestAnimationFrame(frame);
  }

  // ----------- GIRAR N VECES (5) -----------
  async function spinN(n){
    for (let i=0;i<n;i++){
      if (!participantes.length) break;
      $btn1.disabled = $btn5.disabled = true;
      await new Promise(res => {
        const chk = setInterval(()=>{
          if (!spinning){
            spin();
            clearInterval(chk);
            // esperar a que termine la animación
            setTimeout(res, 1900);
          }
        }, 40);
      });
    }
    $btn1.disabled = $btn5.disabled = false;
  }

  // ----------- EXPORT GANADORES -----------
  function exportWinners(){
    if (!ganadores.length) { alert('Aún no hay ganadores.'); return; }
    downloadCSV('ganadores.csv', ganadores);
  }

  // Handlers
  $load.addEventListener('click', loadList);
  $btn1.addEventListener('click', spin);
  $btn5.addEventListener('click', ()=>spinN(5));
  $btnExp.addEventListener('click', exportWinners);

  // Autoselección si solo hay un evento
  (function autopick(){
    const sel = $event;
    const opts = sel.querySelectorAll('option');
    if (opts.length === 2 && !sel.value) { sel.value = opts[1].value; }
  })();
})();
</script>

  <?php
  return ob_get_clean();
}

}
