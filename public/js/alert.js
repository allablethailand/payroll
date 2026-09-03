// 2026-08-29, explicit request: "ตอนกดออก Report สำเร็จ ให้ alert ปิดเองอัตโนมัติ" -- optional `timer`
// (ms) param, undefined by default so every EXISTING showSuccess(...) call across the app keeps
// requiring a manual OK click, unchanged. Only generateReport()'s own success call (app.js) passes
// one. timerProgressBar gives a visible countdown so it doesn't feel like it vanished at random.
function showSuccess(msg, confirm = true, timer = undefined) {
    // 2026-09-03, Platform Hardening Phase 1.2 -- the generic modal dirty-check (public/js/app.js,
    // near snapshotFormState()) reads this timestamp to tell "modal is closing because a save just
    // succeeded" apart from "user is abandoning changes" -- every sampled Save-success handler in
    // this app calls showSuccess() synchronously, immediately before closing its modal (verified via
    // a codebase-wide audit before adding this), so this one line covers that signal for every one
    // of them without touching each individual handler's own .hide() call.
    if (typeof __lastSuccessToastAt !== 'undefined') {
        __lastSuccessToastAt = Date.now();
    }
    Swal.fire({
        icon: 'success',
        title: langData.success || 'Success',
        text: msg,
        showConfirmButton: confirm,
        confirmButtonText: langData.ok || 'OK',
        timer: timer,
        timerProgressBar: !!timer,
    });
}
function showError(msg, confirm = true) {
    Swal.fire({
        icon: 'error',
        title: langData.error || 'Error',
        text: msg,
        showConfirmButton: confirm,
        confirmButtonText: langData.ok || 'OK'
    });
}
function showWarning(msg, confirm = true) {
    Swal.fire({
        icon: 'warning',
        title: langData.warning || 'Warning',
        text: msg,
        showConfirmButton: confirm,
        confirmButtonText: langData.ok || 'OK'
    });
}
function showConfirm(title, msg, yes, no) {
    Swal.fire({
        icon: 'info',
        title,
        text: msg,
        showCancelButton: true,
        confirmButtonText: langData.yes || 'Yes',
        cancelButtonText: langData.no || 'No'
    }).then(r => {
        if (r.isConfirmed && yes) yes();
        if (!r.isConfirmed && no) no();
    });
}