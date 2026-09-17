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
        // 2026-09-15: the same 4 account fields every payee picker returns ride along, so the form
        // can show a summary of the chosen destination. Masked number only -- listSaved() never
        // hands back the real one. 2026-09-17, tiny-L2: the label and this whole shape are composed
        // by the model's own optionItem(), which is what a form prefilling this picker from a stored
        // id reads too -- what this endpoint returns is unchanged.
        $items = array_map(static fn(array $r): array => PaymentDestinationModel::optionItem($r), $rows);
        $this->json(['status' => true, 'data' => ['items' => $items, 'total_count' => count($items)]]);
    }
}
