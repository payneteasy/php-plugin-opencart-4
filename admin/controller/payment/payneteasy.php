<?php
namespace Opencart\Admin\Controller\Extension\Payneteasy\Payment;

class PaynetEasy extends \Opencart\System\Engine\Controller {
	
	
	/**
   * Index function
   *
   * This method handles the loading of the PaynetEasy Pay extension page in the OpenCart admin.
   * It sets up the necessary data for the view, including breadcrumbs, save and back URLs,
   * and PaynetEasy Pay specific configuration values.
   *
   * @return void
   */
	public function index(): void {
		
		$this->load->language('extension/payneteasy/payment/payneteasy');
		$this->document->setTitle($this->language->get('heading_title'));
		$this->load->model('localisation/order_status');

        $fields = [
            'desc',
            'live_url',
            'sandbox_url',
            'three_d_secure',
            'endpoint_id',
            'control_key',
            'login',
            'transaction_end_status',
            'test_mode',
            'logging',
            'status',
            'sort_order',
            'payment_method'
        ];
    
		foreach ($fields as $field) {
			$data['payment_payneteasy_' . $field] = $this->config->get('payment_payneteasy_' . $field);
		}
		
		if(empty($data['payment_payneteasy_desc']))
		{
				$data['payment_payneteasy_desc'] = $this->language->get('default_value_desc');
		}

		$data['order_statuses'] = $this->model_localisation_order_status->getOrderStatuses();

        $data['payment_methods'] = [
            [
                'value' => 'direct',
                'name' => 'DIRECT',
            ],
            [
                'value' => 'form',
                'name' => 'FORM',
            ],
        ];


		$this->showPaymentSettingsPage($data);
	}

    public function install(): void {
        if ($this->user->hasPermission('modify', 'extension/payment')) {
            $this->load->model('extension/payneteasy/payment/payneteasy');
            $this->load->model('setting/event');
            $this->model_extension_payneteasy_payment_payneteasy->install();
            $this->model_setting_event->addEvent([
                'code' => 'refund',
                'description' => 'refund',
                'trigger' => 'catalog/model/checkout/order/addHistory/after',
                'action' => 'extension/payneteasy/refund/addHistory',
                'status' => true,
                'sort_order' => 0
            ]);
        }
    }

    public function uninstall(): void {
        if ($this->user->hasPermission('modify', 'extension/payment')) {
            $this->load->model('extension/payneteasy/payment/payneteasy');
            $this->model_setting_event->deleteEventByCode('refund');
            $this->model_extension_payneteasy_payment_payneteasy->uninstall();
        }
    }

    public function addHistory(int $order_id, int $order_status_id, string $comment = '', bool $notify = false, bool $override = false): void {
        $this->log->write($order_id);
    }
	
	
	/**
	 * showPaymentSettingsPage method
	 *
	 * This method prepares the breadcrumbs and other necessary data for the view,
	 * and then it renders the payment settings page.
	 *
	 * @param array $data The data array that will be passed to the view.
	 * @return void
	 */
	private function showPaymentSettingsPage(array $data): void {
			$data['breadcrumbs'] = [];

			$data['breadcrumbs'][] = [
				'text' => $this->language->get('text_home'),
				'href' => $this->url->link('common/dashboard', 'user_token=' . $this->session->data['user_token'])
			];

			$data['breadcrumbs'][] = [
				'text' => $this->language->get('text_extension'),
				'href' => $this->url->link('marketplace/extension', 'user_token=' . $this->session->data['user_token'] . '&type=payment')
			];

			$data['breadcrumbs'][] = [
				'text' => $this->language->get('heading_title'),
				'href' => $this->url->link('extension/payneteasy/payment/payneteasy', 'user_token=' . $this->session->data['user_token'])
			];

			$data['save'] = $this->url->link('extension/payneteasy/payment/payneteasy.save', 'user_token=' . $this->session->data['user_token']);
			$data['back'] = $this->url->link('marketplace/extension', 'user_token=' . $this->session->data['user_token'] . '&type=payment');

			$data['header'] = $this->load->controller('common/header');
			$data['column_left'] = $this->load->controller('common/column_left');
			$data['footer'] = $this->load->controller('common/footer');

			$this->response->setOutput($this->load->view('extension/payneteasy/payment/payneteasy', $data));
	}


	/**
   * Save function
   *
   * This method handles the submission of the PaynetEasy Pay configuration form in the OpenCart admin.
   * It checks for the necessary permissions, validates required fields, and if everything is correct,
   * saves the provided data using the setting model. It then returns a JSON response indicating success
   * or any errors encountered.
   *
   * @return void
   */
	public function save(): void {
		$this->load->language('extension/payneteasy/payment/payneteasy');

		$json = [];

		if (!$this->user->hasPermission('modify', 'extension/payneteasy/payment/payneteasy')) {
			$json['error']['warning'] = $this->language->get('error_permission');
		}

		$fields = [
      'endpoint_id',
      'control_key'
    ];
    
		foreach ($fields as $field) {
			if (empty($this->request->post['payment_payneteasy_' . $field])) {
				$json['error'][$field] = $this->language->get('error_' . $field);
			}
		}

		if (!$json) {
			$this->load->model('setting/setting');
			$this->model_setting_setting->editSetting('payment_payneteasy', $this->request->post);
			$json['success'] = $this->language->get('text_success');
		}

		$this->response->addHeader('Content-Type: application/json');
		$this->response->setOutput(json_encode($json));
	}
}
