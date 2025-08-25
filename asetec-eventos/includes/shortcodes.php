<?php
if (!defined('ABSPATH')) exit;

/* ===========================
   Helpers seguros
   =========================== */

if (!function_exists('asetec_current_url')) {
  function asetec_current_url() {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host   = $_SERVER['HTTP_HOST'] ?? '';
    $uri    = $_SERVER['REQUEST_URI'] ?? '';
    return $scheme.'://'.$host.$uri;
  }
}

if (!function_exists('asetec_add_query')) {
  function asetec_add_query($args, $base = null) {
    $base = $base ?: asetec_current_url();
    return add_query_arg($args, $base);
  }
}

/* ===========================
   [asetec_event_list]
   =========================== */
if (!function_exists('asetec_sc_event_list')) {
  function asetec_sc_event_list() {
    global $wpdb; $table = $wpdb->prefix.'asetec_events';
    $items = $wpdb->get_results("SELECT code,name,starts_at FROM $table WHERE status='open' ORDER BY starts_at ASC", ARRAY_A);
    if (!$items) return '<p>No hay eventos abiertos.</p>';

    $h = '<ul class="asetec-events">';
    foreach($items as $e){
      $fecha = $e['starts_at'] ? esc_html(date('d/m/Y H:i', strtotime($e['starts_at']))) : '—';
      $h .= '<li><b>'.esc_html($e['name']).'</b> ('.esc_html($e['code']).') — '.$fecha.'</li>';
    }
    $h .= '</ul>';
    return $h;
  }
  add_shortcode('asetec_event_list', 'asetec_sc_event_list');
}

/* ===========================
   [asetec_status event_code="CODE"]
   =========================== */
if (!function_exists('asetec_sc_status_by_cedula')) {
  function asetec_sc_status_by_cedula($atts=[]){
    $atts = shortcode_atts(['event_code'=>'' ], $atts);
    $event_code = sanitize_text_field($atts['event_code']);
    if (!$event_code) return '<em>Falta event_code en el shortcode.</em>';

    if (isset($_POST['cedula'])) {
      global $wpdb;
      $e = $wpdb->get_row($wpdb->prepare("SELECT id FROM {$wpdb->prefix}asetec_events WHERE code=%s", $event_code), ARRAY_A);
      if (!$e) return '<p>Evento inválido.</p>';

      $ced = sanitize_text_field($_POST['cedula']);
      $t = $wpdb->prefix.'asetec_tickets';
      $row = $wpdb->get_row($wpdb->prepare(
        "SELECT status,checked_in_at FROM $t WHERE event_id=%d AND cedula=%s",
        $e['id'], $ced
      ), ARRAY_A);

      if ($row) {
        if ($row['status']==='checked_in') {
          return '<p><b>Estado:</b> Ingresó el '.esc_html($row['checked_in_at']).'.</p>';
        }
        return '<p><b>Estado:</b> Registrado (QR emitido), pendiente de ingreso.</p>';
      }
      return '<p>No aparece como registrado para este evento.</p>';
    }

    return '<form method="post" class="asetec-status-form">
      <input name="cedula" placeholder="Cédula" required>
      <button>Consultar</button>
    </form>';
  }
  add_shortcode('asetec_status', 'asetec_sc_status_by_cedula');
}

/* ===========================
   [asetec_event_roster event_code="CODE" view="resumen|registrados|ingresaron|no_show|no_reg"]
   =========================== */
if (!function_exists('asetec_sc_event_roster')) {
  function asetec_sc_event_roster($atts = []) {
    if (!is_user_logged_in() || !current_user_can('manage_options')) {
      return '<p><em>Acceso restringido.</em></p>';
    }

    global $wpdb;
    $a = shortcode_atts(['event_code'=>'', 'view'=>'resumen'], $atts);
    $event_code = sanitize_text_field($a['event_code']);
    $view = sanitize_text_field($a['view']);
    if (!$event_code) return '<p>Falta <code>event_code</code>.</p>';

    $ev = $wpdb->get_row($wpdb->prepare("SELECT id, code, name FROM {$wpdb->prefix}asetec_events WHERE code=%s", $event_code), ARRAY_A);
    if (!$ev) return '<p>Evento no encontrado.</p>';

    $t = $wpdb->prefix.'asetec_tickets';
    $m = $wpdb->prefix.'asetec_members';

    // ===== Resumen =====
    if ($view === 'resumen') {
      $registrados = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $t WHERE event_id=%d", $ev['id']));
      $ingresaron  = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $t WHERE event_id=%d AND status='checked_in'", $ev['id']));
      $no_show     = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $t WHERE event_id=%d AND status='issued'", $ev['id']));
      $no_reg      = (int)$wpdb->get_var($wpdb->prepare("
        SELECT COUNT(*) FROM $m m WHERE m.activo=1 AND m.cedula NOT IN (SELECT cedula FROM $t WHERE event_id=%d)
      ", $ev['id']));

      $base = remove_query_arg(['view','p'], asetec_current_url());
      $base = add_query_arg(['event_code'=>$event_code], $base);

      $links = [
        'registrados' => add_query_arg(['view'=>'registrados'], $base),
        'ingresaron'  => add_query_arg(['view'=>'ingresaron'],  $base),
        'no_show'     => add_query_arg(['view'=>'no_show'],     $base),
        'no_reg'      => add_query_arg(['view'=>'no_reg'],      $base),
      ];

      ob_start();
      echo '<div class="wrap">';
      echo '<h2>Resumen — '.esc_html($ev['code']).' / '.esc_html($ev['name']).'</h2>';
      echo '<ul>';
      echo '<li><b>Registrados (QR emitido):</b> '.esc_html($registrados).' — <a class="button" href="'.esc_url($links['registrados']).'">ver</a></li>';
      echo '<li><b>Ingresaron (check-in):</b> '.esc_html($ingresaron).' — <a class="button" href="'.esc_url($links['ingresaron']).'">ver</a></li>';
      echo '<li><b>No show:</b> '.esc_html($no_show).' — <a class="button" href="'.esc_url($links['no_show']).'">ver</a></li>';
      echo '<li><b>No solicitaron QR:</b> '.esc_html($no_reg).' — <a class="button" href="'.esc_url($links['no_reg']).'">ver</a></li>';
      echo '</ul>';
      echo '</div>';
      return ob_get_clean();
    }

    // ===== Listados con paginación =====
    $per_page = 50;
    $page = max(1, intval($_GET['p'] ?? 1));
    $offset = ($page - 1) * $per_page;

    $rows = []; $total = 0; $title = '';

    if ($view === 'registrados') {
      $total = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $t WHERE event_id=%d", $ev['id']));
      $rows = $wpdb->get_results($wpdb->prepare("
        SELECT tk.cedula, tk.nombre, tk.email, tk.entry_number, tk.status, tk.checked_in_at
        FROM $t tk WHERE tk.event_id=%d
        ORDER BY tk.id DESC LIMIT %d OFFSET %d
      ", $ev['id'], $per_page, $offset), ARRAY_A);
      $title = 'Registrados (QR emitido)';
    }

    if ($view === 'ingresaron') {
      $total = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $t WHERE event_id=%d AND status='checked_in'", $ev['id']));
      $rows = $wpdb->get_results($wpdb->prepare("
        SELECT tk.cedula, tk.nombre, tk.email, tk.entry_number, tk.status, tk.checked_in_at
        FROM $t tk WHERE tk.event_id=%d AND tk.status='checked_in'
        ORDER BY tk.checked_in_at DESC LIMIT %d OFFSET %d
      ", $ev['id'], $per_page, $offset), ARRAY_A);
      $title = 'Ingresaron (check-in)';
    }

    if ($view === 'no_show') {
      $total = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $t WHERE event_id=%d AND status='issued'", $ev['id']));
      $rows = $wpdb->get_results($wpdb->prepare("
        SELECT tk.cedula, tk.nombre, tk.email, tk.entry_number, tk.status, tk.checked_in_at
        FROM $t tk WHERE tk.event_id=%d AND tk.status='issued'
        ORDER BY tk.entry_number ASC LIMIT %d OFFSET %d
      ", $ev['id'], $per_page, $offset), ARRAY_A);
      $title = 'No show (con QR, sin ingreso)';
    }

    if ($view === 'no_reg') {
      $total = (int)$wpdb->get_var($wpdb->prepare("
        SELECT COUNT(*) FROM $m m
        WHERE m.activo=1 AND m.cedula NOT IN (SELECT cedula FROM $t WHERE event_id=%d)
      ", $ev['id']));
      $rows = $wpdb->get_results($wpdb->prepare("
        SELECT m.cedula, m.nombre, m.email, m.activo
        FROM $m m
        WHERE m.activo=1 AND m.cedula NOT IN (SELECT cedula FROM $t WHERE event_id=%d)
        ORDER BY m.nombre ASC LIMIT %d OFFSET %d
      ", $ev['id'], $per_page, $offset), ARRAY_A);
      $title = 'No solicitaron QR';
    }

    $base_url = add_query_arg(['event_code'=>$event_code,'view'=>$view], remove_query_arg('p', asetec_current_url()));

    ob_start();
    echo '<div class="wrap">';
    echo '<h2>'.esc_html($title).' — '.esc_html($ev['code']).'</h2>';

    $pages = max(1, ceil($total / $per_page));
    if ($pages > 1) {
      echo '<div class="tablenav"><div class="tablenav-pages">';
      for ($p=1; $p<=$pages; $p++) {
        $u = add_query_arg(['p'=>$p], $base_url);
        $cls = ($p==$page) ? ' class="current button button-secondary"' : ' class="button"';
        echo '<a'.$cls.' href="'.esc_url($u).'">'.$p.'</a> ';
      }
      echo '</div></div>';
    }

    echo '<table class="widefat fixed striped"><thead><tr>';
    echo '<th>Cédula</th><th>Nombre</th><th>Email</th><th>Ticket</th><th>Estado</th><th>Check-in</th>';
    echo '</tr></thead><tbody>';

    if (!$rows) {
      echo '<tr><td colspan="6"><em>Sin datos.</em></td></tr>';
    } else {
      foreach ($rows as $r) {
        $ticket = isset($r['entry_number']) ? ('#'.$r['entry_number']) : '-';
        $estado = isset($r['status']) ? $r['status'] : (isset($r['activo']) ? ($r['activo']?'activo':'inactivo') : '-');
        $check  = $r['checked_in_at'] ?? '-';
        echo '<tr>';
        echo '<td>'.esc_html($r['cedula']).'</td>';
        echo '<td>'.esc_html($r['nombre'] ?? '').'</td>';
        echo '<td>'.esc_html($r['email'] ?? '').'</td>';
        echo '<td>'.esc_html($ticket).'</td>';
        echo '<td>'.esc_html($estado).'</td>';
        echo '<td>'.esc_html($check).'</td>';
        echo '</tr>';
      }
    }

    echo '</tbody></table>';
    echo '</div>';
    return ob_get_clean();
  }
  add_shortcode('asetec_event_roster', 'asetec_sc_event_roster');
}

/* ===========================
   [asetec_event_matrix event_code="CODE" per_page="100"]
   - Filtros: q (texto), estado (all|no_qr|solicito|checkin)
   - Export CSV (con mismos filtros)
   =========================== */
if (!function_exists('asetec_sc_event_matrix')) {
  function asetec_sc_event_matrix($atts=[]) {
    if (!is_user_logged_in() || !current_user_can('manage_options')) {
      return '<p><em>Acceso restringido.</em></p>';
    }

    global $wpdb;
    $a = shortcode_atts(['event_code'=>'', 'per_page'=>100], $atts);
    $event_code = sanitize_text_field($a['event_code']);
    $per_page = max(1, intval($a['per_page']));
    if (!$event_code) return '<p>Falta <code>event_code</code>.</p>';

    $ev = $wpdb->get_row($wpdb->prepare("SELECT id, code, name FROM {$wpdb->prefix}asetec_events WHERE code=%s", $event_code), ARRAY_A);
    if (!$ev) return '<p>Evento no encontrado.</p>';

    $t = $wpdb->prefix.'asetec_tickets';
    $m = $wpdb->prefix.'asetec_members';

    // Filtros
    $q      = sanitize_text_field($_GET['q'] ?? '');
    $estado = sanitize_text_field($_GET['estado'] ?? 'all'); // all|no_qr|solicito|checkin
    $page   = max(1, intval($_GET['p'] ?? 1));
    $offset = ($page - 1) * $per_page;

    // Condiciones SQL
    $where = "m.activo=1";
    $args  = [];

    if ($q !== '') {
      $where .= " AND (m.cedula LIKE %s OR m.nombre LIKE %s)";
      $args[] = '%'.$wpdb->esc_like($q).'%';
      $args[] = '%'.$wpdb->esc_like($q).'%';
    }

    // Estado
    if ($estado === 'no_qr') {
      $estadoWhere = " AND tk.id IS NULL";
    } elseif ($estado === 'solicito') {
      $estadoWhere = " AND tk.id IS NOT NULL";
    } elseif ($estado === 'checkin') {
      $estadoWhere = " AND tk.status='checked_in'";
    } else {
      $estadoWhere = "";
    }

    // Total (para paginar)
    $sqlTotal = "
      SELECT COUNT(*) FROM $m m
      LEFT JOIN $t tk ON tk.cedula = m.cedula AND tk.event_id = %d
      WHERE $where $estadoWhere
    ";
    $total = (int)$wpdb->get_var($wpdb->prepare(array_unshift($args, $sqlTotal) ? $args : $sqlTotal, $ev['id']));

    // Listado
    $sqlRows = "
      SELECT m.cedula, m.nombre, tk.status AS tk_status
      FROM $m m
      LEFT JOIN $t tk
        ON tk.cedula = m.cedula AND tk.event_id = %d
      WHERE $where $estadoWhere
      ORDER BY m.nombre ASC
      LIMIT %d OFFSET %d
    ";
    $argsRows = array_merge([$sqlRows, $ev['id']], $args, [$per_page, $offset]);
    $rows = $wpdb->get_results(call_user_func_array([$wpdb,'prepare'], $argsRows), ARRAY_A);

    // Export CSV
    if (isset($_GET['export']) && $_GET['export']=='1') {
      nocache_headers();
      header('Content-Type: text/csv; charset=utf-8');
      header('Content-Disposition: attachment; filename="matriz_'.$event_code.'.csv"');
      $out = fopen('php://output', 'w');
      fputcsv($out, ['cedula','nombre','no_solicito_qr','solicito_qr','check_in']);
      // Repetimos consulta sin LIMIT para exportar todo lo filtrado
      $sqlAll = "
        SELECT m.cedula, m.nombre, tk.status AS tk_status
        FROM $m m
        LEFT JOIN $t tk ON tk.cedula=m.cedula AND tk.event_id=%d
        WHERE $where $estadoWhere
        ORDER BY m.nombre ASC
      ";
      $argsAll = array_merge([$sqlAll, $ev['id']], $args);
      $all = $wpdb->get_results(call_user_func_array([$wpdb,'prepare'], $argsAll), ARRAY_A);
      foreach ($all as $r) {
        $no_qr = is_null($r['tk_status']) ? 1 : 0;
        $so_qr = $no_qr ? 0 : 1;
        $ck_in = ($r['tk_status']==='checked_in') ? 1 : 0;
        fputcsv($out, [$r['cedula'],$r['nombre'],$no_qr,$so_qr,$ck_in]);
      }
      fclose($out); exit;
    }

    // Paginación URL base manteniendo filtros
    $base_url = remove_query_arg('p', asetec_current_url());
    $pages = max(1, ceil(max(1,$total) / $per_page));
    $check = function($cond){ return $cond ? '✓' : '–'; };

    ob_start();
    echo '<div class="wrap">';
    echo '<h2>Matriz — '.esc_html($ev['code']).' / '.esc_html($ev['name']).'</h2>';

    // Form de filtros
    echo '<form method="get" style="margin:12px 0;">';
    // conservar otros params (página de WP)
    foreach ($_GET as $k=>$v) {
      if (in_array($k, ['q','estado','p','export'])) continue;
      echo '<input type="hidden" name="'.esc_attr($k).'" value="'.esc_attr($v).'">';
    }
    echo '<input type="hidden" name="event_code" value="'.esc_attr($event_code).'">';
    echo '<input type="text" name="q" placeholder="Buscar cédula o nombre" value="'.esc_attr($q).'" style="min-width:260px;"> ';
    echo '<select name="estado">';
      $opts = ['all'=>'Todos','no_qr'=>'No solicitó QR','solicito'=>'Solicitó QR','checkin'=>'Check-in'];
      foreach ($opts as $key=>$label) {
        $sel = $estado===$key ? ' selected' : '';
        echo '<option value="'.esc_attr($key).'"'.$sel.'>'.esc_html($label).'</option>';
      }
    echo '</select> ';
    echo '<button class="button">Filtrar</button> ';
    echo '<a class="button button-secondary" href="'.esc_url(asetec_add_query(['export'=>'1'], $base_url)).'">Exportar CSV</a>';
    echo '</form>';

    // Paginación arriba
    if ($pages > 1) {
      echo '<div class="tablenav"><div class="tablenav-pages">';
      for ($p=1; $p<=$pages; $p++) {
        $u = add_query_arg(['p'=>$p], $base_url);
        $cls = ($p==$page) ? ' class="current button button-secondary"' : ' class="button"';
        echo '<a'.$cls.' href="'.esc_url($u).'">'.$p.'</a> ';
      }
      echo '</div></div>';
    }

    // Tabla
    echo '<table class="widefat fixed striped"><thead><tr>';
    echo '<th style="width:120px">Cédula</th><th>Nombre</th><th style="text-align:center">No solicitó QR</th><th style="text-align:center">Solicitó QR</th><th style="text-align:center">Check-in</th>';
    echo '</tr></thead><tbody>';

    if (!$rows) {
      echo '<tr><td colspan="5"><em>Sin datos para los filtros aplicados.</em></td></tr>';
    } else {
      foreach ($rows as $r) {
        $no_solicito = is_null($r['tk_status']);
        $solicito    = !$no_solicito;
        $checkin     = ($r['tk_status'] === 'checked_in');

        echo '<tr>';
        echo '<td>'.esc_html($r['cedula']).'</td>';
        echo '<td>'.esc_html($r['nombre']).'</td>';
        echo '<td style="text-align:center">'.$check($no_solicito).'</td>';
        echo '<td style="text-align:center">'.$check($solicito).'</td>';
        echo '<td style="text-align:center">'.$check($checkin).'</td>';
        echo '</tr>';
      }
    }

    echo '</tbody></table>';
    echo '</div>';
    return ob_get_clean();
  }
  add_shortcode('asetec_event_matrix', 'asetec_sc_event_matrix');
  <?php
if (!defined('ABSPATH')) exit;

/**
 * [asetec_event_table event_code="PA2025" per_page="50"]
 * Tabla pública: búsqueda + filtros por estado.
 * Columnas: Cédula | Nombre | Estado | Cupo
 * NOTA: Es pública; si luego querés, puedes limitar a usuarios logueados.
 */
if (!function_exists('asetec_sc_event_table')) {
  function asetec_sc_event_table($atts=[]) {
    global $wpdb;

    $a = shortcode_atts(['event_code'=>'', 'per_page'=>50], $atts);
    $event_code = sanitize_text_field($a['event_code']);
    $per_page   = max(1, intval($a['per_page']));
    if (!$event_code) return '<p><em>Falta <code>event_code</code>.</em></p>';

    // Resolver evento
    $ev = $wpdb->get_row($wpdb->prepare(
      "SELECT id, code, name FROM {$wpdb->prefix}asetec_events WHERE code=%s", $event_code
    ), ARRAY_A);
    if (!$ev) return '<p><em>Evento no encontrado.</em></p>';

    $t = $wpdb->prefix.'asetec_tickets';
    $m = $wpdb->prefix.'asetec_members';

    // Filtros desde GET (para no crear sesiones)
    $q      = isset($_GET['q']) ? sanitize_text_field($_GET['q']) : '';
    $estado = isset($_GET['estado']) ? sanitize_text_field($_GET['estado']) : 'all'; // all|no_qr|solicito|checkin
    $page   = max(1, intval($_GET['p'] ?? 1));
    $offset = ($page - 1) * $per_page;

    // Construcción WHERE con placeholders seguros
    $conds = ["m.activo=1"];
    $params = [];

    if ($q !== '') {
      $conds[] = "(m.cedula LIKE %s OR m.nombre LIKE %s)";
      $like = '%'.$wpdb->esc_like($q).'%';
      $params[] = $like; $params[] = $like;
    }

    // Estado vía LEFT JOIN a tickets del evento
    $estadoWhere = "";
    if ($estado === 'no_qr') {
      $estadoWhere = " AND tk.id IS NULL";
    } elseif ($estado === 'solicito') {
      $estadoWhere = " AND tk.id IS NOT NULL";
    } elseif ($estado === 'checkin') {
      $estadoWhere = " AND tk.status='checked_in'";
    }
    $where = implode(' AND ', $conds);

    // TOTAL para paginar
    $sqlTotal = "
      SELECT COUNT(*) FROM $m m
      LEFT JOIN $t tk ON tk.cedula=m.cedula AND tk.event_id=%d
      WHERE $where $estadoWhere
    ";
    $total = (int) call_user_func_array(
      [$wpdb,'get_var'],
      [ call_user_func_array([$wpdb,'prepare'], array_merge([$sqlTotal, $ev['id']], $params)) ]
    );

    // Filas (con LIMIT)
    $sqlRows = "
      SELECT m.cedula, m.nombre, tk.status AS tk_status, COALESCE(tk.party_size,1) AS party_size
      FROM $m m
      LEFT JOIN $t tk ON tk.cedula=m.cedula AND tk.event_id=%d
      WHERE $where $estadoWhere
      ORDER BY m.nombre ASC
      LIMIT %d OFFSET %d
    ";
    $rows = call_user_func_array(
      [$wpdb,'get_results'],
      [ call_user_func_array([$wpdb,'prepare'], array_merge([$sqlRows, $ev['id']], $params, [$per_page, $offset])), ARRAY_A ]
    );

    // Render
    $current_url = (function(){
      $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
      $host   = $_SERVER['HTTP_HOST'] ?? '';
      $uri    = $_SERVER['REQUEST_URI'] ?? '';
      return $scheme.'://'.$host.$uri;
    })();

    // Base sin p
    $base_url = remove_query_arg('p', $current_url);

    $pages = max(1, ceil(max(1,$total)/$per_page));

    ob_start();
    echo '<div class="asetec-table">';
    echo '<h3>'.esc_html($ev['code'].' — '.$ev['name']).'</h3>';

    // Form filtros
    echo '<form method="get" class="asetec-filter" style="margin:12px 0;">';
    // conservar otros GET (por si la página tiene más)
    foreach ($_GET as $k=>$v) {
      if (in_array($k, ['q','estado','p'])) continue;
      echo '<input type="hidden" name="'.esc_attr($k).'" value="'.esc_attr($v).'">';
    }
    echo '<input type="hidden" name="event_code" value="'.esc_attr($event_code).'">';
    echo '<input type="text" name="q" placeholder="Buscar cédula o nombre" value="'.esc_attr($q).'" style="min-width:260px;"> ';
    echo '<select name="estado">';
      $opts = ['all'=>'Todos','no_qr'=>'No solicitó QR','solicito'=>'Solicitó QR','checkin'=>'Check-in'];
      foreach ($opts as $key=>$label) {
        $sel = $estado===$key ? ' selected' : '';
        echo '<option value="'.esc_attr($key).'"'.$sel.'>'.esc_html($label).'</option>';
      }
    echo '</select> ';
    echo '<button>Filtrar</button>';
    echo '</form>';

    // Tabla
    echo '<table class="widefat fixed striped"><thead><tr>';
    echo '<th style="width:140px">Cédula</th><th>Nombre</th><th>Estado</th><th style="width:80px;text-align:center">Cupo</th>';
    echo '</tr></thead><tbody>';

    if (!$rows) {
      echo '<tr><td colspan="4"><em>Sin datos para los filtros aplicados.</em></td></tr>';
    } else {
      foreach ($rows as $r) {
        $estado_txt = '—';
        if ($r['tk_status'] === null) $estado_txt = 'No solicitó QR';
        elseif ($r['tk_status'] === 'issued') $estado_txt = 'Solicitó QR';
        elseif ($r['tk_status'] === 'checked_in') $estado_txt = 'Check-in';

        echo '<tr>';
        echo '<td>'.esc_html($r['cedula']).'</td>';
        echo '<td>'.esc_html($r['nombre']).'</td>';
        echo '<td>'.esc_html($estado_txt).'</td>';
        echo '<td style="text-align:center">'.intval($r['party_size']).'</td>';
        echo '</tr>';
      }
    }
    echo '</tbody></table>';

    // Paginación
    if ($pages > 1) {
      echo '<div class="tablenav"><div class="tablenav-pages">';
      for ($p=1; $p<=$pages; $p++) {
        $u = add_query_arg(['p'=>$p], $base_url);
        $cls = ($p==$page) ? ' class="current button button-secondary"' : ' class="button"';
        echo '<a'.$cls.' href="'.esc_url($u).'">'.$p.'</a> ';
      }
      echo '</div></div>';
    }

    echo '</div>';
    return ob_get_clean();
  }
  add_shortcode('asetec_event_table', 'asetec_sc_event_table');
}

}
