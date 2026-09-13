// Round 2 item 7b (docs/design/rules.md §10) -- toast, not a blocking modal, per explicit
// confirmation: every EXISTING call site (135 across the app) keeps its exact same 2 calling
// conventions (`showSuccess(msg)` / `showSuccess(msg, true, customTimerMs)`), but the RENDERED
// result is now always a non-blocking top-right toast, never a centered dialog requiring a click --
// this is a deliberate, confirmed override of the historical default (the `timer` param existed
// since 2026-08-29 as an opt-in exception; this makes the opt-in the only behavior).
//
// Auto timer, per explicit rule: a message <=60 chars gets 3000ms with no close button (reads and
// vanishes); a message >60 chars gets 6000ms WITH a close ("x") button, never an "OK" button --
// longer text needs more time and an escape hatch, not a 2nd click to dismiss what was already a
// passive notification. An explicit `timer` argument (7 existing call sites) always wins over this
// auto-decision, unchanged from before. Hovering the toast pauses its countdown (Swal2's own
// documented toast recipe, `Swal.stopTimer`/`Swal.resumeTimer` from `didOpen`) so a long message
// being actively read never vanishes mid-read.
//
// The `confirm` param is kept in the signature ONLY so no existing call site's argument count
// changes -- confirmed via grep that not one of the 135 calls ever passes `false` for it (every
// call either omits it or passes `true` alongside a custom timer), so there is no real behavior it
// still needs to control: a toast never shows a confirm/OK button by design.
//
// Known, deliberately NOT handled here: a small number of call sites (public/js/setup/data-sync.js,
// public/js/setup/origami-sync-widget.js) build a multi-line HTML summary (a <div> plus a <ul> of up
// to 5+ error lines) and pass that whole string in as `msg` -- these are long enough to already fall
// into the 6-second/close-button branch on length alone, so they do not vanish in 3 seconds, but
// they remain a REAL PRE-EXISTING BUG independent of this change: showSuccess()/showError() render
// `msg` via Swal2's `text`/`title` (plain text), never `html`, so that HTML has always displayed as
// literal, visible angle-bracket source text, not a formatted list. Found while auditing every
// showSuccess()/showError() call site's message content for this change -- NOT fixed here, per
// rules.md §0.7 ("phase design ห้ามแก้ logic" -- a rendering/logic bug found during a design pass
// gets logged, not fixed alongside the design work). See BACKLOG.md's own entry for this.
function showSuccess(msg, confirm = true, timer = undefined) {
    const isLong = typeof msg === 'string' && msg.length > 60;
    const effectiveTimer = timer !== undefined ? timer : (isLong ? 6000 : 3000);
    Swal.fire({
        icon: 'success',
        toast: true,
        position: 'top-end',
        title: msg,
        showConfirmButton: false,
        showCloseButton: isLong,
        timer: effectiveTimer,
        timerProgressBar: true,
        didOpen: (toastEl) => {
            toastEl.addEventListener('mouseenter', Swal.stopTimer);
            toastEl.addEventListener('mouseleave', Swal.resumeTimer);
        },
    });
}
// Deliberately UNCHANGED this round -- still a centered, must-acknowledge dialog, not a toast.
// rules.md §10 only calls out success as a toast ("สำเร็จ: toast มุมขวาบน 3 วินาที") -- an error is
// exactly the kind of thing a passive, auto-dismissing corner notification is wrong for (the user
// must not be able to miss it by looking away for 3-6 seconds).
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
// Round 2 item 7b (docs/design/rules.md §10) -- widened to accept an object form ALONGSIDE the
// original positional one, never instead of it: `typeof arg1 === 'object'` is the only branch point,
// so every existing `showConfirm(title, msg, yesCallback, noCallback)` call site (the vast majority
// of this app's ~100+ confirms) is byte-for-byte unaffected -- it's routed through the exact same
// Swal.fire() call as before, just via one shared `opts` object built from the positional arguments
// instead of duplicating the Swal.fire() config twice.
//
// Object form: `showConfirm({title, message, confirmText, cancelText, danger, onYes, onNo})`.
// `cancelText` is a small, deliberate widening beyond rules.md §10's own original wording (which
// only named `confirmText`) -- added because the modal dirty-guard mechanism below (app.js) needs
// its own exact button copy on BOTH sides ("กลับไปแก้ต่อ"/"ปิดโดยไม่บันทึก", not the generic
// yes/no default), and there is nowhere else for that 2nd override to live without it.
// `danger: true` swaps ONLY the confirm button's color to `var(--c-danger)` (style.css's own default
// for every OTHER confirm button is `var(--c-primary)`, see the Swal2 token block there) -- nothing
// else about the dialog changes for danger.
function showConfirm(arg1, arg2, arg3, arg4) {
    const opts = (arg1 !== null && typeof arg1 === 'object')
        ? arg1
        : { title: arg1, message: arg2, onYes: arg3, onNo: arg4 };
    const swalOpts = {
        icon: 'info',
        title: opts.title,
        text: opts.message,
        showCancelButton: true,
        confirmButtonText: opts.confirmText || langData.yes || 'Yes',
        cancelButtonText: opts.cancelText || langData.no || 'No',
    };
    if (opts.danger) {
        swalOpts.confirmButtonColor = 'var(--c-danger)';
    }
    Swal.fire(swalOpts).then(r => {
        if (r.isConfirmed && opts.onYes) opts.onYes();
        if (!r.isConfirmed && opts.onNo) opts.onNo();
    });
}
