<?php
declare(strict_types=1);
require_once __DIR__ . '/../models/AttendanceRecordModel.php';
require_once __DIR__ . '/../models/LeaveRequestModel.php';
require_once __DIR__ . '/../models/OvertimeRecordModel.php';

/**
 * Manual entry (Step 6) for attendance/leave/overtime -- the "No HR" user type keying data in
 * directly. department/position/shift/holiday/leave_type/ot_rate/employees already have their
 * own controllers elsewhere (Company Setup / Setup & Rules / Employee), unaffected by this file.
 * No permission gate here, matching the same precedent as PayslipTemplateController/
 * PayslipRequestController (RBAC in this project is scoped to Holiday/Leave Type/Approval
 * Workflow only, per CLAUDE.md).
 */
class ManualEntryController extends Controller {
    private AttendanceRecordModel $attendanceModel;
    private LeaveRequestModel $leaveModel;
    private OvertimeRecordModel $overtimeModel;

    public function __construct() {
        $this->attendanceModel = new AttendanceRecordModel();
        $this->leaveModel = new LeaveRequestModel();
        $this->overtimeModel = new OvertimeRecordModel();
    }

    private function userId(): int {
        return (int)($_SESSION['user']['employee_id'] ?? 0);
    }

    private function filtersFromQuery(): array {
        return [
            'employee_id' => $_GET['employee_id'] ?? null,
            'date_from' => $_GET['date_from'] ?? null,
            'date_to' => $_GET['date_to'] ?? null,
        ];
    }

    private function jsonBody(): ?array {
        $data = json_decode(file_get_contents('php://input'), true);
        return is_array($data) ? $data : null;
    }

    public function index() {
        $this->view('manual-entry/index');
    }

    /* ---------- Attendance ---------- */

    public function attendanceList() {
        $compId = getCompId();
        $this->json(['status' => true, 'data' => $this->attendanceModel->list((int)$compId, $this->filtersFromQuery())]);
    }

    public function attendanceGet() {
        $compId = getCompId();
        $id = (int)($_GET['id'] ?? 0);
        $row = $this->attendanceModel->get($id, (int)$compId);
        if (!$row) {
            $this->json(['status' => false, 'message' => 'Record not found.']);
            return;
        }
        $this->json(['status' => true, 'data' => $row]);
    }

    public function attendanceSave() {
        $compId = getCompId();
        $data = $this->jsonBody();
        if ($data === null) {
            $this->json(['status' => false, 'message' => 'Invalid request payload.']);
            return;
        }
        $this->json($this->attendanceModel->save($data, (int)$compId, $this->userId()));
    }

    public function attendanceDelete() {
        $compId = getCompId();
        $id = (int)($_POST['id'] ?? 0);
        $this->json($this->attendanceModel->delete($id, (int)$compId, $this->userId()));
    }

    /* ---------- Leave ---------- */

    public function leaveList() {
        $compId = getCompId();
        $this->json(['status' => true, 'data' => $this->leaveModel->list((int)$compId, $this->filtersFromQuery())]);
    }

    public function leaveGet() {
        $compId = getCompId();
        $id = (int)($_GET['id'] ?? 0);
        $row = $this->leaveModel->get($id, (int)$compId);
        if (!$row) {
            $this->json(['status' => false, 'message' => 'Record not found.']);
            return;
        }
        $this->json(['status' => true, 'data' => $row]);
    }

    public function leaveSave() {
        $compId = getCompId();
        $data = $this->jsonBody();
        if ($data === null) {
            $this->json(['status' => false, 'message' => 'Invalid request payload.']);
            return;
        }
        $this->json($this->leaveModel->save($data, (int)$compId, $this->userId()));
    }

    public function leaveDelete() {
        $compId = getCompId();
        $id = (int)($_POST['id'] ?? 0);
        $this->json($this->leaveModel->delete($id, (int)$compId, $this->userId()));
    }

    /* ---------- Overtime ---------- */

    public function overtimeList() {
        $compId = getCompId();
        $this->json(['status' => true, 'data' => $this->overtimeModel->list((int)$compId, $this->filtersFromQuery())]);
    }

    public function overtimeGet() {
        $compId = getCompId();
        $id = (int)($_GET['id'] ?? 0);
        $row = $this->overtimeModel->get($id, (int)$compId);
        if (!$row) {
            $this->json(['status' => false, 'message' => 'Record not found.']);
            return;
        }
        $this->json(['status' => true, 'data' => $row]);
    }

    public function overtimeSave() {
        $compId = getCompId();
        $data = $this->jsonBody();
        if ($data === null) {
            $this->json(['status' => false, 'message' => 'Invalid request payload.']);
            return;
        }
        $this->json($this->overtimeModel->save($data, (int)$compId, $this->userId()));
    }

    public function overtimeDelete() {
        $compId = getCompId();
        $id = (int)($_POST['id'] ?? 0);
        $this->json($this->overtimeModel->delete($id, (int)$compId, $this->userId()));
    }
}
