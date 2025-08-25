<?php
if (!defined('ABSPATH')) exit;

/**
 * [asetec_event_list]
 * Lista de eventos abiertos (vista de usuario)
 */
function asetec_sc_event_list() {
  global $wpdb; $table = $wpdb->prefix.'asetec_events';
  $items = $wpdb->get_results("SELECT code,name,starts_at FROM $table WHERE status='open' ORDER BY starts_at ASC", ARRAY_A);
  if (!$items) return '<p>No hay eventos abiertos.</p>';

  $html = '<ul class="asetec-events">';
  foreach($items as $e){
    $fecha = $e['starts_at'] ? esc_html(date('d/m/Y H:i', strtotime($e['starts_at']))) : '—';
    $html .= '<li><b>'.esc_html($e['name']).'</b> ('.esc_html($e['code']).') — '.$fecha.'</li>';
  }
  $html .= '</ul>';
  return $html;
}
add_shortcode('asetec_event_list', 'asetec_sc_event_list');


/**
 * [asetec_status event_code="ASAMBLEA-2025"]
 * Consulta del estado por cédula (registrado / ingresó / no aparece)
 */
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

  // Form
  return '<form method="post" class="asetec-status-form">
    <input name="cedula" placeholder="Cédula" required>
    <button>Consultar</button>
  </form>';
}
add_shortcode('asetec_status', 'asetec_sc_status_by_cedula');


/* ====== Helpers para el roster ====== */
function asetec_paginate($total, $per_page, $page, $base_url) {
  $pages = max(1, ceil($total / $per_page));
  if ($pages <= 1) return '';
  $html = '<div class="tablenav"><div class="tablenav-pages">';
  for ($p = 1; $p <= $pages; $p++) {
    $url = add_query_arg(['p'=>$p], $base_url);
    $class = $p == $page ? ' class="current button button-secondary"' : ' class="button"';
    $html .= '<a'.$class.' href="'.esc_url($url).'">'.$p.'</a> ';
  }
  $html .= '</div></div>';
  return $html;
}

/**
 * [asetec_event_roster event_code="ASAMBLEA-2025" view="resumen|registrados|ingresaron|no_show|no_reg"]
 * Vista de tablero interna (solo staff con manage_options)
 */
/**
 * [asetec_event_roster event_code="ASAMBLEA-2025" view="resumen|registrados|ingresaron|no_show|no_reg"]
 */
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

    // Base: URL actual sin p ni view, siempre preservando event_code
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

  // Paginación: mantener event_code y view
  $base_url = add_query_arg(['event_code'=>$event_code,'view'=>$view], remove_query_arg('p', asetec_current_url()));

  // Pintar
  ob_start();
  echo '<div class="wrap">';
  echo '<h2>'.esc_html($title).' — '.esc_html($ev['code']).'</h2>';

  // paginador simple
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
/**
 * [asetec_event_matrix event_code="PA2025" per_page="100"]
 * Tabla estilo checklist: Cedula | Nombre | No solicitó QR | Solicitó QR | Check-in
 * Visible solo para usuarios con manage_options (staff).
 */
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

  // Paginación
  $page = max(1, intval($_GET['p'] ?? 1));
  $offset = ($page - 1) * $per_page;

  // Total = activos (los que consideramos para la matriz)
  $total = (int)$wpdb->get_var("SELECT COUNT(*) FROM $m WHERE activo=1");

  // Traer miembros activos + su ticket (si existe) para este evento
  $rows = $wpdb->get_results($wpdb->prepare("
    SELECT m.cedula, m.nombre, 
           tk.status AS tk_status
    FROM $m m
    LEFT JOIN $t tk
      ON tk.cedula = m.cedula AND tk.event_id = %d
    WHERE m.activo = 1
    ORDER BY m.nombre ASC
    LIMIT %d OFFSET %d
  ", $ev['id'], $per_page, $offset), ARRAY_A);

  // Armar tabla
  $check = function($cond){ return $cond ? '✓' : '–'; };

  // Paginación URL
  $base_url = add_query_arg(['event_code'=>$event_code], remove_query_arg('p', asetec_current_url()));
  $pages = max(1, ceil($total / $per_page));

  ob_start();
  echo '<div class="wrap">';
  echo '<h2>Matriz — '.esc_html($ev['code']).' / '.esc_html($ev['name']).'</h2>';

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
  echo '<th>Cédula</th><th>Nombre</th><th>No solicitó QR</th><th>Solicitó QR</th><th>Check-in</th>';
  echo '</tr></thead><tbody>';

  if (!$rows) {
    echo '<tr><td colspan="5"><em>Sin datos.</em></td></tr>';
  } else {
    foreach ($rows as $r) {
      $no_solicito = is_null($r['tk_status']);                 // no hay ticket
      $solicito    = !$no_solicito;                             // hay ticket
      $checkin     = ($r['tk_status'] === 'checked_in');        // ticket con check-in

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
