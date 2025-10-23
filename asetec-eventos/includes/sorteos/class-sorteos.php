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

    <!-- Controles -->
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

    <!-- Ruleta -->
    <div id="sor-wheel-wrap" style="display:none;text-align:center">
      <canvas id="ruletaCanvas" width="520" height="520" style="max-width:100%"></canvas>
      <button id="btn-sortear" class="button button-primary" style="margin-top:10px">🎡 Girar ruleta</button>
      <div id="sor-winner" style="margin-top:12px;font-weight:700;font-size:18px;color:#0a7f2e"></div>
      <div id="sor-last" style="margin-top:8px;color:#666;font-size:12px"></div>
    </div>
  </div>

  <script>
  (function(){
    const exportURL = <?php echo json_encode( esc_url_raw( rest_url('asetec/v1/board/export') ) ); ?>;
    const $event  = document.getElementById('sor-event');
    const $filter = document.getElementById('sor-filter');
    const $load   = document.getElementById('sor-load');
    const $info   = document.getElementById('sor-info');
    const $wrap   = document.getElementById('sor-wheel-wrap');
    const $winBox = document.getElementById('sor-winner');
    const $last   = document.getElementById('sor-last');
    const $unique = document.getElementById('sor-unique');

    const canvas  = document.getElementById('ruletaCanvas');
    const ctx     = canvas.getContext('2d');

    let participantes = [];     // [{cedula, nombre}]
    let historial     = [];     // ganadores en orden
    let angleOffset   = 0;      // rotación actual para animación

    // --- Parser CSV simple con soporte de comillas ---
    function parseCSVStrict(text){
      const out = [];
      const lines = text.replace(/\r/g,'').split('\n');
      if (!lines.length) return out;
      for (let li=1; li<lines.length; li++){ // saltar encabezado
        const line = lines[li];
        if (!line) continue;
        const row = [];
        let cur = '', inQ = false;
        for (let i=0;i<line.length;i++){
          const c = line[i];
          if (c === '"' && line[i+1] === '"'){ cur += '"'; i++; continue; }
          if (c === '"'){ inQ = !inQ; continue; }
          if (c === ',' && !inQ){ row.push(cur); cur=''; continue; }
          cur += c;
        }
        row.push(cur);
        if (row.length >= 2){
          out.push({ cedula: row[0], nombre: row[1] });
        }
      }
      return out;
    }

    function drawWheel(items){
      const total = items.length;
      const r = canvas.width/2;
      const cx = r, cy = r;
      ctx.clearRect(0,0,canvas.width,canvas.height);
      if (!total) return;

      // Flecha/puntero FIJO a la derecha (ángulo 0, eje +X)
      ctx.save();
      ctx.translate(cx, cy);
      ctx.fillStyle = '#b71c1c';
      ctx.beginPath();
      ctx.moveTo(r-10, 0);
      ctx.lineTo(r+12, -8);
      ctx.lineTo(r+12,  8);
      ctx.closePath();
      ctx.fill();
      ctx.restore();

      const slice = (2*Math.PI)/total;

      // ===== Etiquetado adaptativo =====
      //  - tamaño de fuente dinámico
      //  - mostramos solo una fracción de etiquetas cuando hay muchos (ticks finos para el resto)
      let fontSize = 16;
      if (total >= 20)  fontSize = 14;
      if (total >= 60)  fontSize = 12;
      if (total >= 120) fontSize = 10;

      // Mostrar ~40 etiquetas máximo repartidas uniformemente
      const showEvery = (total > 40) ? Math.ceil(total / 40) : 1;

      // Radio para el texto (lo ponemos cerca del borde para no encimar en el centro)
      const textR = r - 26;

      // Borde interior para evitar sobrepintar el centro
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

        // Tick finito en el borde para todos los sectores (ayuda a ver los cortes)
        ctx.save();
        ctx.strokeStyle = 'rgba(0,0,0,.15)';
        ctx.lineWidth = 1;
        ctx.beginPath();
        ctx.arc(cx, cy, innerR, start, start+0.001);
        ctx.stroke();
        ctx.restore();

        // Etiqueta: solo cada N sectores si hay muchos
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
          ctx.fillText(label, textR, 4); // texto hacia afuera
          ctx.restore();
        }
      }
    }



    function loadList(){
      $winBox.textContent = '';
      const ev = $event.value;
      if (!ev){ alert('Seleccioná un evento.'); return; }
      const status = $filter.value;

      const url = new URL(exportURL, window.location.origin);
      url.searchParams.set('event_code', ev);
      if (status && status !== 'all') url.searchParams.set('status', status);
      url.searchParams.set('per_page', 5000);


      $info.textContent = 'Cargando participantes…';
      fetch(url.toString())
        .then(r => r.text())
        .then(txt => {
          participantes = parseCSVStrict(txt);
          $info.textContent = `Participantes cargados: ${participantes.length}`;
          if (participantes.length){
            $wrap.style.display = 'block';
            angleOffset = 0;
            drawWheel(participantes);
          } else {
            $wrap.style.display = 'none';
          }
        })
        .catch(() => { $info.textContent = 'Error cargando lista'; });
    }

    function spin(){
      if (!participantes.length) return;

      const total = participantes.length;
      const slice = (2*Math.PI)/total;

      // Índice ganador
      const idx = Math.floor(Math.random() * total);
      const ganador = participantes[idx];

      // --- Normalizadores de ángulos para evitar acumulaciones raras ---
      const TAU = 2*Math.PI;
      const norm = a => {
        a = a % TAU;
        return (a < 0) ? a + TAU : a;
      };

      // Estado actual normalizado
      const current = norm(angleOffset);

      // Centro del sector ganador debe quedar en ángulo 0 (puntero a la derecha)
      const targetCenter = norm(-(idx*slice + slice/2));

      // Giremos SIEMPRE hacia adelante (sentido antihorario en canvas)
      let delta = targetCenter - current;
      if (delta < 0) delta += TAU;

      // Vueltas extra para animación
      const turns = 3.5;
      const final = angleOffset + delta + TAU*turns;

      // Animación
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
          angleOffset = final; // fijar exacto
          drawWheel(participantes);
          $winBox.textContent = `🎉 Ganador: ${ganador.nombre} — ${ganador.cedula}`;
          historial.unshift(ganador);
          $last.textContent = 'Últimos: ' + historial.slice(0,5).map(x=>x.nombre).join(', ');
          if ($unique.checked){
            participantes.splice(idx, 1); // evitar repetidos
          }
        }
      }
      requestAnimationFrame(frame);
    }



    $load.addEventListener('click', loadList);
    document.getElementById('btn-sortear').addEventListener('click', spin);

    // Si hay un solo evento en el select, lo seleccionamos automáticamente
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
