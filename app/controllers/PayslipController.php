<?php
declare(strict_types=1);
class PayslipController extends Controller {
    public function requests() {
        $this->view('payslip/requests');
    }
    public function settings() {
        $this->view('payslip/settings');
    }
}
