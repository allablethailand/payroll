<?php
declare(strict_types=1);
require_once __DIR__ . '/../models/PaymentDestinationModel.php';

/**
 * 2026-09-02, Deduction Destination & Third-Party Remittance -- Select2-ajax options endpoint for
 * the "pick a saved destination" dropdown (payee_type='other_person'). Same open/no-permission-
 * gate posture as MasterController::getMaster() (every other Select2-ajax dropdown in this app) --
 * only account_name + bank name are exposed here, never the encrypted account_no.
 */
class PaymentDestinationController extends Controller {
    private PaymentDestinationModel $model;

    public function __construct() {
        $this->model = new PaymentDestinationModel();
    }

    public function options() {
        $compId = getCompId();
        if (!$compId) {
            $this->json(['status' => true, 'data' => ['items' => [], 'total_count' => 0]]);
            return;
        }
        $searchTerm = (string)($_POST['searchTerm'] ?? '');
        $limit = (int)($_POST['limit'] ?? 20);
        $rows = $this->model->listSaved((int)$compId, $searchTerm, $limit);
        $items = array_map(static function (array $r): array {
            $bankName = $r['bank_name_th'] ?? $r['bank_name_en'] ?? '';
            $label = $r['account_name'] . ($bankName !== '' ? " ({$bankName})" : '');
            return ['id' => (int)$r['id'], 'text_th' => $label, 'text_en' => $label];
        }, $rows);
        $this->json(['status' => true, 'data' => ['items' => $items, 'total_count' => count($items)]]);
    }
}
