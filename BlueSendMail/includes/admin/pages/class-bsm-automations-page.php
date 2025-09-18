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
		
		if ( $automation_id ) {
			$automation = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}bluesendmail_automations WHERE automation_id = %d", $automation_id ) );
			if( $automation ) {
				$trigger_settings = maybe_unserialize( $automation->trigger_settings );
			}
		}

        $lists = $wpdb->get_results("SELECT list_id, name FROM {$wpdb->prefix}bluesendmail_lists ORDER BY name ASC");
        $campaigns = $wpdb->get_results("SELECT campaign_id, title FROM {$wpdb->prefix}bluesendmail_campaigns WHERE status IN ('sent', 'draft') ORDER BY title ASC");

		// **CORREÇÃO PRINCIPAL:** Lógica robusta para construir a árvore de passos
		$steps_tree = array();
		if ( $automation_id ) {
			$all_steps_flat = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}bluesendmail_automation_steps WHERE automation_id = %d ORDER BY parent_id ASC, step_order ASC", $automation_id ), OBJECT_K );
			
			if ( $all_steps_flat ) {
				$steps_by_parent = [];
				foreach ( $all_steps_flat as $step ) {
					$steps_by_parent[$step->parent_id][] = $step;
				}

				$build_tree_func = function($parent_id) use (&$steps_by_parent, &$build_tree_func) {
					$branch = [];
					if (isset($steps_by_parent[$parent_id])) {
						foreach ($steps_by_parent[$parent_id] as $step) {
							if ($step->step_type === 'condition') {
								$step->yes_branch = $build_tree_func($step->step_id, 'yes');
								$step->no_branch = $build_tree_func($step->step_id, 'no');
							}
							$branch[] = $step;
						}
					}
					return $branch;
				};
				
				$steps_tree = $build_tree_func(0);
			}
		}

		$js_data = array(
			'steps_tree' => $steps_tree,
			'campaigns'  => array_map(function($c) { return ['id' => $c->campaign_id, 'title' => $c->title]; }, $campaigns),
			'i18n'       => [
				'sendCampaign'   => __( 'Enviar Campanha', 'bluesendmail' ),
				'wait'           => __( 'Esperar', 'bluesendmail' ),
				'if'             => __( 'Se/Senão', 'bluesendmail' ),
				'selectCampaign' => __( 'Selecione uma campanha...', 'bluesendmail' ),
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
					<div id="bsm-workflow-container" class="bsm-step-container">
						<!-- Steps will be rendered here by JavaScript -->
					</div>
				</div>

                <div class="submit" style="padding-top: 20px;">
                    <?php submit_button( $automation ? __( 'Salvar Alterações', 'bluesendmail' ) : __( 'Criar Automação', 'bluesendmail' ), 'primary bsm-btn bsm-btn-primary', 'bsm_save_automation', false ); ?>
                </div>
            </form>
        </div>

		<!-- Templates for JavaScript -->
		<script type="text/html" id="tmpl-bsm-step-card">
			<div class="bsm-workflow-step">
				<input type="hidden" class="bsm-step-field bsm-step-type-input" data-field-name="type" value="">
				<div class="bsm-step-header">
					<span class="bsm-step-drag-handle dashicons dashicons-menu"></span>
					<strong class="bsm-step-title"></strong>
					<button type="button" class="bsm-step-remove dashicons dashicons-no-alt"></button>
				</div>
				<div class="bsm-step-content">
					<!-- Action Content -->
					<div class="bsm-step-content-action" style="display: none;">
						<select class="bsm-step-field bsm-campaign-select-action" data-field-name="campaign_id"></select>
					</div>
					<!-- Delay Content -->
					<div class="bsm-step-content-delay" style="display: none;">
						<input type="number" class="bsm-step-field bsm-delay-value" data-field-name="value" min="1" value="1" style="width: 70px;">
						<select class="bsm-step-field bsm-delay-unit" data-field-name="unit">
							<option value="minute"><?php _e( 'Minuto(s)', 'bluesendmail' ); ?></option>
							<option value="hour"><?php _e( 'Hora(s)', 'bluesendmail' ); ?></option>
							<option value="day" selected><?php _e( 'Dia(s)', 'bluesendmail' ); ?></option>
						</select>
					</div>
					<!-- Condition Content -->
					<div class="bsm-step-content-condition" style="display: none;">
						<p><?php _e('Verificar se o contato abriu a campanha:', 'bluesendmail'); ?></p>
						<select class="bsm-step-field bsm-campaign-select-condition" data-field-name="campaign_id"></select>
					</div>
				</div>
				<div class="bsm-step-branches" style="display: none;">
					<div class="bsm-branch-yes">
						<div class="bsm-branch-title"><?php _e('Sim', 'bluesendmail'); ?></div>
						<div class="bsm-branch-container bsm-step-container" data-branch="yes"></div>
					</div>
					<div class="bsm-branch-no">
						<div class="bsm-branch-title"><?php _e('Não', 'bluesendmail'); ?></div>
						<div class="bsm-branch-container bsm-step-container" data-branch="no"></div>
					</div>
				</div>
			</div>
		</script>
		<script type="text/html" id="tmpl-bsm-add-buttons">
			<div class="bsm-add-step-container">
				<div class="bsm-add-step-line"></div>
				<div class="bsm-add-step-buttons">
					<button type="button" class="button bsm-add-action-btn"><span class="dashicons dashicons-send"></span> <?php _e( 'Adicionar Ação', 'bluesendmail' ); ?></button>
					<button type="button" class="button bsm-add-delay-btn"><span class="dashicons dashicons-clock"></span> <?php _e( 'Adicionar Espera', 'bluesendmail' ); ?></button>
					<button type="button" class="button bsm-add-condition-btn"><span class="dashicons dashicons-filter"></span> <?php _e( 'Adicionar Condição', 'bluesendmail' ); ?></button>
				</div>
			</div>
		</script>
		<?php
	}
}

