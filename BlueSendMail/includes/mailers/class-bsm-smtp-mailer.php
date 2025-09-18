<?php
/**
 * Mailer class for sending emails via SMTP.
 * It primarily configures PHPMailer and lets wp_mail handle the sending.
 *
 * @package BlueSendMail
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BSM_SMTP_Mailer extends BSM_WPMail_Mailer {

	private $options;

	public function __construct( $options ) {
		parent::__construct();
		$this->options = $options;
		add_action( 'phpmailer_init', array( $this, 'configure_smtp' ) );
	}

	/**
	 * Configures the PHPMailer instance for SMTP.
	 *
	 * @param PHPMailer $phpmailer The PHPMailer instance.
	 */
	public function configure_smtp( $phpmailer ) {
		$phpmailer->isSMTP();

        // **MELHORIA DE SEGURANÇA: Prioriza constantes definidas no wp-config.php**
        // Isso permite que os usuários armazenem credenciais sensíveis fora do banco de dados.
		$phpmailer->Host       = defined( 'BSM_SMTP_HOST' ) ? BSM_SMTP_HOST : ( $this->options['smtp_host'] ?? '' );
		$phpmailer->Port       = defined( 'BSM_SMTP_PORT' ) ? BSM_SMTP_PORT : ( $this->options['smtp_port'] ?? 587 );
		$phpmailer->SMTPSecure = defined( 'BSM_SMTP_ENCRYPTION' ) ? BSM_SMTP_ENCRYPTION : ( $this->options['smtp_encryption'] ?? 'tls' );
		$phpmailer->Username   = defined( 'BSM_SMTP_USER' ) ? BSM_SMTP_USER : ( $this->options['smtp_user'] ?? '' );
		$phpmailer->Password   = defined( 'BSM_SMTP_PASS' ) ? BSM_SMTP_PASS : ( $this->options['smtp_pass'] ?? '' );
        
        // A autenticação só é necessária se um usuário e senha forem fornecidos.
        if ( ! empty( $phpmailer->Username ) && ! empty( $phpmailer->Password ) ) {
		    $phpmailer->SMTPAuth   = true;
        } else {
            $phpmailer->SMTPAuth   = false;
        }

		$phpmailer->From       = $this->options['from_email'] ?? get_bloginfo( 'admin_email' );
		$phpmailer->FromName   = $this->options['from_name'] ?? get_bloginfo( 'name' );
	}
}
