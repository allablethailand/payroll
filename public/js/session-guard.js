/**
 * 2026-08-30, Phase 7 (T037 "1 User 1 Login" / T038 30-minute idle timeout / T039 timeout popup +
 * auto-redirect). The SERVER (app/helpers/helpers.php's ensure_login(), run before every request
 * reaches a controller) is the sole AUTHORITY on both rules -- everything in this file is UX on top
 * of that, never a substitute for it. A paused tab, a tampered/disabled script, or devtools open
 * doesn't grant extra time: the very next real request this tab makes still gets rejected
 * server-side regardless of what this file did or didn't do.
 *
 * Two independent mechanisms:
 *  1. A client-side idle timer (resetIdleTimer(), reset on any REAL interaction event -- mousemove/
 *     mousedown/keydown/scroll/touchstart/click) fires showSessionEndedPopup('timeout') the moment
 *     THIS tab has been genuinely idle for SESSION_IDLE_TIMEOUT_SECONDS (header.php's own JS
 *     global, the SAME number ensure_login() enforces server-side -- kept as one shared constant so
 *     the two can never drift apart) -- this is what makes T039's "popup appears even if you just
 *     left the screen sitting there, with no page navigation to trigger a server round-trip at all"
 *     requirement possible; a purely server-driven check could only ever fire on the NEXT real
 *     request, which never comes while the tab just sits idle.
 *  2. A periodic heartbeat (GET api/session.heartbeat, every 60s) that goes through ensure_login()
 *     like any other request but is EXPLICITLY EXCLUDED there from bumping last_activity (see that
 *     function's own $isHeartbeatRoute comment) -- this is what catches T037 (superseded by a login
 *     elsewhere) and a server-side timeout within about a minute even while this tab is genuinely
 *     idle, without that background poll itself resetting the idle clock it's trying to check.
 * A third path (the global $(document).ajaxError() hook below) catches the same 401 signal from
 * ANY of this app's existing ajax calls the instant one happens to fire, rather than waiting up to
 * 60s for the next heartbeat tick.
 */
(function () {
    if (typeof SESSION_EMPLOYEE_ID === 'undefined' || !SESSION_EMPLOYEE_ID) {
        return;
    }
    const IDLE_TIMEOUT_MS = (typeof SESSION_IDLE_TIMEOUT_SECONDS === 'number' ? SESSION_IDLE_TIMEOUT_SECONDS : 1800) * 1000;
    const HEARTBEAT_INTERVAL_MS = 60 * 1000;
    let idleTimer = null;
    let sessionEnded = false;

    function backToOrigamiUrl() {
        return (typeof ORIGAMI_BASE_URL === 'string' && ORIGAMI_BASE_URL !== '') ? ORIGAMI_BASE_URL : '/';
    }

    function showSessionEndedPopup(reason) {
        if (sessionEnded) {
            return;
        }
        sessionEnded = true;
        clearTimeout(idleTimer);
        const messages = {
            timeout: (typeof langData !== 'undefined' && langData['session_timeout_message']) || 'Your session has timed out due to inactivity.',
            superseded: (typeof langData !== 'undefined' && langData['session_superseded_message']) || 'This account was signed in from another device or browser. You have been signed out here.',
        };
        const text = messages[reason] || ((typeof langData !== 'undefined' && langData['session_ended_message']) || 'Your session has ended. Please sign in again.');
        // 2026-08-30: one more heartbeat call (fire-and-forget, ignore the result either way) so the
        // server-side teardown (session_kill_response()) actually runs and the login log row is
        // correctly marked ended BEFORE the user leaves -- the client-side idle timer above can fire
        // this popup before the server has seen any request past the timeout mark at all, so without
        // this the DB/session could still look "active" for a little while after the user already
        // saw and acknowledged the popup.
        $.ajax({ url: `${BASE_URL}/api/session.heartbeat`, method: 'GET', dataType: 'json' });
        Swal.fire({
            icon: 'warning',
            title: (typeof langData !== 'undefined' && langData['session_ended_title']) || 'Session Ended',
            text: text,
            confirmButtonText: (typeof langData !== 'undefined' && langData['back_to_origami']) || 'Back to Origami',
            showCancelButton: false,
            allowOutsideClick: false,
            allowEscapeKey: false,
        }).then(function () {
            window.location.href = backToOrigamiUrl();
        });
    }

    function resetIdleTimer() {
        if (sessionEnded) {
            return;
        }
        clearTimeout(idleTimer);
        idleTimer = setTimeout(function () { showSessionEndedPopup('timeout'); }, IDLE_TIMEOUT_MS);
    }
    ['mousemove', 'mousedown', 'keydown', 'scroll', 'touchstart', 'click'].forEach(function (evt) {
        document.addEventListener(evt, resetIdleTimer, { passive: true });
    });
    resetIdleTimer();

    function heartbeat() {
        if (sessionEnded || document.hidden) {
            return;
        }
        $.ajax({ url: `${BASE_URL}/api/session.heartbeat`, method: 'GET', dataType: 'json' })
            .fail(function (xhr) {
                if (xhr.status === 401) {
                    showSessionEndedPopup((xhr.responseJSON && xhr.responseJSON.reason) || 'not_logged_in');
                }
            });
    }
    setInterval(heartbeat, HEARTBEAT_INTERVAL_MS);

    // Catch-all: ANY ajax call anywhere in this app that comes back 401 with a session-specific
    // reason (timeout/superseded -- deliberately NOT plain 'not_logged_in', which is the
    // pre-existing/mundane case every page's own ad hoc error handling already deals with) shows
    // this popup immediately instead of waiting for the next heartbeat tick.
    $(document).ajaxError(function (event, xhr) {
        if (xhr.status === 401 && xhr.responseJSON && xhr.responseJSON.reason && xhr.responseJSON.reason !== 'not_logged_in') {
            showSessionEndedPopup(xhr.responseJSON.reason);
        }
    });
})();
