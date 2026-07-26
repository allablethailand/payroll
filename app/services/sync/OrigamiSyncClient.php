<?php
declare(strict_types=1);
require_once __DIR__ . '/OrigamiSyncClientInterface.php';

/**
 * STUB -- no Origami HR API integration exists anywhere in this codebase (confirmed by survey:
 * no URL, no auth, no response spec). Every method throws until ORIGAMI_API_BASE_URL is set AND
 * this class is actually implemented against a real endpoint -- same "don't implement
 * speculatively" reasoning as LineChannel/TelegramChannel's docblocks.
 *
 * MasterDataSyncerInterface implementations and the sync orchestrator are fully built and
 * tested against a fake implementation of OrigamiSyncClientInterface (see
 * tests/master_data_sync_test.php's FakeOrigamiSyncClient) -- only this class is a stub. Once a
 * real API spec exists, implement the fetch*() methods here; nothing else in the sync engine
 * needs to change.
 */
class OrigamiSyncClient implements OrigamiSyncClientInterface {
    private function notConfigured(string $method): void {
        throw new RuntimeException("OrigamiSyncClient::{$method}() is not implemented yet. Set ORIGAMI_API_BASE_URL/ORIGAMI_API_KEY in .env and implement this method against the real Origami HR API before syncing.");
    }

    public function fetchDepartments(int $origamiCompanyId): array { $this->notConfigured(__FUNCTION__); }
    public function fetchPositions(int $origamiCompanyId): array { $this->notConfigured(__FUNCTION__); }
    public function fetchShifts(int $origamiCompanyId): array { $this->notConfigured(__FUNCTION__); }
    public function fetchHolidays(int $origamiCompanyId): array { $this->notConfigured(__FUNCTION__); }
    public function fetchLeaveTypes(int $origamiCompanyId): array { $this->notConfigured(__FUNCTION__); }
    public function fetchOtRates(int $origamiCompanyId): array { $this->notConfigured(__FUNCTION__); }
    public function fetchEmployees(int $origamiCompanyId): array { $this->notConfigured(__FUNCTION__); }
    public function fetchAttendance(int $origamiCompanyId, string $dateFrom, string $dateTo): array { $this->notConfigured(__FUNCTION__); }
    public function fetchLeaveRequests(int $origamiCompanyId, string $dateFrom, string $dateTo): array { $this->notConfigured(__FUNCTION__); }
    public function fetchOvertimeRecords(int $origamiCompanyId, string $dateFrom, string $dateTo): array { $this->notConfigured(__FUNCTION__); }
}
