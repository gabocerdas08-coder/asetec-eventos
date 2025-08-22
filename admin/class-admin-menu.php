<?php
if (!defined('ABSPATH')) exit;

class Asetec_Admin_Menu {
  private $events;

  public function __construct() {
    $this->events = new Asetec_Events();
    add_action('admin_menu', [$this, 'register']);
  }

  public function register() {
    add_menu_page(
      'ASETEC Eventos', 'ASETEC Eventos',
      'manage_options', 'asetec-eventos',
      [$this, 'render'], 'dashicons-tickets', 26
    );
  }

  public function render() {
    $items = $this->events->all();
    $updated = isset($_GET['updated']); $err = isset($_GET['err']);
    ?>
    <div class="wrap">
      <h1>Eventos ASETEC</h1>
      <?php if($updated): ?><div class="notice notice-success"><p>Evento guardado.</p></div><?php endif; ?>
      <?php if($err): ?><div class="notice notice-error"><p>Faltan campos.</p></div><?php endif; ?>

      <h2>Crear evento</h2>
      <form method="post" action="<?php echo admin_url('admin-post.php'); ?>">
        <?php wp_nonce_field('asetec_event_form'); ?>
        <input type="hidden" name="action" value="asetec_save_event">
        <table class="form-table">
          <tr><th><label>Código</label></th><td><input name="code" required></td></tr>
          <tr><th><label>Nombre</label></th><td><input name="name" required></td></tr>
          <tr><th><label>Inicio</label></th><td><input type="datetime-local" name="starts_at"></td></tr>
          <tr><th><label>Fin</label></th><td><input type="datetime-local" name="ends_at"></td></tr>
          <tr><th><label>Lugar</label></th><td><input name="location"></td></tr>
          <tr><th><label>Estado</label></th><td>
            <select name="status">
              <option value="draft">Borrador</option>
              <option value="open">Abierto</option>
              <option value="locked">Bloqueado</option>
              <option value="closed">Cerrado</option>
            </select>
          </td></tr>
        </table>
        <p><button class="button button-primary">Guardar</button></p>
      </form>

      <h2 style="margin-top:30px">Listado</h2>
      <table class="widefat fixed striped">
        <thead><tr><th>ID</th><th>Código<s/th><th>Nombre</th><th>Fechas</th><th>Estado</th></tr></thead>
        <tbody>
          <?php foreach($items as $e): ?>
            <tr>
              <td><?php echo esc_html($e['id']); ?></td>
              <td><?php echo esc_html($e['code']); ?></td>
              <td><?php echo esc_html($e['name']); ?></td>
              <td><?php echo esc_html(($e['starts_at'] ?? '') . ' → ' . ($e['ends_at'] ?? '')); ?></td>
              <td><?php echo esc_html($e['status']); ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php
  }
}
