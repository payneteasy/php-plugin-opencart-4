<?php

class ControllerExtensionEventRefund extends Controller {
    public function refund(&$route, &$data, &$output) {
        var_dump($route);
        var_dump($data);
        var_dump($output);
    }
}