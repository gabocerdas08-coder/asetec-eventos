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
  ?>
  <div class="wrap">
    <h1>Asociados — Importar/Actualizar CSV</h1>

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


    <form method="post" action="<?php echo admin_url('admin-post.php'); ?>" enctype="multipart/form-data">
      <?php wp_nonce_field('asetec_members_csv'); ?>
      <input type="hidden" name="action" value="asetec_members_upload">
      <input type="file" name="csv" accept=".csv" required>
      <br><br>
      <label>
        <input type="checkbox" name="purge" value="1">
        Reemplazar todo (borra los asociados que no estén en este CSV)
      </label>
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
});
