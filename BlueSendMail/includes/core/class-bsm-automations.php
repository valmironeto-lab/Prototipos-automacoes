<?php
/**
 * Gerencia a lógica principal das automações. (Motor de Automação V2)
 *
 * @package BlueSendMail
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BSM_Automations {

    private $plugin;

    public function __construct( $plugin ) {
        $this->plugin = $plugin;
        $this->register_hooks();
    }

    private function register_hooks() {
        add_action( 'bsm_contact_added_to_list', array( $this, 'handle_contact_added_to_list' ), 10, 2 );
    }

    /**
     * Inicia um fluxo de trabalho para um contato quando ele entra numa lista.
     */
    public function handle_contact_added_to_list( $contact_id, $list_id ) {
        global $wpdb;
        $automations = $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}bluesendmail_automations WHERE status = 'active' AND trigger_type = 'contact_added_to_list'" );

        foreach ( $automations as $automation ) {
            $trigger_settings = maybe_unserialize( $automation->trigger_settings );
            if ( ! empty( $trigger_settings['list_id'] ) && absint( $trigger_settings['list_id'] ) === $list_id ) {
                $this->enqueue_contact( $contact_id, $automation->automation_id );
            }
        }
    }
	
	/**
	 * Processa um passo específico para um contato na fila de automação.
	 */
	public function process_step( $item ) {
		global $wpdb;
		$step = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}bluesendmail_automation_steps WHERE step_id = %d", $item->current_step_id ) );

		if ( ! $step ) {
			$this->plugin->log_event( 'error', 'automation_engine', "Passo #{$item->current_step_id} não encontrado para o item da fila #{$item->queue_id}. Finalizando fluxo." );
			return $this->complete_contact_journey( $item->queue_id );
		}
		
		switch ( $step->step_type ) {
			case 'action':
				$this->_process_action_step( $item, $step );
				break;
			case 'delay':
				$this->_process_delay_step( $item, $step );
				break;
			case 'condition':
				$this->_process_condition_step( $item, $step );
				break;
		}
	}

    /**
     * Enfileira um contato para o início de um fluxo de automação.
     */
    private function enqueue_contact( $contact_id, $automation_id ) {
        global $wpdb;
        $first_step = $this->get_next_step( $automation_id, 0 );

        if ( $first_step ) {
            $wpdb->insert(
                "{$wpdb->prefix}bluesendmail_automation_queue",
                array(
                    'automation_id'   => $automation_id,
                    'contact_id'      => $contact_id,
                    'status'          => 'waiting',
                    'current_step_id' => $first_step->step_id,
                    'process_at'      => current_time( 'mysql', 1 ),
                    'created_at'      => current_time( 'mysql', 1 ),
                )
            );
            $this->plugin->log_event( 'info', 'automation_trigger', "Contato #{$contact_id} enfileirado para o início da automação #{$automation_id}." );
        }
    }

	private function _process_action_step( $item, $step ) {
		global $wpdb;
		$settings = maybe_unserialize( $step->step_settings );
		
		if ( ! empty( $settings['campaign_id'] ) ) {
			$wpdb->insert( "{$wpdb->prefix}bluesendmail_queue", array(
				'campaign_id' => $settings['campaign_id'],
				'contact_id'  => $item->contact_id,
				'status'      => 'pending',
				'added_at'    => current_time( 'mysql', 1 )
			));
			$this->plugin->log_event( 'info', 'automation_engine', "Ação: Campanha #{$settings['campaign_id']} enfileirada para o contato #{$item->contact_id} pela automação #{$item->automation_id}." );
		}
		
		$this->move_to_next_step( $item, $step );
	}

	private function _process_delay_step( $item, $step ) {
		$settings = maybe_unserialize( $step->step_settings );
		$value = absint( $settings['value'] ?? 1 );
		$unit = sanitize_key( $settings['unit'] ?? 'days' );
		
		$delay_interval = "PT{$value}" . strtoupper( substr( $unit, 0, 1 ) );
		try {
			$process_at_dt = new DateTime( 'now', wp_timezone() );
			$process_at_dt->add( new DateInterval( $delay_interval ) );
			$process_at = $process_at_dt->format( 'Y-m-d H:i:s' );

			$this->move_to_next_step( $item, $step, 'waiting', $process_at );
			$this->plugin->log_event( 'info', 'automation_engine', "Delay: Contato #{$item->contact_id} pausado na automação #{$item->automation_id} até {$process_at}." );
		} catch ( Exception $e ) {
			$this->plugin->log_event( 'error', 'automation_engine', "Erro ao calcular delay para o item da fila #{$item->queue_id}: " . $e->getMessage() );
			$this->move_to_next_step( $item, $step ); // Pula o delay se houver erro
		}
	}
	
	private function _process_condition_step( $item, $step ) {
		$settings = maybe_unserialize( $step->step_settings );
		$condition_met = false;

		if ( 'campaign_opened' === $settings['type'] && ! empty( $settings['campaign_id'] ) ) {
			$condition_met = $this->check_campaign_opened_condition( $item->contact_id, $settings['campaign_id'] );
		}
		
		$branch_to_follow = $condition_met ? 'yes' : 'no';
		$next_step = $this->get_next_step( $item->automation_id, $step->step_id, $branch_to_follow );
		
		if ( $next_step ) {
			$this->update_contact_journey( $item->queue_id, $next_step->step_id );
		} else {
			$this->complete_contact_journey( $item->queue_id );
		}
	}

	private function check_campaign_opened_condition( $contact_id, $campaign_id ) {
		global $wpdb;
		$count = $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(o.open_id) FROM {$wpdb->prefix}bluesendmail_email_opens o 
			 JOIN {$wpdb->prefix}bluesendmail_queue q ON o.queue_id = q.queue_id
			 WHERE q.contact_id = %d AND q.campaign_id = %d",
			$contact_id,
			$campaign_id
		) );
		return $count > 0;
	}
	
	private function move_to_next_step( $item, $current_step, $new_status = 'waiting', $process_at = null ) {
		$next_step = $this->get_next_step( $item->automation_id, $current_step->step_id, $current_step->branch );
		
		if ( $next_step ) {
			$this->update_contact_journey( $item->queue_id, $next_step->step_id, $new_status, $process_at );
		} else {
			// Se não houver próximo passo no ramo atual, tenta subir um nível
			$parent_step = $this->get_parent_step( $current_step->parent_id );
			if( $parent_step ) {
				$this->move_to_next_step( $item, $parent_step, $new_status, $process_at );
			} else {
				$this->complete_contact_journey( $item->queue_id );
			}
		}
	}

	private function get_next_step( $automation_id, $current_step_id = 0, $branch = null ) {
		global $wpdb;
		$sql = $wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}bluesendmail_automation_steps 
			 WHERE automation_id = %d AND parent_id = %d",
			$automation_id,
			$current_step_id
		);

		if ( $branch ) {
			$sql .= $wpdb->prepare( " AND branch = %s", $branch );
		}
		
		$sql .= " ORDER BY step_order ASC LIMIT 1";

		return $wpdb->get_row( $sql );
	}
	
	private function get_parent_step( $parent_id ) {
		if ( ! $parent_id ) return null;
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}bluesendmail_automation_steps WHERE step_id = %d", $parent_id ) );
	}

	private function update_contact_journey( $queue_id, $next_step_id, $status = 'waiting', $process_at = null ) {
		global $wpdb;
		$data = array(
			'current_step_id' => $next_step_id,
			'status' => $status,
			'process_at' => $process_at ?? current_time( 'mysql', 1 ),
		);
		$wpdb->update( "{$wpdb->prefix}bluesendmail_automation_queue", $data, array( 'queue_id' => $queue_id ) );
	}
	
	private function complete_contact_journey( $queue_id ) {
		global $wpdb;
		$wpdb->update( "{$wpdb->prefix}bluesendmail_automation_queue", array( 'status' => 'completed' ), array( 'queue_id' => $queue_id ) );
	}
}

