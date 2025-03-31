<?php
namespace Opencart\Catalog\Model\Extension\Payneteasy\Payment;

class Payneteasy extends \Opencart\System\Engine\Model {
	
	
		/**
		 * Retrieves the payment methods available for PaynetEasy Pay.
		 *
		 * This method is responsible for returning the payment methods that are applicable for PaynetEasy Pay. It does so by first loading the necessary language
		 * pack for the PaynetEasy Pay extension. Next, it fetches the total amount currently present in the user's cart.
		 *
		 * Based on the total amount, if it's greater than or equal to 0.00, the method considers PaynetEasy Pay as an available payment method, setting the
		 * status variable to true. If the total amount is less than 0.00, it sets the status variable to false, indicating that PaynetEasy Pay is not available as a
		 * payment option.
		 *
		 * If the status is true, the method then creates an array with 'payneteasy' as the key, and the corresponding code and name as its value. It also populates
		 * the method_data array with details such as the code, title, name, option, and sort order for the PaynetEasy Pay method.
		 *
		 * Ultimately, the function returns the method_data array which contains the details of the PaynetEasy Pay payment method if it's available, otherwise an empty array.
		 *
		 * @param array $address (optional) An array containing address data. This parameter is currently not used in the method. Defaults to an empty array.
		 * @return array An array containing the details of the available PaynetEasy Pay payment method. If PaynetEasy Pay is not available, an empty array is returned.
		 */
		public function getMethods(array $address = []): array {
			
				$this->load->language('extension/payneteasy/payment/payneteasy');

				$total = $this->cart->getTotal();

				if ((float)$total >= 0.00) {
						$status = true;
				} else {
						$status = false;
				}

				$method_data = [];

				if ($status) {
						$option_data['payneteasy'] = [
								'code' => 'payneteasy.payneteasy',
								'name' => $this->language->get('heading_title')
						];

						$method_data = [
								'code'       => 'payneteasy',
								'title'      => $this->language->get('heading_title'),
								'name'       => $this->language->get('heading_title'),
								'option'     => $option_data,
								'sort_order' => $this->config->get('payment_payneteasy_sort_order'),
						];
				}

				return $method_data;
		}
}