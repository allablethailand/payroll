function showSuccess(msg, confirm = true) {
    Swal.fire({
        icon: 'success',
        title: langData.success || 'Success',
        text: msg,
        showConfirmButton: confirm,
        confirmButtonText: langData.ok || 'OK'
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