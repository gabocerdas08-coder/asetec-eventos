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
            tr.innerHTML = `
              <td>${it.cedula}</td>
              <td>${it.nombre}</td>
              <td style="text-align:center">${mark(it.no_qr)}</td>
              <td style="text-align:center">${mark(it.solicito_qr)}</td>
              <td style="text-align:center">${mark(it.check_in)}</td>
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
