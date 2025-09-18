<?php
/**
 * Gerencia a renderização da página de Automações.
 *
 * @package BlueSendMail
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BSM_Automations_Page extends BSM_Admin_Page {

	public function render() {
		$action = isset( $_GET['action'] ) ? sanitize_key( $_GET['action'] ) : '';

		if ( 'new' === $action || ( 'edit' === $action && ! empty( $_GET['automation'] ) ) ) {
			$this->render_add_edit_page();
		} else {
			$this->render_list_page();
		}
	}

	private function render_list_page() {
		?>
        <div class="wrap bsm-wrap">
            <?php
            $this->render_header(
                __( 'Automações', 'bluesendmail' ),
                array(
                    'url'   => admin_url( 'admin.php?page=bluesendmail-automations&action=new' ),
                    'label' => __( 'Criar Nova Automação', 'bluesendmail' ),
                    'icon'  => 'dashicons-plus',
                )
            );
            ?>
            <form method="post">
                <?php
                $automations_table = new BlueSendMail_Automations_List_Table();
                $automations_table->prepare_items();
                $automations_table->display();
                ?>
            </form>
        </div>
        <?php
	}

	private function render_add_edit_page() {
		global $wpdb;
		$automation_id = isset( $_GET['automation'] ) ? absint( $_GET['automation'] ) : 0;
		$automation = null;
		$trigger_settings = array();
        $steps = array();
		
		if ( $automation_id ) {
			$automation = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}bluesendmail_automations WHERE automation_id = %d", $automation_id ) );
			if( $automation ) {
				$trigger_settings = maybe_unserialize( $automation->trigger_settings );
			}
            
            // <<< CORREÇÃO: Desserializa as configurações dos passos antes de passar para o JavaScript. >>>
            $raw_steps = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}bluesendmail_automation_steps WHERE automation_id = %d ORDER BY step_order ASC", $automation_id ) );
            foreach ($raw_steps as $step) {
                $step->step_settings = maybe_unserialize($step->step_settings);
                $steps[] = $step;
            }
		}

        $lists = $wpdb->get_results("SELECT list_id, name FROM {$wpdb->prefix}bluesendmail_lists ORDER BY name ASC");
        $campaigns = $wpdb->get_results("SELECT campaign_id, title FROM {$wpdb->prefix}bluesendmail_campaigns WHERE status IN ('sent', 'draft') ORDER BY title ASC");
		
		$js_data = array(
			'steps'      => $steps,
			'campaigns'  => array_map(function($c) { return ['id' => $c->campaign_id, 'title' => $c->title]; }, $campaigns),
			'i18n'       => [
				'sendCampaign'         => __( 'Enviar Campanha', 'bluesendmail' ),
				'wait'                 => __( 'Esperar', 'bluesendmail' ),
				'selectCampaign'       => __( 'Selecione uma campanha...', 'bluesendmail' ),
                'removeStep'           => __( 'Remover Passo', 'bluesendmail' ),
                'selectCampaignDesc'   => __('Selecione a campanha a ser enviada.', 'bluesendmail'),
                'waitDesc'             => __('Aguardar por um período antes de executar o próximo passo.', 'bluesendmail'),
                'minute'               => __( 'Minuto(s)', 'bluesendmail' ),
                'hour'                 => __( 'Hora(s)', 'bluesendmail' ),
                'day'                  => __( 'Dia(s)', 'bluesendmail' ),
                'addAction'            => __( 'Adicionar Ação de Envio', 'bluesendmail' ),
                'addDelay'             => __( 'Adicionar Espera', 'bluesendmail' ),
			],
		);
		wp_localize_script('bsm-automation-builder', 'bsmBuilderData', $js_data);
		?>
		<div class="wrap bsm-wrap">
            <?php $this->render_header( $automation ? esc_html__( 'Editar Automação', 'bluesendmail' ) : esc_html__( 'Criar Nova Automação', 'bluesendmail' ) ); ?>
            
            <form method="post">
                <?php wp_nonce_field( 'bsm_save_automation_nonce_action', 'bsm_save_automation_nonce_field' ); ?>
                <input type="hidden" name="automation_id" value="<?php echo esc_attr( $automation_id ); ?>">

                <div class="bsm-card">
                    <table class="form-table">
                        <tbody>
                            <tr>
                                <th scope="row"><label for="bsm-name"><?php _e( 'Nome da Automação', 'bluesendmail' ); ?></label></th>
                                <td><input type="text" name="name" id="bsm-name" class="large-text" value="<?php echo esc_attr( $automation->name ?? '' ); ?>" required>
                                <p class="description"><?php _e( 'Para sua referência interna.', 'bluesendmail' ); ?></p></td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="bsm-status"><?php _e( 'Status', 'bluesendmail' ); ?></label></th>
                                <td>
                                    <select name="status" id="bsm-status">
                                        <option value="active" <?php selected( $automation->status ?? 'inactive', 'active' ); ?>><?php _e( 'Ativo', 'bluesendmail' ); ?></option>
                                        <option value="inactive" <?php selected( $automation->status ?? 'inactive', 'inactive' ); ?>><?php _e( 'Inativo', 'bluesendmail' ); ?></option>
                                    </select>
                                </td>
                            </tr>
                             <tr>
                                <th scope="row"><label for="bsm-trigger-list"><?php _e( 'Gatilho: Quando o contato for adicionado à lista...', 'bluesendmail' ); ?></label></th>
                                <td>
                                    <select name="trigger_list_id" id="bsm-trigger-list" required>
                                        <option value=""><?php _e( 'Selecione uma lista...', 'bluesendmail' ); ?></option>
                                        <?php foreach($lists as $list): ?>
                                            <option value="<?php echo esc_attr($list->list_id); ?>" <?php selected( $trigger_settings['list_id'] ?? '', $list->list_id); ?>>
                                                <?php echo esc_html($list->name); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

				<div id="bsm-workflow-builder" class="bsm-card" style="margin-top: 24px;">
					<h2 class="bsm-card-title"><?php _e( 'Fluxo de Trabalho', 'bluesendmail' ); ?></h2>
					<p class="description"><?php _e( 'Adicione e reordene as ações que serão executadas quando o gatilho for disparado.', 'bluesendmail' ); ?></p>
					<div id="bsm-workflow-container" class="bsm-step-container">
						<!-- Passos (ações) são renderizados aqui pelo JavaScript -->
					</div>
				</div>

                <div class="submit" style="padding-top: 20px;">
                    <?php submit_button( $automation ? __( 'Salvar Alterações', 'bluesendmail' ) : __( 'Criar Automação', 'bluesendmail' ), 'primary bsm-btn bsm-btn-primary', 'bsm_save_automation', false ); ?>
                </div>
            </form>
        </div>

		<!-- Templates para JavaScript -->
		<script type="text/html" id="tmpl-bsm-step-card">
			<div class="bsm-workflow-step">
				<input type="hidden" class="bsm-step-field bsm-step-type-input" data-field-name="type" value="">
				<div class="bsm-step-header">
					<span class="bsm-step-drag-handle dashicons dashicons-menu"></span>
					<strong class="bsm-step-title"></strong>
					<button type="button" class="bsm-step-remove dashicons dashicons-no-alt" title="[REMOVE_STEP_TITLE]"></button>
				</div>
				<div class="bsm-step-body">
					<!-- Conteúdo da Ação -->
					<div class="bsm-step-content-action" style="display: none;">
						<p class="description" style="margin-bottom: 8px;">[SELECT_CAMPAIGN_DESC]</p>
						<select class="bsm-step-field bsm-campaign-select-action" data-field-name="campaign_id" style="width: 100%;"></select>
					</div>
					<!-- Conteúdo do Delay -->
					<div class="bsm-step-content-delay" style="display: none;">
						<p class="description" style="margin-bottom: 8px;">[WAIT_DESC]</p>
						<input type="number" class="bsm-step-field bsm-delay-value" data-field-name="value" min="1" value="1" style="width: 80px;">
						<select class="bsm-step-field bsm-delay-unit" data-field-name="unit">
							<option value="minute">[MINUTE_TEXT]</option>
							<option value="hour">[HOUR_TEXT]</option>
							<option value="day" selected>[DAY_TEXT]</option>
						</select>
					</div>
				</div>
			</div>
		</script>
		<script type="text/html" id="tmpl-bsm-add-buttons">
			<div class="bsm-add-step-container">
				<button type="button" class="button bsm-add-action-btn"><span class="dashicons dashicons-send"></span> [ADD_ACTION_TEXT]</button>
				<button type="button" class="button bsm-add-delay-btn"><span class="dashicons dashicons-clock"></span> [ADD_DELAY_TEXT]</button>
			</div>
		</script>
		<?php
	}
}

