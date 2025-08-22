<?php
if (!defined('ABSPATH')) exit;

class Asetec_Events {
  private $table;

  public function __construct() {
    global $wpdb; $this->table = $wpdb->prefix . 'asetec_events';
    add_action('admin_post_asetec_save_event',   [$this, 'save']);
    add_action('admin_post_asetec_delete_event', [$this, 'delete']);
  }

  /** Listado de eventos */
  public function all() {
    global $wpdb;
    return $wpdb->get_results("SELECT * FROM {$this->table} ORDER BY id DESC", ARRAY_A);
  }

  /** Buscar evento */
  public function find($id) {
    global $wpdb;
    return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->table} WHERE id=%d", $id), ARRAY_A);
  }

  /** Guardar/actualizar */
  public function save() {
    if (!current_user_can('manage_options')) wp_die('No autorizado');
    check_admin_referer('asetec_event_form');

    $id       = intval($_POST['id'] ?? 0);
    $code     = sanitize_text_field($_POST['code'] ?? '');
    $name     = sanitize_text_field($_POST['name'] ?? '');
    $starts   = $this->parse_dt($_POST['starts_at'] ?? '');
    $ends     = $this->parse_dt($_POST['ends_at'] ?? '');
    $location = sanitize_text_field($_POST['location'] ?? '');
    $status   = sanitize_text_field($_POST['status'] ?? 'draft');

    if (!$code || !$name) {
      wp_redirect(admin_url('admin.php?page=asetec-eventos&err=1')); exit;
    }

    global $wpdb;

    // Validar unicidad de code
    $exists = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$this->table} WHERE code=%s", $code));
    if ($exists && (!$id || intval($exists) !== $id)) {
      wp_redirect(admin_url('admin.php?page=asetec-eventos&dupe=1')); exit;
    }

    $data = [
      'code'=>$code,'name'=>$name,
      'starts_at'=>$starts,'ends_at'=>$ends,
      'location'=>$location ?: null,'status'=>$status,
      'updated_at'=>current_time('mysql')
    ];
    $fmt = ['%s','%s','%s','%s','%s','%s','%s'];

    if ($id) {
      $wpdb->update($this->table, $data, ['id'=>$id], $fmt, ['%d']);
    } else {
      $data['created_at'] = current_time('mysql');
      $wpdb->insert($this->table, $data, $fmt);
      $id = $wpdb->insert_id;
    }

    wp_redirect(admin_url('admin.php?page=asetec-eventos&updated=1')); exit;
  }

  /** Eliminar evento (no toca tickets por auditoría) */
  public function delete() {
    if (!current_user_can('manage_options')) wp_die('No autorizado');
    check_admin_referer('asetec_delete_event');

    $id = intval($_GET['id'] ?? 0);
    if ($id) {
      global $wpdb; $wpdb->delete($this->table, ['id'=>$id], ['%d']);
    }
    wp_redirect(admin_url('admin.php?page=asetec-eventos&deleted=1')); exit;
  }

  /** Convertir datetime-local a MySQL DATETIME */
  private function parse_dt($val) {
    $val = trim((string)$val);
    if ($val === '') return null;
    // soporta "YYYY-MM-DDTHH:MM"
    $val = str_replace('T',' ',$val);
    return preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/',$val) ? ($val.':00') : $val;
  }
}
