<?php
/**
 * Gerencia a renderização da página de Formulários.
 *
 * @package BlueSendMail
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BSM_Forms_Page extends BSM_Admin_Page {

    public function __construct( $plugin ) {
        parent::__construct( $plugin );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_color_picker_assets' ) );
    }

    public function enqueue_color_picker_assets( $hook ) {
        $screen = get_current_screen();
        if ( $screen && $screen->id === 'bluesendmail_page_bluesendmail-forms' && ( isset( $_GET['action'] ) && in_array( $_GET['action'], array( 'new', 'edit' ) ) ) ) {
            wp_enqueue_style( 'wp-color-picker' );
            wp_enqueue_script( 'bsm-form-editor', BLUESENDMAIL_PLUGIN_URL . 'assets/js/form-editor.js', array( 'wp-color-picker' ), BLUESENDMAIL_VERSION, true );
        }
    }

	public function render() {
		echo '<div class="wrap bsm-wrap">';
		$action = isset( $_GET['action'] ) ? sanitize_key( $_GET['action'] ) : '';

		if ( 'new' === $action || 'edit' === $action ) {
			$this->render_add_edit_page();
		} else {
			$this->render_list_page();
		}
		echo '</div>';
	}

	private function render_list_page() {
		$forms_table = new BlueSendMail_Forms_List_Table();
        $this->render_header(
            __( 'Formulários', 'bluesendmail' ),
            array(
                'url'   => admin_url( 'admin.php?page=bluesendmail-forms&action=new' ),
                'label' => __( 'Adicionar Novo', 'bluesendmail' ),
                'icon'  => 'dashicons-plus',
            )
        );
		?>
		<form method="post">
            <?php
            wp_nonce_field( 'bsm_bulk_action_nonce', 'bsm_bulk_nonce_field' );
            $forms_table->prepare_items();
            $forms_table->display();
            ?>
        </form>
		<?php
	}

	private function render_add_edit_page() {
		global $wpdb;
		$form_id = isset( $_GET['form'] ) ? absint( $_GET['form'] ) : 0;
		$form = $form_id ? $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}bluesendmail_forms WHERE form_id = %d", $form_id ) ) : null;
        $all_lists = $wpdb->get_results( "SELECT list_id, name FROM {$wpdb->prefix}bluesendmail_lists ORDER BY name ASC" );

        $enabled_fields = $form ? maybe_unserialize( $form->fields ) : array();
        if ( ! is_array( $enabled_fields ) ) $enabled_fields = array();

        $available_fields = array(
            'first_name' => __( 'Primeiro Nome', 'bluesendmail' ),
            'last_name'  => __( 'Sobrenome', 'bluesendmail' ),
            'phone'      => __( 'Telefone/WhatsApp', 'bluesendmail' ),
            'company'    => __( 'Empresa', 'bluesendmail' ),
            'job_title'  => __( 'Cargo', 'bluesendmail' ),
            'segment'    => __( 'Segmento', 'bluesendmail' ),
        );

        $this->render_header( $form ? __( 'Editar Formulário', 'bluesendmail' ) : __( 'Adicionar Novo Formulário', 'bluesendmail' ) );
		?>
		<div class="bsm-card">
			<form method="post">
				<table class="form-table" role="presentation">
					<tbody>
						<tr class="form-field form-required"><th scope="row"><label for="title"><?php _e( 'Título do Formulário', 'bluesendmail' ); ?></label></th><td><input name="title" type="text" id="title" value="<?php echo esc_attr( $form->title ?? '' ); ?>" class="regular-text" required></td></tr>
                        <tr class="form-field form-required"><th scope="row"><label for="list_id"><?php _e( 'Associar à Lista', 'bluesendmail' ); ?></label></th><td><select name="list_id" id="list_id" required><option value=""><?php _e( 'Selecione uma lista', 'bluesendmail' ); ?></option><?php foreach ( $all_lists as $list ) : ?><option value="<?php echo esc_attr( $list->list_id ); ?>" <?php selected( $form->list_id ?? '', $list->list_id ); ?>><?php echo esc_html( $list->name ); ?></option><?php endforeach; ?></select></td></tr>
                        
                        <tr class="form-field"><th scope="row"><?php _e( 'Campos do Formulário', 'bluesendmail' ); ?></th>
                            <td>
                                <fieldset>
                                    <legend class="screen-reader-text"><span><?php _e( 'Campos a exibir', 'bluesendmail' ); ?></span></legend>
                                    <p class="description"><?php _e( 'Selecione os campos que deseja solicitar ao usuário. O campo de E-mail é sempre obrigatório.', 'bluesendmail' ); ?></p>
                                    <?php foreach ( $available_fields as $field_key => $field_label ) : ?>
                                        <label for="field-<?php echo esc_attr( $field_key ); ?>">
                                            <input type="checkbox" name="bsm_form_fields[]" id="field-<?php echo esc_attr( $field_key ); ?>" value="<?php echo esc_attr( $field_key ); ?>" <?php checked( in_array( $field_key, $enabled_fields, true ) ); ?>>
                                            <?php echo esc_html( $field_label ); ?>
                                        </label><br>
                                    <?php endforeach; ?>
                                </fieldset>
                            </td>
                        </tr>

                        <tr class="form-field"><th scope="row"><label for="content"><?php _e( 'Texto Acima do Formulário', 'bluesendmail' ); ?></label></th><td><textarea name="content" id="content" rows="4" class="large-text"><?php echo esc_textarea( $form->content ?? __( 'Inscreva-se na nossa newsletter para receber novidades!', 'bluesendmail' ) ); ?></textarea></td></tr>
                        <tr class="form-field"><th scope="row"><label for="button_text"><?php _e( 'Texto do Botão', 'bluesendmail' ); ?></label></th><td><input name="button_text" type="text" id="button_text" value="<?php echo esc_attr( $form->button_text ?? __( 'Inscrever-se', 'bluesendmail' ) ); ?>" class="regular-text"></td></tr>
                        
                        <!-- ALTERAÇÃO: Novo campo para a cor do botão -->
                        <tr class="form-field"><th scope="row"><label for="button_color"><?php _e( 'Cor do Botão', 'bluesendmail' ); ?></label></th><td><input name="button_color" type="text" id="button_color" value="<?php echo esc_attr( $form->button_color ?? '#0073aa' ); ?>" class="bsm-color-picker"></td></tr>
                        
                        <tr class="form-field"><th scope="row"><label for="success_message"><?php _e( 'Mensagem de Sucesso', 'bluesendmail' ); ?></label></th><td><input name="success_message" type="text" id="success_message" value="<?php echo esc_attr( $form->success_message ?? __( 'Inscrição realizada com sucesso. Obrigado!', 'bluesendmail' ) ); ?>" class="large-text"></td></tr>
                        <tr class="form-field"><th scope="row"><label for="error_message"><?php _e( 'Mensagem de Erro', 'bluesendmail' ); ?></label></th><td><input name="error_message" type="text" id="error_message" value="<?php echo esc_attr( $form->error_message ?? __( 'Ocorreu um erro. Por favor, tente novamente.', 'bluesendmail' ) ); ?>" class="large-text"></td></tr>
                    </tbody>
				</table>
				<input type="hidden" name="form_id" value="<?php echo esc_attr( $form_id ); ?>" />
				<?php wp_nonce_field( 'bsm_save_form_nonce_action', 'bsm_save_form_nonce_field' ); ?>
				<?php submit_button( $form ? __( 'Salvar Alterações', 'bluesendmail' ) : __( 'Criar Formulário', 'bluesendmail' ), 'primary', 'bsm_save_form' ); ?>
			</form>
		</div>
		<?php
	}
}

