<?php
declare(strict_types=1);

/**
 * 2026-08-30, Phase 7 (T037/T038/T039). heartbeat() itself does almost nothing -- by the time this
 * method runs at all, ensure_login() (app/helpers/helpers.php, called before the router ever
 * dispatches here) has ALREADY run the real checks (superseded-by-a-newer-login / idle-timeout) and
 * would have exited with a 401 JSON {status:false, reason:...} on its own if either had tripped.
 * Reaching this method at all means the session is currently valid, so a plain {status:true} is the
 * entire response -- this endpoint exists purely to give public/js/session-guard.js's periodic poll
 * something to call that goes through ensure_login() WITHOUT counting as real user activity (see
 * that function's own `$isHeartbeatRoute` check for why that distinction matters).
 */
class SessionController extends Controller {
    public function heartbeat() {
        $this->json(['status' => true]);
    }
}
