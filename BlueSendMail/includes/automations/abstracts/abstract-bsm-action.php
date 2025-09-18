<?php
/**
 * Classe Abstrata para Ações de Automação.
 * Define o contrato que todas as ações devem seguir.
 *
 * @package BlueSendMail
 * @version 2.1.1
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

abstract class BSM_Abstract_Action {

    /**
     * O plugin principal.
     * @var BlueSendMail
     */
    protected $plugin;

    /**
     * O item da fila que está sendo processado.
     * @var stdClass
     */
    protected $queue_item;

    /**
     * A camada de dados para esta execução.
     * @var BSM_Data_Layer
     */
    protected $data_layer;

    /**
     * As configurações específicas desta ação.
     * @var array
     */
    protected $settings;

    /**
     * Construtor.
     *
     * @param BlueSendMail   $plugin      A instância principal do plugin.
     * @param stdClass       $queue_item  O objeto do item da fila do banco de dados.
     * @param BSM_Data_Layer $data_layer  A camada de dados com informações do contato, etc.
     * @param array          $settings    As configurações do passo (step) salvas no banco de dados.
     */
    public function __construct( $plugin, $queue_item, $data_layer, $settings ) {
        $this->plugin     = $plugin;
        $this->queue_item = $queue_item;
        $this->data_layer = $data_layer;
        $this->settings   = $settings;
    }

    /**
     * Executa a lógica principal da ação.
     * Este método deve ser implementado por todas as classes de ação concretas.
     */
    abstract public function run();

    /**
     * Enfileira a próxima ação na sequência da automação.
     *
     * @param string|null $process_at Data/hora para processar a próxima ação (formato 'Y-m-d H:i:s'). Null para processamento imediato.
     */
    protected function schedule_next_action( $process_at = null ) {
        global $wpdb;

        $automation_id    = $this->queue_item->automation_id;
        $next_step_index  = (int) $this->queue_item->next_step_index + 1;

        $total_steps = $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(step_id) FROM {$wpdb->prefix}bluesendmail_automation_steps WHERE automation_id = %d",
            $automation_id
        ) );

        if ( $next_step_index >= $total_steps ) {
            // Fim da automação, marca como concluída.
            $wpdb->update(
                "{$wpdb->prefix}bluesendmail_automation_queue",
                array( 'status' => 'completed' ),
                array( 'queue_id' => $this->queue_item->queue_id )
            );
             $this->plugin->log_event( 'info', 'automation_engine', "Automação #{$automation_id} concluída para o contato #{$this->queue_item->contact_id}." );
        } else {
            // <<< CORREÇÃO: Adicionado 'status' => 'waiting' para garantir que o cron continue processando. >>>
            $wpdb->update(
                "{$wpdb->prefix}bluesendmail_automation_queue",
                array(
                    'next_step_index' => $next_step_index,
                    'status'          => 'waiting',
                    'process_at'      => $process_at ?? current_time( 'mysql', 1 ),
                ),
                array( 'queue_id' => $this->queue_item->queue_id )
            );
        }
    }
}

