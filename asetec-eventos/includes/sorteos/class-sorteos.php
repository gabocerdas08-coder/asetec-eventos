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
      <canvas id="ruletaCanvas" width="440" height="440" style="max-width:100%"></canvas><br>
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

      // Flecha/puntero a la DERECHA (eje +X)
      ctx.save();
      ctx.translate(cx, cy);
      ctx.fillStyle = '#b71c1c';
      ctx.beginPath();
      ctx.moveTo(r-8, 0);
      ctx.lineTo(r+12, -9);
      ctx.lineTo(r+12,  9);
      ctx.closePath();
      ctx.fill();
      ctx.restore();

      const slice = (2*Math.PI)/total;

      // Tamaño de fuente dinámico
      // 10–16 px, y ocultar algunos labels cuando hay demasiados
      let fontSize = Math.max(10, Math.min(16, Math.floor(280/total)));
      const showEvery = (total > 60) ? Math.ceil(total/40) : 1; // muestra ~40 etiquetas máx.

      for (let i=0;i<total;i++){
        const start = angleOffset + i*slice;
        const end   = start + slice;

        // sector
        ctx.beginPath();
        ctx.moveTo(cx, cy);
        ctx.fillStyle = (i%2 ? '#ffd54f' : '#ffa726');
        ctx.arc(cx, cy, r-16, start, end);
        ctx.fill();

        // etiqueta (saltamos algunas si hay demasiadas)
        if (i % showEvery === 0){
          ctx.save();
          ctx.translate(cx, cy);
          ctx.rotate(start + slice/2);
          ctx.textAlign = 'right';
          ctx.fillStyle = '#111';
          ctx.font = `${fontSize}px system-ui, sans-serif`;
          const raw = items[i].nombre || '';
          const maxChars = (total > 60) ? 14 : 22;
          const label = raw.length > maxChars ? raw.slice(0,maxChars) + '…' : raw;
          ctx.fillText(label, r-30, 4);
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

      // Queremos que el centro del segmento ganador quede en el ángulo 0 (puntero a la derecha)
      const base = -(idx * slice + slice/2);    // orientación final deseada
      const turns = 3.5;                         // vueltas para animación
      const final = base + 2*Math.PI*turns;      // destino total

      const start = angleOffset;
      const delta = final - start;
      const dur   = 1800; // ms
      const t0    = performance.now();

      function easeOutCubic(t){ return 1 - Math.pow(1 - t, 3); }

      const ganador = participantes[idx];

      function frame(now){
        const t = Math.min(1, (now - t0) / dur);
        angleOffset = start + delta * easeOutCubic(t);
        drawWheel(participantes);
        if (t < 1) {
          requestAnimationFrame(frame);
        } else {
          angleOffset = start + delta; // fijar
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
