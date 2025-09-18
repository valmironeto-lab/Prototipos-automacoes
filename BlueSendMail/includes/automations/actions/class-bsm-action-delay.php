<?php
/**
 * Ação de Automação: Esperar (Delay).
 *
 * @package BlueSendMail
 * @version 2.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BSM_Action_Delay extends BSM_Abstract_Action {

    /**
     * Executa a ação de esperar, agendando o próximo passo para o futuro.
     */
    public function run() {
        $value = absint( $this->settings['value'] ?? 1 );
        $unit  = sanitize_key( $this->settings['unit'] ?? 'day' );

        $interval_map = [
            'minute' => 'PT' . $value . 'M',
            'hour'   => 'PT' . $value . 'H',
            'day'    => 'P' . $value . 'D',
        ];

        if ( ! isset( $interval_map[ $unit ] ) ) {
            $this->plugin->log_event( 'error', 'automation_action', "Unidade de tempo inválida ('{$unit}') para a ação de Espera na automação #{$this->queue_item->automation_id}." );
            // Se a unidade for inválida, agendamos a próxima ação imediatamente para não travar o fluxo.
            $this->schedule_next_action();
            return;
        }

        try {
            $process_at_dt = new DateTime( 'now', $this->plugin->bsm_get_timezone() );
            $process_at_dt->add( new DateInterval( $interval_map[ $unit ] ) );
            $process_at_gmt = $process_at_dt->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );

            $this->plugin->log_event( 'info', 'automation_action', "Delay: Contato #{$this->queue_item->contact_id} pausado na automação #{$this->queue_item->automation_id}. Próximo passo em {$process_at_gmt} UTC." );

            // Agenda a próxima ação para a data calculada.
            $this->schedule_next_action( $process_at_gmt );

        } catch ( Exception $e ) {
            $this->plugin->log_event( 'error', 'automation_action', "Erro ao calcular o delay para o item da fila #{$this->queue_item->queue_id}: " . $e->getMessage() );
            $this->schedule_next_action(); // Continua o fluxo imediatamente em caso de erro.
        }
    }
}

