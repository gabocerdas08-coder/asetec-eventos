<?php
// === Submenú "Reportes" en ASETEC Eventos ===
add_action('admin_menu', function(){
  add_submenu_page(
    'asetec-eventos',            // slug del menú padre (ya lo tienes)
    'Reportes',                  // Título de la página
    'Reportes',                  // Título del menú
    'manage_options',            // Capacidad requerida
    'asetec-reportes',           // slug
    'asetec_render_reportes_page'// callback
  );
});

function asetec_render_reportes_page(){
  if (!current_user_can('manage_options')) { wp_die('No autorizado'); }

  global $wpdb;
  $t_events  = $wpdb->prefix.'asetec_events';
  $t_members = $wpdb->prefix.'asetec_members';
  $t_tickets = $wpdb->prefix.'asetec_tickets';

  // Parámetros (GET)
  $event_code = isset($_GET['event_code']) ? sanitize_text_field($_GET['event_code']) : '';
  $q          = isset($_GET['q']) ? sanitize_text_field($_GET['q']) : '';
  $estado     = isset($_GET['estado']) ? sanitize_text_field($_GET['estado']) : 'all'; // all|no_qr|solicito|checkin
  $per_page   = max(1, intval($_GET['per_page'] ?? 50));
  $page       = max(1, intval($_GET['p'] ?? 1));
  $offset     = ($page - 1) * $per_page;

  // Lista de eventos para el selector
  $events = $wpdb->get_results("SELECT id, code, name FROM $t_events ORDER BY starts_at DESC", ARRAY_A);

  // Evento seleccionado (o el primero si no se envió)
  if (!$event_code && $events) $event_code = $events[0]['code'];
  $ev = $event_code ? $wpdb->get_row($wpdb->prepare("SELECT id, code, name FROM $t_events WHERE code=%s", $event_code), ARRAY_A) : null;

  echo '<div class="wrap"><h1>Reportes</h1>';

  // Filtros
  echo '<form method="get" style="margin:12px 0;">';
  echo '<input type="hidden" name="page" value="asetec-reportes">';

  echo '<label>Evento: <select name="event_code">';
  foreach($events as $e){
    $sel = $e['code']===$event_code ? ' selected' : '';
    echo '<option value="'.esc_attr($e['code']).'"'.$sel.'>'.esc_html($e['code'].' — '.$e['name']).'</option>';
  }
  echo '</select></label> ';

  echo '<input type="text" name="q" placeholder="Buscar cédula o nombre" value="'.esc_attr($q).'" style="min-width:260px"> ';

  $opts = ['all'=>'Todos','no_qr'=>'No solicitó QR','solicito'=>'Solicitó QR','checkin'=>'Check-in'];
  echo '<select name="estado">';
  foreach($opts as $k=>$lbl){
    $sel = $estado===$k ? ' selected' : '';
    echo '<option value="'.esc_attr($k).'"'.$sel.'>'.esc_html($lbl).'</option>';
  }
  echo '</select> ';

  echo '<select name="per_page">';
  foreach([25,50,100,200] as $pp){
    $sel = $per_page==$pp ? ' selected' : '';
    echo '<option value="'.$pp.'"'.$sel.'>'.$pp.' por página</option>';
  }
  echo '</select> ';

  echo '<button class="button button-primary">Ver</button> ';

  // Enlace export CSV manteniendo filtros (sin la página p)
  $export_url = add_query_arg(array_merge($_GET, ['export'=>'1','p'=>null]));
  echo '<a class="button" href="'.esc_url($export_url).'">Exportar CSV</a>';

  echo '</form>';

  if (!$ev) {
    echo '<p><em>Selecciona un evento.</em></p></div>';
    return;
  }

  // Construcción de condiciones
  $conds = ["m.activo=1"];
  $params = [];

  if ($q !== '') {
    $conds[] = "(m.cedula LIKE %s OR m.nombre LIKE %s)";
    $like = '%'.$wpdb->esc_like($q).'%';
    $params[] = $like; $params[] = $like;
  }

  // Estado a partir del join con tickets
  $estadoWhere = "";
  if ($estado === 'no_qr') {
    $estadoWhere = " AND tk.id IS NULL";
  } elseif ($estado === 'solicito') {
    $estadoWhere = " AND tk.id IS NOT NULL";
  } elseif ($estado === 'checkin') {
    $estadoWhere = " AND tk.status='checked_in'";
  }

  $where = implode(' AND ', $conds);

  // TOTAL (para paginación)
  $sqlTotal = "
    SELECT COUNT(*) FROM $t_members m
    LEFT JOIN $t_tickets tk ON tk.cedula = m.cedula AND tk.event_id = %d
    WHERE $where $estadoWhere
  ";
  $total = (int) call_user_func_array(
    [$wpdb,'get_var'],
    [ call_user_func_array([$wpdb,'prepare'], array_merge([$sqlTotal, $ev['id']], $params)) ]
  );

  // Export CSV (todo lo filtrado, sin LIMIT)
  if (isset($_GET['export']) && $_GET['export']=='1') {
    $sqlAll = "
      SELECT m.cedula, m.nombre, tk.status AS tk_status
      FROM $t_members m
      LEFT JOIN $t_tickets tk ON tk.cedula = m.cedula AND tk.event_id = %d
      WHERE $where $estadoWhere
      ORDER BY m.nombre ASC
    ";
    $rows = call_user_func_array(
      [$wpdb,'get_results'],
      [ call_user_func_array([$wpdb,'prepare'], array_merge([$sqlAll, $ev['id']], $params)), ARRAY_A ]
    );

    nocache_headers();
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="reporte_'.$ev['code'].'.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['cedula','nombre','no_solicito_qr','solicito_qr','check_in']);
    foreach($rows as $r){
      $no_qr = is_null($r['tk_status']) ? 1 : 0;
      $so_qr = $no_qr ? 0 : 1;
      $ck_in = ($r['tk_status']==='checked_in') ? 1 : 0;
      fputcsv($out, [$r['cedula'],$r['nombre'],$no_qr,$so_qr,$ck_in]);
    }
    fclose($out);
    exit;
  }

  // LISTADO (con LIMIT/OFFSET)
  $sqlRows = "
    SELECT m.cedula, m.nombre, tk.status AS tk_status
    FROM $t_members m
    LEFT JOIN $t_tickets tk ON tk.cedula = m.cedula AND tk.event_id = %d
    WHERE $where $estadoWhere
    ORDER BY m.nombre ASC
    LIMIT %d OFFSET %d
  ";
  $rows = call_user_func_array(
    [$wpdb,'get_results'],
    [ call_user_func_array([$wpdb,'prepare'], array_merge([$sqlRows, $ev['id']], $params, [$per_page, $offset])), ARRAY_A ]
  );

  // Paginación
  $pages = max(1, ceil(max(1,$total)/$per_page));
  if ($pages > 1) {
    echo '<div class="tablenav"><div class="tablenav-pages">';
    for ($p=1; $p<=$pages; $p++) {
      $u = add_query_arg(array_merge($_GET, ['p'=>$p]));
      $cls = ($p==$page) ? ' class="current button button-secondary"' : ' class="button"';
      echo '<a'.$cls.' href="'.esc_url($u).'">'.$p.'</a> ';
    }
    echo '</div></div>';
  }

  // Tabla matriz
  $tick = function($b){ return $b ? '✓' : '–'; };

  echo '<h2>Matriz — '.esc_html($ev['code']).' / '.esc_html($ev['name']).'</h2>';
  echo '<table class="widefat fixed striped"><thead><tr>';
  echo '<th style="width:140px">Cédula</th><th>Nombre</th><th style="text-align:center">No solicitó QR</th><th style="text-align:center">Solicitó QR</th><th style="text-align:center">Check-in</th>';
  echo '</tr></thead><tbody>';

  if (!$rows) {
    echo '<tr><td colspan="5"><em>Sin datos para los filtros aplicados.</em></td></tr>';
  } else {
    foreach($rows as $r){
      $no_qr = is_null($r['tk_status']);
      $so_qr = !$no_qr;
      $ck_in = ($r['tk_status']==='checked_in');
      echo '<tr>';
      echo '<td>'.esc_html($r['cedula']).'</td>';
      echo '<td>'.esc_html($r['nombre']).'</td>';
      echo '<td style="text-align:center">'.$tick($no_qr).'</td>';
      echo '<td style="text-align:center">'.$tick($so_qr).'</td>';
      echo '<td style="text-align:center">'.$tick($ck_in).'</td>';
      echo '</tr>';
    }
  }
  echo '</tbody></table>';
  echo '</div>';
}
