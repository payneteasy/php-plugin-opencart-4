<?php
namespace Opencart\Catalog\Controller\Extension\Payneteasy\Payment;

require __DIR__ . '/vendor/autoload.php';

use \Payneteasy\Classes\PaynetApi,
    \Opencart\System\Engine\Registry,
    \Opencart\System\Engine\Controller;

class Payneteasy extends Controller {
  
    private $logger;


    /**
     * PaynetEasy constructor
     *
     * Set up the logging functionality for the PaynetEasy transactions.
     *
     * @param object $registry The registry of the OpenCart system
     */
    public function __construct(Registry $registry) {
        parent::__construct($registry);
        $this->logger = new \Opencart\System\Library\Log('payneteasy.log');
    }
    
    
    /**
     * Get Paynet API instance
     *
     * Returns an instance of the Paynet API using configuration values.
     *
     * @return PaynetApi An instance of the PaynetApi
     */
    private function getPaynetApi(): PaynetApi {
        return new PaynetApi(
            $this->config->get('payment_payneteasy_login'),
            $this->config->get('payment_payneteasy_control_key'),
            $this->config->get('payment_payneteasy_endpoint_id'),
            $this->config->get('payment_payneteasy_payment_method'),
            $this->config->get('payment_payneteasy_test_mode')
        );
    }


    /**
     * Index function
     *
     * Prepares data for the checkout view, and returns the rendered HTML.
     *
     * @return string Rendered HTML of the checkout view
     */
    public function index(): string {
        $this->load->language('extension/payneteasy/payment/payneteasy');
        $data['button_confirm'] = $this->language->get('button_confirm');
        $data['action'] = $this->url->link('extension/payneteasy/payment/payneteasy.confirm', '', true);
        $data['logo'] = '/extension/payneteasy/catalog/view/image/payment/payneteasy.png';
        $data['css'] = '/extension/payneteasy/catalog/view/stylesheet/payment/payneteasy.css';
        $data['desc'] = $this->config->get('payment_payneteasy_desc');
        $data['sandbox'] = $this->config->get('payment_payneteasy_test_mode');
        $data['payment_method'] = $this->config->get('payment_payneteasy_payment_method');
        return $this->load->view('extension/payneteasy/payment/payneteasy', $data);
    }


    /**
     * Confirm function
     *
     * Initiates a payment transaction with Paynet Pay and redirects the user to the Paynet Pay payment page.
     * Logs all Paynet Pay transactions.
     *
     * @return void
     */
    public function confirm(): void {
        try {
            $order_id = $this->session->data['order_id'] ?? null;
            if (!$order_id) {
                throw new \Exception($this->language->get('error_no_order_id'));
            }

            $this->load->model('checkout/order');
            $this->load->language('extension/payneteasy/payment/payneteasy');

            $order_info = $this->model_checkout_order->getOrder($this->session->data['order_id']);

            if (!$order_info) {
                throw new \Exception($this->language->get('error_order_not_found'));
            }

            // Only orders paid with this method
            $payment_code = $order_info['payment_method']['code'] ?? ($order_info['payment_code'] ?? '');
            if (strpos((string)$payment_code, 'payneteasy') !== 0) {
                throw new \Exception($this->language->get('error_order_not_found'));
            }

            $keys = [
                'card_number',
                'printed_name',
                'expire_month',
                'expire_year',
                'cvv2'
            ];

            foreach ($keys as $key) {
                if (!isset($this->request->post[$key])) {
                    $this->request->post[$key] = '';
                }
            }

            $card_data = [
                'credit_card_number' => $this->request->post['card_number']?:'',
                'card_printed_name' => $this->request->post['printed_name']?:'',
                'expire_month' => $this->request->post['expire_month']?:'',
                'expire_year' => $this->request->post['expire_year']?:'',
                'cvv2' => $this->request->post['cvv2']?:'',
            ];

            // Stores may skip the payment and shipping address at checkout:
            // fall back to the store country, which the gateway requires.
            $country = $order_info['payment_iso_code_2'] ?: $order_info['shipping_iso_code_2'];
            if (!$country) {
                $this->load->model('localisation/country');
                $store_country = $this->model_localisation_country->getCountry((int)$this->config->get('config_country_id'));
                $country = $store_country['iso_code_2'] ?? '';
            }

            $data = [
                'client_orderid' => (string)$order_id,
                'order_desc' => 'Order # ' . $order_id,
                'amount' => $order_info['total'],
                'currency' => $this->config->get('config_currency')?:'',
                'address1' => $order_info['payment_address_1']?:$order_info['shipping_address_1'],
                'city' => $order_info['payment_city']?:$order_info['shipping_city'],
                'zip_code' => $order_info['payment_postcode']?:$order_info['shipping_postcode'],
                'country' => $country,
                'phone'      => $order_info['telephone'],
                'email'      => $order_info['email'],
                'ipaddress' => $_SERVER['REMOTE_ADDR'],
                'cvv2' => $card_data['cvv2'],
                'credit_card_number' => $card_data['credit_card_number'],
                'card_printed_name' => $card_data['card_printed_name'],
                'expire_month' => $card_data['expire_month'],
                'expire_year' => $card_data['expire_year'],
                'first_name' => $order_info['firstname']?:$order_info['shipping_firstname'],
                'last_name'  => $order_info['lastname']?:$order_info['shipping_lastname'],
                'redirect_success_url'      => $this->url->link('extension/payneteasy/payment/payneteasy.callback'). '&orderId=' . $order_id, // $this->url->link('checkout/success', 'language=' . $this->config->get('config_language'), true)
                'redirect_fail_url'      => $this->url->link('extension/payneteasy/payment/payneteasy.callback'). '&orderId=' . $order_id, // $this->url->link('checkout/failure', 'language=' . $this->config->get('config_language'), true)
                'redirect_url' => $this->url->link('extension/payneteasy/payment/payneteasy.callback'). '&orderId=' . $order_id,
                'server_callback_url' => $this->url->link('extension/payneteasy/payment/payneteasy.callback'). '&orderId=' . $order_id,
            ];

            $data['control'] = $this->signPaymentRequest($data, $this->config->get('payment_payneteasy_endpoint_id'), $this->config->get('payment_payneteasy_control_key'));
            
            // The order status is set from the gateway answer in callback(). Setting it
            // here would stop OpenCart from updating the order (address, totals) when
            // the customer corrects the details and tries again after a failed payment.
            
            $payneteasy = $this->getPaynetApi();

            $action_url = $this->config->get('payment_payneteasy_live_url');

            if ($this->config->get('payment_payneteasy_test_mode') == true)
                $action_url = $this->config->get('payment_payneteasy_sandbox_url');

            if ($this->config->get('payment_payneteasy_payment_method') == 'form') {
                $response = $payneteasy->saleForm(
                    $data,
                    $this->config->get('payment_payneteasy_payment_method'),
                    $this->config->get('payment_payneteasy_test_mode'),
                    $action_url,
                    $this->config->get('payment_payneteasy_endpoint_id')
                );
            } elseif ($this->config->get('payment_payneteasy_payment_method') == 'direct') {
                $response = $payneteasy->saleDirect(
                    $data,
                    $this->config->get('payment_payneteasy_payment_method'),
                    $this->config->get('payment_payneteasy_test_mode'),
                    $action_url,
                    $this->config->get('payment_payneteasy_endpoint_id')
                );
            }
            
            if($this->config->get('payment_payneteasy_logging')) $this->logger->write('confirm: ' . json_encode($response));

            // Every attempt is kept: the customer may pay in an older tab
            if (isset($response['paynet-order-id'], $response['merchant-order-id'])) {
                $this->ensurePaymentsTable();
                $this->db->query("INSERT INTO " . DB_PREFIX . "payneteasy_payments SET paynet_order_id = '" . (int)$response['paynet-order-id'] . "', merchant_order_id = '" . (int)$response['merchant-order-id'] . "'");
            }

            if (!empty($response['redirect-url']) && $this->config->get('payment_payneteasy_payment_method') == 'form') {
                $this->response->redirect($response['redirect-url']);
            } elseif ($this->config->get('payment_payneteasy_payment_method') == 'direct' && !$this->config->get('payment_payneteasy_three_d_secure')) {
                $this->response->redirect($this->url->link('extension/payneteasy/payment/payneteasy.callback'). '&orderId=' . $order_id);
            } elseif ($this->config->get('payment_payneteasy_payment_method') == 'direct' && $this->config->get('payment_payneteasy_three_d_secure')) {
                $this->response->redirect($this->url->link('extension/payneteasy/payment/payneteasy.callback'). '&orderId=' . $order_id);
            } else {
                throw new \Exception("Paynet Pay URL not available");
            }
        } catch (\Exception $e) {
            $this->logger->write($e->getMessage());
            $this->session->data['error'] = $e->getMessage();
            $this->response->redirect($this->url->link('checkout/failure', '', true));
        }
    }

    
    /**
     * Callback function
     *
     * Processes the callback from Paynet Pay, updates the order status based on the callback data,
     * and prepares and displays a success or failure message to the user.
     *
     * @return void
     */
    public function callback(): void {
        $order_id = 0;
        $is_owner = false;
        $has_slot = false;

        try {
            $session_order_id = (int)($this->session->data['order_id'] ?? 0);
            $order_id = $session_order_id;
            if (isset($this->request->get['orderId']) && is_scalar($this->request->get['orderId'])) {
                $order_id = (int)$this->request->get['orderId'];
            }

            if ($order_id <= 0) {
                $order_id = 0;
                throw new \Exception($this->language->get('error_no_order_id_in_session'));
            }

            // The payment result is shown only to the customer whose session holds this
            // order. Any other request (gateway notification, a return without the session
            // cookie, a copied link) updates the order and gets a neutral page.
            $is_owner = $session_order_id === $order_id;

            $this->load->model('checkout/order');
            $this->load->language('extension/payneteasy/payment/payneteasy');

            $order_info = $this->model_checkout_order->getOrder($order_id);

            if (!$order_info) {
                throw new \Exception($this->language->get('error_order_not_found'));
            }

            // Only orders paid with this method
            $payment_code = $order_info['payment_method']['code'] ?? ($order_info['payment_code'] ?? '');
            if (strpos((string)$payment_code, 'payneteasy') !== 0) {
                throw new \Exception($this->language->get('error_order_not_found'));
            }

            $action_url = $this->config->get('payment_payneteasy_live_url');

            if ($this->config->get('payment_payneteasy_test_mode') == true)
                $action_url = $this->config->get('payment_payneteasy_sandbox_url');

            $payneteasy = $this->getPaynetApi();

            // Requests without the customer's session ask the gateway once,
            // and not more often than once in a few seconds per order
            if (!$is_owner) {
                $has_slot = $this->acquireCallbackSlot((int)$order_id);
                if (!$has_slot) {
                    $this->showNeutral((int)$order_id, true);
                    return;
                }
            }

            $response = $this->getPaymentStatus((int)$order_id, $payneteasy, $action_url);

            // The customer is waiting: if the bank has not answered yet and there is no
            // 3-D Secure page to show, ask again a few times before showing the result
            for ($attempt = 0; $is_owner && $attempt < 5 && trim($response['status'] ?? '') === 'processing' && empty($response['html']); $attempt++) {
                sleep(1);
                $response = $this->getPaymentStatus((int)$order_id, $payneteasy, $action_url);
            }

            // The gateway was asked for a while: check the order as it is now,
            // not as it was before the wait
            $order_info = $this->model_checkout_order->getOrder($order_id);
            if (!$order_info) {
                throw new \Exception($this->language->get('error_order_not_found'));
            }

            if($this->config->get('payment_payneteasy_logging')) $this->logger->write('callback: ' . json_encode($response));

            if ($is_owner && $this->config->get('payment_payneteasy_three_d_secure') == true) {
                if (isset($response['html']))
                    echo $response['html'];
            }

            if (trim($response['status'] ?? '') == 'approved') {
                $end_status = (int)$this->config->get('payment_payneteasy_transaction_end_status');

                if ((int)$order_info['order_status_id'] === $end_status) {
                    // Already paid: repeated returns change nothing
                } elseif (!$this->isAwaitingPayment($order_info)) {
                    // The order has been processed further (cancelled, shipped...): keep it
                    $this->logger->write('callback: order ' . (int)$order_id . ' is not awaiting payment, status not changed');
                    $this->addOrderNote((int)$order_id, (int)$order_info['order_status_id'], 'PaynetEasy: payment ' . $this->paymentLabel($response) . ' was approved, but the order had already been processed. The order status was not changed, check the payment.');
                } elseif (!$this->isPaidInFull($order_info, $response)) {
                    // The order was changed after the payment was started
                    $this->logger->write('callback: order ' . (int)$order_id . ' amount does not match the payment, status not changed');
                    // An order that is not placed yet is not listed in the admin panel:
                    // it gets the pending status, so the merchant sees the note
                    $this->addOrderNote((int)$order_id, (int)$order_info['order_status_id'] ?: (int)$this->config->get('config_order_status_id'), 'PaynetEasy: payment ' . $this->paymentLabel($response) . ' was approved, but it does not match the order total. The order was not marked as paid, check the payment.');
                    throw new \Exception($this->language->get('error_order_not_found'));
                } else {
                    $this->model_checkout_order->addHistory($order_id, $end_status);
                }
                $data['text_message'] = $this->language->get('text_success');
                $data['heading_title'] = $this->language->get('heading_title_success');
                // Only the cart of the customer who placed this order
                if ((int)($this->session->data['order_id'] ?? 0) === (int)$order_id) {
                    $this->cart->clear();
                    unset($this->session->data['order_id']);
                }
            } elseif (trim($response['status'] ?? '') == 'processing') {
                // Payment in progress: show the order as pending
                if (!(int)$order_info['order_status_id']) {
                    $this->model_checkout_order->addHistory($order_id, (int)$this->config->get('config_order_status_id'));
                }
                $data['heading_title'] = $this->language->get('heading_title_fail');
                $data['text_message'] = $this->language->get('text_fail');
            } else {
                // Declined or error: the order stays editable, the customer can try again.
                // An order put to the pending status while the payment was processing
                // (3-D Secure) goes back to "not placed", as a decline without 3-D Secure.
                if ((int)$order_info['order_status_id'] === (int)$this->config->get('config_order_status_id')
                    && in_array(trim($response['status'] ?? ''), ['declined', 'filtered', 'error'], true)) {
                    $this->model_checkout_order->addHistory($order_id, 0, 'PaynetEasy: payment declined');
                }
                $data['heading_title'] = $this->language->get('heading_title_fail');
                $data['text_message'] = $this->language->get('text_fail');
            }
            
            if ($is_owner) {
                $this->showSuccess($data);
            } else {
                $this->showNeutral((int)$order_id);
            }
        } catch (\Exception $e) {
            $this->log->write("Paynet Pay Error: " . $e->getMessage());
            if ($is_owner) {
                $this->response->redirect($this->url->link('checkout/failure'));
            } else {
                $this->showNeutral((int)$order_id);
            }
        } finally {
            if ($has_slot) {
                $this->db->query("SELECT RELEASE_LOCK('" . $this->db->escape($this->callbackLockName((int)$order_id)) . "')");
            }
        }
    }


    /**
     * Name of the database lock of the order callback
     */
    private function callbackLockName(int $order_id): string {
        return DB_DATABASE . '.' . DB_PREFIX . 'payneteasy_cb_' . $order_id;
    }

    /**
     * Limits the gateway requests caused by requests without the customer's session:
     * one request per order at a time and not more often than once in 3 seconds.
     *
     * @param int $order_id Order id.
     * @return bool False, if the request must not ask the gateway now.
     */
    private function acquireCallbackSlot(int $order_id): bool {
        $name = $this->db->escape($this->callbackLockName($order_id));

        $lock = $this->db->query("SELECT GET_LOCK('" . $name . "', 0) AS `locked`");
        if ((int)($lock->row['locked'] ?? 0) !== 1) {
            return false;
        }

        $key = 'payneteasy.cb.' . $order_id;
        $last = $this->cache->get($key);
        if (is_scalar($last) && time() - (int)$last < 3) {
            $this->db->query("SELECT RELEASE_LOCK('" . $name . "')");
            return false;
        }
        $this->cache->set($key, (string)time(), 60);

        return true;
    }

    /**
     * Gateway payment id, amount and currency for an order note
     */
    private function paymentLabel(array $response): string {
        $label = preg_replace('/[^0-9]/', '', (string)($response['paynet-order-id'] ?? ''));
        $amount = preg_replace('/[^0-9.]/', '', (string)($response['amount'] ?? ''));
        $currency = preg_replace('/[^A-Za-z]/', '', (string)($response['currency'] ?? ''));

        return trim($label . ($amount !== '' ? ' (' . trim($amount . ' ' . $currency) . ')' : ''));
    }

    /**
     * Adds a note to the order history once: repeated requests do not repeat it
     *
     * @param int    $order_id        Order id.
     * @param int    $order_status_id Status kept with the note.
     * @param string $comment         Note text.
     * @return void
     */
    private function addOrderNote(int $order_id, int $order_status_id, string $comment): void {
        $exists = $this->db->query("SELECT order_history_id FROM `" . DB_PREFIX . "order_history` WHERE order_id = '" . $order_id . "' AND comment = '" . $this->db->escape($comment) . "' LIMIT 1");
        if ($exists->num_rows) {
            return;
        }

        $this->model_checkout_order->addHistory($order_id, $order_status_id, $comment, false);
    }

    /**
     * Page for a request that is not made from the session of the order's customer:
     * it tells nothing about the order. The first answer sends the browser to the same
     * address once more: a return from the payment page comes without the session
     * cookie, the repeated request from the shop's own page carries it.
     *
     * @param int  $order_id  Order id, 0 if unknown.
     * @param bool $throttled True, if the gateway was not asked because of the limit.
     * @return void
     */
    private function showNeutral(int $order_id, bool $throttled = false): void {
        $this->load->language('extension/payneteasy/payment/payneteasy');

        if ($throttled) {
            $this->response->addHeader(($this->request->server['SERVER_PROTOCOL'] ?? 'HTTP/1.1') . ' 429 Too Many Requests');
            $this->response->addHeader('Retry-After: 5');
        }

        if ($order_id > 0 && empty($this->request->get['shown'])) {
            $url = htmlspecialchars(str_replace('&amp;', '&', $this->url->link('extension/payneteasy/payment/payneteasy.callback')) . '&orderId=' . $order_id . '&shown=1', ENT_QUOTES, 'UTF-8');
            $text = htmlspecialchars($this->language->get('text_neutral'), ENT_QUOTES, 'UTF-8');
            $this->response->addHeader('Cache-Control: no-store');
            $this->response->setOutput('<!DOCTYPE html><html><head><meta charset="utf-8"><meta http-equiv="refresh" content="0;url=' . $url . '"><title></title></head><body><p>' . $text . '</p><p><a href="' . $url . '">' . htmlspecialchars($this->language->get('button_continue'), ENT_QUOTES, 'UTF-8') . '</a></p></body></html>');
            return;
        }

        $data['heading_title'] = $this->language->get('heading_title_neutral');
        $data['text_message'] = $this->language->get('text_neutral');

        $this->showSuccess($data);
    }
    
    
    /**
     * True, if the order has not got a payment result yet
     *
     * @param array $order_info Order data.
     * @return bool
     */
    protected function isAwaitingPayment(array $order_info): bool {
        $status_id = (int)$order_info['order_status_id'];

        // 0: checkout not finished; config_order_status_id: set while the payment is processing
        return $status_id === 0 || $status_id === (int)$this->config->get('config_order_status_id');
    }

    /**
     * True, if the gateway amount and currency match the order total
     *
     * @param array $order_info Order data.
     * @param array $response   Gateway status response.
     * @return bool
     */
    protected function isPaidInFull(array $order_info, array $response): bool {
        $amount   = trim((string)($response['amount'] ?? ''));
        $currency = trim((string)($response['currency'] ?? ''));

        if (!preg_match('/^\d+(\.\d{1,2})?$/', $amount)) {
            return false;
        }

        // confirm() sends the order total in the default currency
        return strcasecmp($currency, (string)$this->config->get('config_currency')) === 0
            && (int)round((float)$amount * 100) === (int)round((float)$order_info['total'] * 100);
    }

    /**
     * Show Success function
     *
     * Prepares and displays a success page to the user.
     *
     * @param array $data Array of data for the success page view.
     * @return void
     */
    public function showSuccess(array $data): void {
      
    		$this->document->setTitle($data['heading_title']);

    		$data['breadcrumbs'] = [];

    		$data['breadcrumbs'][] = [
    			'text' => $this->language->get('text_home'),
    			'href' => $this->url->link('common/home', 'language=' . $this->config->get('config_language'))
    		];

        $data['breadcrumbs'][] = [
          'text' => $this->language->get('text_basket'),
          'href' => $this->url->link('checkout/cart', 'language=' . $this->config->get('config_language'))
        ];

    		$data['breadcrumbs'][] = [
    			'text' => $data['heading_title'],
    			'href' => $this->url->link('account/success', 'language=' . $this->config->get('config_language') . (isset($this->session->data['customer_token']) ? '&customer_token=' . $this->session->data['customer_token'] : ''))
    		];
        
        $data['column_left'] = $this->load->controller('common/column_left');
        $data['column_right'] = $this->load->controller('common/column_right');
        $data['content_top'] = $this->load->controller('common/content_top');
        $data['content_bottom'] = $this->load->controller('common/content_bottom');
        $data['footer'] = $this->load->controller('common/footer');
        $data['header'] = $this->load->controller('common/header');
        $data['continue'] = $this->url->link('checkout/cart');
        $this->response->setOutput($this->load->view('common/success', $data));
    }

    /**
     * Gateway ids do not fit into int: widen the column of tables created by older versions.
     */
    /**
     * Status of the order payment: all attempts of the order are checked, newest first
     * (the customer may pay in an older tab). An approved attempt wins, then a
     * processing one, otherwise the latest attempt.
     */
    private function getPaymentStatus(int $order_id, PaynetApi $payneteasy, string $action_url): array
    {
        $attempts = $this->db->query("SELECT paynet_order_id FROM " . DB_PREFIX . "payneteasy_payments WHERE merchant_order_id = '" . $order_id . "' ORDER BY id DESC LIMIT 5")->rows ?: [['paynet_order_id' => '']];

        $latest = null;
        $processing = null;

        foreach ($attempts as $row) {
            $data = [
                'login' => $this->config->get('payment_payneteasy_login'),
                'client_orderid' => (string)$order_id,
                'orderid' => (string)$row['paynet_order_id'],
            ];
            $data['control'] = $this->signStatusRequest($data, $this->config->get('payment_payneteasy_login'), $this->config->get('payment_payneteasy_control_key'));

            $response = $payneteasy->status($data, $this->config->get('payment_payneteasy_payment_method'), $this->config->get('payment_payneteasy_test_mode'), $action_url, $this->config->get('payment_payneteasy_endpoint_id'));

            // The gateway answers for any transaction id: use only answers for this order
            if (isset($response['merchant-order-id']) && trim((string)$response['merchant-order-id']) !== (string)$order_id) {
                continue;
            }

            $status = trim((string)($response['status'] ?? ''));
            if ($status === 'approved') {
                return $response;
            }
            if ($status === 'processing' && $processing === null) {
                $processing = $response;
            }
            if ($latest === null) {
                $latest = $response;
            }
        }

        return $processing ?? $latest ?? [];
    }


    private function ensurePaymentsTable(): void
    {
        $column = $this->db->query("SHOW COLUMNS FROM `" . DB_PREFIX . "payneteasy_payments` LIKE 'paynet_order_id'");
        if ($column->num_rows && stripos($column->row['Type'], 'bigint') !== 0) {
            $this->db->query("ALTER TABLE `" . DB_PREFIX . "payneteasy_payments` MODIFY `paynet_order_id` bigint(20) unsigned NOT NULL");
        }
    }

    private function signPaymentRequest($data, $endpointId, $merchantControl)
    {
        $base = '';
        $base .= $endpointId;
        $base .= $data['client_orderid'];
        $base .= $data['amount'] * 100;
        $base .= $data['email'];

        return $this->signString($base, $merchantControl);
    }

    private function signStatusRequest($requestFields, $login, $merchantControl)
    {
        $base = '';
        $base .= $login;
        $base .= $requestFields['client_orderid'];
        $base .= $requestFields['orderid'];

        return $this->signString($base, $merchantControl);
    }

    private function signString($s, $merchantControl)
    {
        return sha1($s . $merchantControl);
    }
}
