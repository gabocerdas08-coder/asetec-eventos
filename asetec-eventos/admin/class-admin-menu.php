<?php
if (!defined('ABSPATH')) exit;

class Asetec_Admin_Menu {
  private $events;

  public function __construct() {
    $this->events = new Asetec_Events();
    add_action('admin_menu', [$this, 'register']);
  }

  public function register() {
    add_menu_page('ASETEC Eventos','ASETEC Eventos','manage_options','asetec-eventos',[$this,'render_events'],'dashicons-tickets',26);
    add_submenu_page('asetec-eventos','Asociados','Asociados','manage_options','asetec-asociados',[$this,'render_members']);
    add_submenu_page('asetec-eventos','Reportes','Reportes','manage_options','asetec-reportes',[$this,'render_reports']);
  }

  /** ====== Eventos (CRUD) ====== */
  public function render_events() {
    $items = $this->events->all();

    // Manejo "editar"
    $editId = 0; $edit = null;
    if (isset($_GET['edit']) && isset($_GET['_wpnonce']) && wp_verify_nonce($_GET['_wpnonce'],'asetec_edit_event')) {
      $editId = intval($_GET['edit']);
      $edit = $editId ? $this->events->find($editId) : null;
    }
    $val = function($k) use($edit){ return $edit[$k] ?? ''; };

    $updated = isset($_GET['updated']);
    $deleted = isset($_GET['deleted']);
    $err     = isset($_GET['err']);
    $dupe    = isset($_GET['dupe']);
    ?>
    <div class="wrap">
      <h1>Eventos ASETEC</h1>
      <?php if($updated): ?><div class="notice notice-success"><p>Evento guardado.</p></div><?php endif; ?>
      <?php if($deleted): ?><div class="notice notice-success"><p>Evento eliminado.</p></div><?php endif; ?>
      <?php if($err): ?><div class="notice notice-error"><p>Faltan campos obligatorios.</p></div><?php endif; ?>
      <?php if($dupe): ?><div class="notice notice-error"><p>El código de evento ya existe.</p></div><?php endif; ?>

      <h2><?php echo $edit ? 'Editar evento' : 'Crear evento'; ?></h2>
      <form method="post" action="<?php echo admin_url('admin-post.php'); ?>">
        <?php wp_nonce_field('asetec_event_form'); ?>
        <input type="hidden" name="action" value="asetec_save_event">
        <input type="hidden" name="id" value="<?php echo esc_attr($val('id')); ?>">
        <table class="form-table">
          <tr><th><label>Código</label></th><td><input name="code" required value="<?php echo esc_attr($val('code')); ?>"></td></tr>
          <tr><th><label>Nombre</label></th><td><input name="name" required value="<?php echo esc_attr($val('name')); ?>"></td></tr>
          <tr><th><label>Inicio</label></th><td><input type="datetime-local" name="starts_at" value="<?php echo esc_attr($this->local_dt($val('starts_at'))); ?>"></td></tr>
          <tr><th><label>Fin</label></th><td><input type="datetime-local"   name="ends_at"   value="<?php echo esc_attr($this->local_dt($val('ends_at'))); ?>"></td></tr>
          <tr><th><label>Lugar</label></th><td><input name="location" value="<?php echo esc_attr($val('location')); ?>"></td></tr>
          <tr><th><label>Estado</label></th><td>
            <select name="status">
              <?php foreach(['draft'=>'Borrador','open'=>'Abierto','locked'=>'Bloqueado','closed'=>'Cerrado'] as $k=>$lbl): ?>
                <option value="<?php echo esc_attr($k); ?>" <?php selected(($val('status')?:'draft'), $k); ?>><?php echo esc_html($lbl); ?></option>
              <?php endforeach; ?>
            </select>
          </td></tr>
        </table>
        <p><button class="button button-primary"><?php echo $edit ? 'Actualizar' : 'Guardar'; ?></button></p>
      </form>

      <h2 style="margin-top:30px">Listado</h2>
      <table class="widefat fixed striped">
        <thead><tr><th>ID</th><th>Código</th><th>Nombre</th><th>Fechas</th><th>Estado</th><th>Acciones</th></tr></thead>
        <tbody>
          <?php if(!$items): ?>
            <tr><td colspan="6"><em>Sin eventos.</em></td></tr>
          <?php else: foreach($items as $e): ?>
            <tr>
              <td><?php echo esc_html($e['id']); ?></td>
              <td><?php echo esc_html($e['code']); ?></td>
              <td><?php echo esc_html($e['name']); ?></td>
              <td><?php echo esc_html(($e['starts_at'] ?? '') . ' → ' . ($e['ends_at'] ?? '')); ?></td>
              <td><?php echo esc_html($e['status']); ?></td>
              <td>
                <a class="button" href="<?php
                  echo esc_url( wp_nonce_url(
                    admin_url('admin.php?page=asetec-eventos&edit='.$e['id']),
                    'asetec_edit_event'
                  ));
                ?>">Editar</a>
                <a class="button button-link-delete" onclick="return confirm('¿Eliminar evento?');" href="<?php
                  echo esc_url( wp_nonce_url(
                    admin_url('admin-post.php?action=asetec_delete_event&id='.$e['id']),
                    'asetec_delete_event'
                  ));
                ?>">Eliminar</a>
              </td>
            </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
    <?php
  }

  private function local_dt($mysql) {
    if (!$mysql) return '';
    // devuelve formato compatible con input datetime-local (YYYY-MM-DDTHH:MM)
    $t = strtotime($mysql); if(!$t) return '';
    return date('Y-m-d\TH:i', $t);
  }

  /** ====== Asociados (CSV) ====== */
public function render_members() {
  $imported = isset($_GET['imported']) ? intval($_GET['imported']) : null;
  $purged   = isset($_GET['purged'])   ? intval($_GET['purged'])   : null;
  $total    = isset($_GET['total'])    ? intval($_GET['total'])    : null;
  $cleared  = isset($_GET['cleared'])  ? intval($_GET['cleared'])  : null;
  $upload_error = isset($_GET['upload_error']) ? sanitize_text_field($_GET['upload_error']) : '';

  ?>
  <div class="wrap">
    <h1>Asociados — Importar/Actualizar CSV</h1>

    <?php if($upload_error): ?>
      <div class="notice notice-error"><p><b>Error de carga:</b> <?php echo esc_html($upload_error); ?></p></div>
    <?php endif; ?>

    <?php if($cleared): ?>
      <div class="notice notice-success"><p>La base de asociados fue vaciada.</p></div>
    <?php endif; ?>

    <?php if($imported !== null || $purged !== null || $total !== null): ?>
      <div class="notice notice-success">
        <p>
          <?php if($imported !== null): ?>
            <b>Procesados (insertados/actualizados):</b> <?php echo esc_html($imported); ?><br>
          <?php endif; ?>
          <?php if($purged !== null): ?>
            <b>Borrados por reemplazo (purge):</b> <?php echo esc_html($purged); ?><br>
          <?php endif; ?>
          <?php if($total !== null): ?>
            <b>Total actual en la base:</b> <?php echo esc_html($total); ?>
          <?php endif; ?>
        </p>
      </div>
    <?php endif; ?>

    <p>Formato recomendado (UTF-8, separador coma o punto y coma): <code>cedula,nombre,email,activo</code>. La <b>cédula</b> es la llave única.</p>

    <h2>Opciones avanzadas</h2>
    <form method="post" action="<?php echo admin_url('admin-post.php'); ?>">
      <?php wp_nonce_field('asetec_members_clear'); ?>
      <input type="hidden" name="action" value="asetec_members_clear">
      <button class="button" onclick="return confirm('¿Seguro que quieres vaciar toda la base de asociados? Esta acción no se puede deshacer.');">
        Vaciar base de asociados
      </button>
    </form>
    <hr>

    <h2>Importar CSV</h2>
    <form method="post" action="<?php echo admin_url('admin-post.php'); ?>" enctype="multipart/form-data">
      <?php wp_nonce_field('asetec_members_csv'); ?>
      <input type="hidden" name="action" value="asetec_members_upload">
      <input type="file" name="csv" accept=".csv,.txt" required>
      <br><br>
      <label><input type="checkbox" name="purge" value="1"> Reemplazar todo (borra los asociados que no estén en este CSV)</label>
      <p><button class="button button-primary">Subir/Actualizar</button></p>
    </form>
  </div>
  <?php
}



  /** ====== Reportes ====== */
  public function render_reports() {
    global $wpdb;
    $events = $this->events->all();
    $event_id = intval($_GET['event_id'] ?? 0);

    $t = $wpdb->prefix.'asetec_tickets';
    $m = $wpdb->prefix.'asetec_members';
    ?>
    <div class="wrap">
      <h1>Reportes por evento</h1>
      <form method="get" style="margin-bottom:16px">
        <input type="hidden" name="page" value="asetec-reportes">
        <select name="event_id" required>
          <option value="">Seleccione evento…</option>
          <?php foreach($events as $ev): ?>
            <option value="<?php echo esc_attr($ev['id']); ?>" <?php selected($event_id,$ev['id']); ?>>
              <?php echo esc_html($ev['code'].' — '.$ev['name']); ?>
            </option>
          <?php endforeach; ?>
        </select>
        <button class="button">Ver</button>
        <?php if($event_id): ?>
          <a class="button button-primary" href="<?php echo esc_url( wp_nonce_url(
            admin_url('admin-post.php?action=asetec_export_csv&event_id='.$event_id),
            'asetec_export_csv'
          )); ?>">Descargar CSV</a>
        <?php endif; ?>
      </form>

      <?php if($event_id):
        $registrados = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $t WHERE event_id=%d", $event_id));
        $ingresaron  = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $t WHERE event_id=%d AND status='checked_in'", $event_id));
        $no_show     = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $t WHERE event_id=%d AND status='issued'", $event_id));
        $no_reg      = (int)$wpdb->get_var($wpdb->prepare("
          SELECT COUNT(*) FROM $m m WHERE m.activo=1 AND m.cedula NOT IN (SELECT cedula FROM $t WHERE event_id=%d)
        ", $event_id));
      ?>
      <h2>Resumen</h2>
      <ul>
        <li><b>Registrados (QR emitido):</b> <?php echo esc_html($registrados); ?></li>
        <li><b>Ingresaron (check-in):</b> <?php echo esc_html($ingresaron); ?></li>
        <li><b>No show:</b> <?php echo esc_html($no_show); ?></li>
        <li><b>No solicitaron QR:</b> <?php echo esc_html($no_reg); ?></li>
      </ul>
      <?php endif; ?>
    </div>
    <?php
  }
}

/** Export CSV handler (simple) */
add_action('admin_post_asetec_export_csv', function(){
  if (!current_user_can('manage_options')) wp_die('No autorizado');
  check_admin_referer('asetec_export_csv');
  global $wpdb; $event_id = intval($_GET['event_id'] ?? 0);
  if (!$event_id) wp_die('Evento inválido');

  $t = $wpdb->prefix.'asetec_tickets';
  $m = $wpdb->prefix.'asetec_members';

  header('Content-Type: text/csv; charset=utf-8');
  header('Content-Disposition: attachment; filename="reporte_evento_'.$event_id.'.csv"');
  $out = fopen('php://output', 'w');
  fputcsv($out, ['grupo','cedula','nombre','email','status','checked_in_at']);

  // Registrados
  $rows = $wpdb->get_results($wpdb->prepare("SELECT cedula,nombre,email,status,checked_in_at FROM $t WHERE event_id=%d", $event_id), ARRAY_A);
  foreach($rows as $r){ fputcsv($out, ['registrados',$r['cedula'],$r['nombre'],$r['email'],$r['status'],$r['checked_in_at']]); }

  // No solicitaron QR
  $rows = $wpdb->get_results($wpdb->prepare("
    SELECT m.cedula,m.nombre,m.email FROM $m m
    WHERE m.activo=1 AND m.cedula NOT IN (SELECT cedula FROM $t WHERE event_id=%d)
  ", $event_id), ARRAY_A);
  foreach($rows as $r){ fputcsv($out, ['no_solicitaron_qr',$r['cedula'],$r['nombre'],$r['email'],'','']); }

  fclose($out); exit;
}

add_action('admin_menu', function(){
  add_submenu_page(
    'asetec-eventos',
    'Reportes',
    'Reportes',
    'manage_options',
    'asetec-reportes',
    'asetec_render_reportes_page'
  );
});

function asetec_render_reportes_page(){
  if (!current_user_can('manage_options')) { wp_die('No autorizado'); }

  global $wpdb;
  $t_events  = $wpdb->prefix.'asetec_events';
  $t_members = $wpdb->prefix.'asetec_members';
  $t_tickets = $wpdb->prefix.'asetec_tickets';

  // Parámetros
  $event_code = isset($_GET['event_code']) ? sanitize_text_field($_GET['event_code']) : '';
  $q          = isset($_GET['q']) ? sanitize_text_field($_GET['q']) : '';
  $estado     = isset($_GET['estado']) ? sanitize_text_field($_GET['estado']) : 'all'; // all|no_qr|solicito|checkin
  $per_page   = max(1, intval($_GET['per_page'] ?? 50));
  $page       = max(1, intval($_GET['p'] ?? 1));
  $offset     = ($page - 1) * $per_page;

  // Eventos para el dropdown
  $events = $wpdb->get_results("SELECT id, code, name FROM $t_events ORDER BY starts_at DESC", ARRAY_A);

  // Resolver evento
  if (!$event_code && $events) $event_code = $events[0]['code'];
  $ev = $event_code ? $wpdb->get_row($wpdb->prepare("SELECT id, code, name FROM $t_events WHERE code=%s", $event_code), ARRAY_A) : null;

  // Form filtros (arriba)
  echo '<div class="wrap"><h1>Reportes</h1>';
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

  // Enlace export CSV manteniendo filtros
  $export_url = add_query_arg(array_merge($_GET, ['export'=>'1','p'=>null]));
  echo '<a class="button" href="'.esc_url($export_url).'">Exportar CSV</a>';

  echo '</form>';

  if (!$ev) {
    echo '<p><em>Selecciona un evento.</em></p></div>';
    return;
  }

  // Construir condiciones
  $conds = ["m.activo=1"];
  $params = [];

  if ($q !== '') {
    $conds[] = "(m.cedula LIKE %s OR m.nombre LIKE %s)";
    $like = '%'.$wpdb->esc_like($q).'%';
    $params[] = $like; $params[] = $like;
  }

  // Estado según join con tickets
  $estadoWhere = "";
  if ($estado === 'no_qr') {
    $estadoWhere = " AND tk.id IS NULL";
  } elseif ($estado === 'solicito') {
    $estadoWhere = " AND tk.id IS NOT NULL";
  } elseif ($estado === 'checkin') {
    $estadoWhere = " AND tk.status='checked_in'";
  }

  $where = implode(' AND ', $conds);

  // TOTAL
  $sqlTotal = "
    SELECT COUNT(*) FROM $t_members m
    LEFT JOIN $t_tickets tk ON tk.cedula = m.cedula AND tk.event_id = %d
    WHERE $where $estadoWhere
  ";
  $total = (int) call_user_func_array(
    [$wpdb,'get_var'],
    [ call_user_func_array([$wpdb,'prepare'], array_merge([$sqlTotal, $ev['id']], $params)) ]
  );

  // Export CSV (sin LIMIT)
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

  // LISTADO con límite
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
);
