<?php
namespace Opencart\Admin\Model\Extension\Payneteasy\Payment;
class Payneteasy extends \Opencart\System\Engine\Model
{
    public function install(): void
    {
        $this->db->query("CREATE TABLE IF NOT EXISTS `" . DB_PREFIX . "payneteasy_payments` (
			`id` int(11) NOT NULL AUTO_INCREMENT,
			`paynet_order_id` int(11) NOT NULL,
			`merchant_order_id` int(11) NOT NULL,
			PRIMARY KEY (`id`)
			) ENGINE=MyISAM DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci
		");
    }

    public function uninstall(): void
    {
        $this->db->query("DROP TABLE IF EXISTS `" . DB_PREFIX . "payneteasy_payments`");
    }
}