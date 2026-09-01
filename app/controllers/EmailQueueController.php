<?php
declare(strict_types=1);
require_once __DIR__ . '/../models/EmailQueueModel.php';

/**
 * 2026-08-31, explicit request: admin log/summary page for the email_queue system built in Phase 7
 * (T040) -- see EmailQueueModel::list()/summary()'s own docblocks. Same shape as
 * PayslipDeliveryLogController (read-only, filter-driven, no update/delete -- writing only ever
 * happens from EmailChannel::send()/cron/send_queued_emails.php).
 */
class EmailQueueController extends Controller {
    private EmailQueueModel $model;

    public function __construct() {
        $this->model = new EmailQueueModel();
    }

    private function filtersFromRequest(): array {
        return [
            'status' => (string)($_GET['status'] ?? ''),
            'date_from' => (string)($_GET['date_from'] ?? ''),
            'date_to' => (string)($_GET['date_to'] ?? ''),
            'to_address' => (string)($_GET['to_address'] ?? ''),
        ];
    }

    public function list() {
        if (!$this->requirePermission('email_queue.view')) return;
        $compId = getCompId();
        if (!$compId) {
            $this->json(['status' => true, 'data' => []]);
            return;
        }
        $this->json(['status' => true, 'data' => $this->model->list((int)$compId, $this->filtersFromRequest())]);
    }

    public function summary() {
        if (!$this->requirePermission('email_queue.view')) return;
        $compId = getCompId();
        if (!$compId) {
            $this->json(['status' => true, 'data' => ['pending' => 0, 'sent' => 0, 'failed' => 0, 'total' => 0]]);
            return;
        }
        $this->json(['status' => true, 'data' => $this->model->summary((int)$compId, $this->filtersFromRequest())]);
    }
}
