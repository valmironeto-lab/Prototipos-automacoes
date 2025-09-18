<?php
/**
 * Automations List Table Class.
 *
 * @package BlueSendMail
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

class BlueSendMail_Automations_List_Table extends WP_List_Table {

	public function __construct() {
		parent::__construct(
			array(
				'singular' => __( 'Automação', 'bluesendmail' ),
				'plural'   => __( 'Automações', 'bluesendmail' ),
				'ajax'     => false,
			)
		);
	}

	public function get_columns() {
		return array(
			'name'       => __( 'Nome', 'bluesendmail' ),
			'trigger'    => __( 'Gatilho', 'bluesendmail' ),
			'action'     => __( 'Passos', 'bluesendmail' ),
			'status'     => __( 'Status', 'bluesendmail' ),
			'created_at' => __( 'Data de Criação', 'bluesendmail' ),
		);
	}

	/**
	 * Prepara os itens para a tabela, adicionando a contagem de passos e o nome do gatilho.
	 */
	public function prepare_items() {
		global $wpdb;
		$table_automations = $wpdb->prefix . 'bluesendmail_automations';
		
		$per_page     = 20;
		$current_page = $this->get_pagenum();
		$offset       = ( $current_page - 1 ) * $per_page;

		$sql = $wpdb->prepare(
			"SELECT a.* FROM {$table_automations} AS a
			 ORDER BY a.created_at DESC LIMIT %d OFFSET %d",
			$per_page,
			$offset
		);
		
		$this->items = $wpdb->get_results( $sql, ARRAY_A );

		// Adiciona dados extras (contagem de passos, nome do gatilho) após a consulta principal
		$automation_ids = wp_list_pluck($this->items, 'automation_id');
		if (!empty($automation_ids)) {
			// Obtém a contagem de passos para cada automação
			$steps_counts_query = "SELECT automation_id, COUNT(step_id) as count FROM {$wpdb->prefix}bluesendmail_automation_steps WHERE automation_id IN (" . implode(',', $automation_ids) . ") GROUP BY automation_id";
			$steps_counts = $wpdb->get_results($steps_counts_query, OBJECT_K);

			// Obtém todas as listas de uma vez para eficiência
			$lists = $wpdb->get_results("SELECT list_id, name FROM {$wpdb->prefix}bluesendmail_lists", OBJECT_K);

			foreach($this->items as &$item) { // Usa referência (&) para modificar o array diretamente
				// Adiciona a contagem de passos
				$item['steps_count'] = isset($steps_counts[$item['automation_id']]) ? $steps_counts[$item['automation_id']]->count : 0;
				
				// Adiciona o nome do gatilho
				$trigger_settings = maybe_unserialize($item['trigger_settings']);
				if (is_array($trigger_settings) && !empty($trigger_settings['list_id'])) {
					$list_id = $trigger_settings['list_id'];
					$item['trigger_name'] = isset($lists[$list_id]) ? $lists[$list_id]->name : __('Lista Apagada', 'bluesendmail');
				} else {
					$item['trigger_name'] = __('Não configurado', 'bluesendmail');
				}
			}
			unset($item); // Quebra a referência
		}

		$total_items = $wpdb->get_var( "SELECT COUNT(automation_id) FROM $table_automations" );

		$this->set_pagination_args(
			array(
				'total_items' => $total_items,
				'per_page'    => $per_page,
			)
		);
		$this->_column_headers = array( $this->get_columns(), array(), array() );
	}

	protected function column_default( $item, $column_name ) {
		return $item[ $column_name ] ? esc_html( $item[ $column_name ] ) : '';
	}
    
	protected function column_name( $item ) {
		$edit_url     = admin_url( 'admin.php?page=bluesendmail-automations&action=edit&automation=' . $item['automation_id'] );
		$delete_nonce = wp_create_nonce( 'bsm_delete_automation_' . $item['automation_id'] );
		$delete_url   = admin_url( 'admin.php?page=bluesendmail-automations&action=delete&automation=' . $item['automation_id'] . '&_wpnonce=' . $delete_nonce );
		
		$actions = array(
			'edit'   => sprintf( '<a href="%s">%s</a>', esc_url( $edit_url ), __( 'Editar', 'bluesendmail' ) ),
			'delete' => sprintf( '<a href="%s" style="color:red;" onclick="return confirm(\'Tem certeza que deseja excluir esta automação?\');">%s</a>', esc_url( $delete_url ), __( 'Excluir', 'bluesendmail' ) ),
		);

		return sprintf( '<strong><a href="%1$s">%2$s</a></strong> %3$s', esc_url( $edit_url ), esc_html( $item['name'] ), $this->row_actions( $actions ) );
	}

	protected function column_status( $item ) {
		$status = $item['status'];
		$color  = 'inactive' === $status ? '#888' : 'green';
		$text   = 'active' === $status ? __( 'Ativo', 'bluesendmail' ) : __( 'Inativo', 'bluesendmail' );
		return '<strong style="color:' . esc_attr( $color ) . ';">' . esc_html( $text ) . '</strong>';
	}

	protected function column_trigger( $item ) {
		return sprintf( __( 'Contato adicionado à lista: %s', 'bluesendmail' ), '<strong>' . esc_html( $item['trigger_name'] ) . '</strong>' );
	}

	protected function column_action( $item ) {
		return sprintf( __( '%d passo(s)', 'bluesendmail' ), $item['steps_count'] ?? 0 );
	}

	public function no_items() {
		_e( 'Nenhuma automação encontrada. Crie uma para começar!', 'bluesendmail' );
	}
}

