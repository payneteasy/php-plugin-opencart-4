<?php
class ControllerExtensionPayneteasyRefund extends Controller {

    public function addHistory(&$route, &$args, &$output): void {
        $this->log->write($output);
    }
}