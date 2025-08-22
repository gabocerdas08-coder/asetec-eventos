<?php
if (!defined('ABSPATH')) exit;

class Asetec_Events {
  private $table;

  public function __construct() {
    global $wpdb; $this->table = $wpdb->prefix . 'asetec_events';
    add_action('admin_post_asetec_save_event', [$this, 'save']);
  }

  public function all() {
    global $wpdb;
    return $wpdb->get_results("SELECT * FROM {$this->table} ORDER BY id DESC", ARRAY_A);
  }

  public function find($id) {
    global $wpdb;
    return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->table} WHERE id=%d", $id), ARRAY_A);
  }

  public function save() {
    if (!current_user_can('manage_options')) wp_die('No autorizado');
    check_admin_referer('asetec_event_form');

    $id       = isset($_POST['id']) ? intval($_POST['id']) : 0;
    $code     = sanitize_text_field($_POST['code']);
    $name     = sanitize_text_field($_POST['name']);
    $starts   = sanitize_text_field($_POST['starts_at']);
    $ends     = sanitize_text_field($_POST['ends_at']);
    $location = sanitize_text_field($_POST['location']);
    $status   = sanitize_text_field($_POST['status']);

    global $wpdb;
    $data = [
      'code'=>$code,'name'=>$name,'starts_at'=>$starts ?: null,'ends_at'=>$ends ?: null,
      'location'=>$location ?: null,'status'=>$status,'updated_at'=>current_time('mysql')
    ];
    $format = ['%s','%s','%s','%s','%s','%s','%s'];

    if ($id) {
      $wpdb->update($this->table, $data, ['id'=>$id], $format, ['%d']);
    } else {
      $data['created_at'] = current_time('mysql');
      $wpdb->insert($this->table, $data, $format);
      $id = $wpdb->insert_id;
    }
    wp_redirect(admin_url('admin.php?page=asetec-eventos&updated=1'));
    exit;
  }
}
