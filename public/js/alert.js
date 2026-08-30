// 2026-08-29, explicit request: "ตอนกดออก Report สำเร็จ ให้ alert ปิดเองอัตโนมัติ" -- optional `timer`
// (ms) param, undefined by default so every EXISTING showSuccess(...) call across the app keeps
// requiring a manual OK click, unchanged. Only generateReport()'s own success call (app.js) passes
// one. timerProgressBar gives a visible countdown so it doesn't feel like it vanished at random.
function showSuccess(msg, confirm = true, timer = undefined) {
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