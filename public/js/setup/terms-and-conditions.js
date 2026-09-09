/**
 * 2026-09-05, Backlog Phase 13 -- Terms & Conditions: a login-gate forced-scroll modal (versioned,
 * acceptance logged) PLUS the same modal reused, non-forced, from Profile > "Terms and
 * Conditions" to view the current text + this employee's own acceptance history. Loaded globally
 * (every page, via footer.php) since the login-gate check has to run no matter which page the
 * employee lands on first after logging in.
 *
 * Content is PLACEHOLDER text for now (explicit request, confirmed via AskUserQuestion) -- this
 * file only builds the mechanism, it has no opinion on what the text says.
 *
 * `#termsModal`'s own `data-forced` attribute (set right before `.show()`, read by the
 * `hide.bs.modal` guard below) distinguishes the 2 modes -- see modals.php's own docblock on this
 * same modal for the visual differences (Accept button/scroll-hint only shown when forced).
 */
(function () {
    function escapeHtmlTerms(s) {
        return $('<div>').text(s == null ? '' : String(s)).html();
    }

    // Holds whichever version's data is CURRENTLY shown in #termsModalContent (the active version,
    // or an old one picked via .btn-terms-view-version) so a language switch can re-render it
    // in-place without a round trip -- see termsRefreshLanguage() below.
    let lastRenderedData = null;

    function renderContent(active) {
        lastRenderedData = active;
        const content = currentLang === 'en' ? active.content_en : active.content_th;
        // 2026-09-07: content is now real HTML (headings/bold/bullet lists -- see the
        // 2026-09-07_2_terms_and_conditions_draft_content.sql migration's own docblock), not the
        // 2026-09-05 placeholder's plain-text-with-\n -- rendered directly, no escaping. Safe
        // because this table is DB-seeded/admin-authored only (TermsAndConditionsModel's own
        // docblock: no user-input path ever writes to content_th/content_en).
        $('#termsModalContent').html(content || '');
    }

    // 2026-09-09, real bug found and fixed ("ตอนที่ขึ้น Modal ให้กด เปลี่ยนภาษาแล้วไม่ยอมเปลี่ยนตาม"):
    // #termsModalContent is plain server-fetched HTML with no data-i18n attributes at all, so
    // app.js's own applyLanguage() (which changeLanguage() always calls via loadLang()) can never
    // touch it -- every other page with this same shape (Setup Guide checklist, Version changelog,
    // Help Drawer) already has its own `xxxRefreshLanguage()` hook called from changeLanguage(), but
    // this modal never got one when it was built, so its content silently stayed in whichever
    // language was active when the modal was first opened, no matter how many times the language
    // switcher was clicked afterward. Re-renders whatever is currently shown (current version OR an
    // old version being viewed via History) using the SAME data already in memory -- no re-fetch
    // needed, and it's a safe no-op when the modal has never been opened this page load.
    window.termsRefreshLanguage = function () {
        if (lastRenderedData) renderContent(lastRenderedData);
        if (viewingBannerInfo) renderViewingBanner(viewingBannerInfo.versionLabel, viewingBannerInfo.acceptedAt);
    };

    // 2026-09-07, explicit design question answered: "ถ้ามีหลาย Version จะแสดงยังไง...เป็นตารางก่อน
    // แล้วค่อยกดดูข้อความ...ช่วย Design ให้แสดงผลใน modal เดียวครับ รองรับ responsive" -- was a plain
    // <ul> of "label — date" text with no way to see an OLD version's actual content; now a real
    // (`.table-responsive`, so it never breaks the modal's own width on a narrow screen) table with
    // a per-row "View" button that swaps #termsModalContent (see the click handler further down).
    function renderHistory(rows) {
        if (!rows || !rows.length) {
            $('#termsModalHistory').html('<div class="text-muted small" data-i18n="terms_and_conditions_history_empty">You have not accepted any version yet.</div>');
            if (typeof applyLanguage === 'function') applyLanguage();
            return;
        }
        let html = '<div class="text-muted small fw-semibold mb-2" data-i18n="terms_and_conditions_history_title">Version History</div>'
            + '<div class="table-responsive"><table class="table table-sm table-hover align-middle mb-0">'
            + '<thead class="table-light text-secondary"><tr>'
            + '<th data-i18n="terms_and_conditions_col_version">Version</th>'
            + '<th data-i18n="terms_and_conditions_col_effective_date">Effective Date</th>'
            + '<th data-i18n="terms_and_conditions_accepted_on">Accepted on</th>'
            + '<th></th>'
            + '</tr></thead><tbody>';
        rows.forEach(function (r) {
            html += '<tr>'
                + '<td>' + escapeHtmlTerms(r.version_label) + '</td>'
                + '<td>' + (typeof formatDisplayDate === 'function' ? formatDisplayDate(r.effective_date) : escapeHtmlTerms(r.effective_date || '-')) + '</td>'
                + '<td>' + (typeof formatDisplayDateTime === 'function' ? formatDisplayDateTime(r.accepted_at) : escapeHtmlTerms(r.accepted_at || '-')) + '</td>'
                + '<td class="text-end"><button type="button" class="btn btn-link btn-sm p-0 btn-terms-view-version" data-terms-id="' + r.terms_id + '" data-version-label="' + escapeHtmlTerms(r.version_label) + '" data-accepted-at="' + escapeHtmlTerms(r.accepted_at || '') + '" data-i18n="terms_and_conditions_view_version">View</button></td>'
                + '</tr>';
        });
        html += '</tbody></table></div>';
        $('#termsModalHistory').html(html);
        if (typeof applyLanguage === 'function') applyLanguage();
    }
    // Set only while the "viewing an old version" banner is showing (View mode only -- the banner
    // area itself is hidden in the forced login-gate mode, see setForcedUi()) -- holds just enough
    // to REBUILD the banner's sentence from scratch, since it's plain templated text (langData +
    // string substitution), not something applyLanguage()'s data-i18n pass can touch on its own.
    let viewingBannerInfo = null;

    function renderViewingBanner(versionLabel, acceptedAt) {
        const tpl = (typeof langData !== 'undefined' && langData['terms_and_conditions_viewing_version'])
            || 'Viewing version {version} (accepted {date}) -- this is not the current version.';
        const dateText = (typeof formatDisplayDateTime === 'function') ? formatDisplayDateTime(acceptedAt) : acceptedAt;
        $('#termsModalViewingBannerText').text(tpl.replace('{version}', versionLabel).replace('{date}', dateText));
    }

    function showCurrentVersionInModal() {
        $.get(`${BASE_URL}/api/terms.get`).done(function (res) {
            if (res && res.status && res.data) renderContent(res.data);
        });
        viewingBannerInfo = null;
        $('#termsModalViewingBanner').addClass('d-none');
    }
    $(document).on('click', '.btn-terms-view-version', function () {
        const termsId = $(this).data('terms-id');
        const versionLabel = $(this).data('version-label');
        const acceptedAt = $(this).data('accepted-at');
        $.get(`${BASE_URL}/api/terms.version`, { id: termsId }).done(function (res) {
            if (!res || !res.status || !res.data) return;
            renderContent(res.data);
            viewingBannerInfo = { versionLabel: versionLabel, acceptedAt: acceptedAt };
            renderViewingBanner(versionLabel, acceptedAt);
            $('#termsModalViewingBanner').removeClass('d-none');
            document.getElementById('termsModalBody').scrollTop = 0;
        });
    });
    $(document).on('click', '#btnTermsBackToCurrent', showCurrentVersionInModal);

    function setForcedUi(forced) {
        const $modal = $('#termsModal');
        $modal.attr('data-forced', forced ? '1' : '0');
        $modal.find('.terms-modal-close-btn').toggleClass('d-none', forced);
        $modal.find('.terms-modal-footer-forced').toggleClass('d-none', !forced);
        $modal.find('.terms-modal-footer-view').toggleClass('d-none', forced);
        $('#termsModalHistory').toggleClass('d-none', forced);
        $('#btnAcceptTerms').prop('disabled', forced);
    }

    /** @param {boolean} forced */
    function openTermsModal(forced) {
        $.get(`${BASE_URL}/api/terms.get`).done(function (res) {
            if (!res || !res.status || !res.data) return; // no active T&C at all -- nothing to show
            setForcedUi(forced && !res.data.accepted);
            viewingBannerInfo = null; // reset any "viewing an old version" state from a previous open
            $('#termsModalViewingBanner').addClass('d-none');
            renderContent(res.data);
            if (!forced) {
                $.get(`${BASE_URL}/api/terms.history`).done(function (histRes) {
                    if (histRes && histRes.status) renderHistory(histRes.data);
                });
            }
            const modalEl = document.getElementById('termsModal');
            bootstrap.Modal.getOrCreateInstance(modalEl, { backdrop: 'static', keyboard: false }).show();
        });
    }

    $(document).ready(function () {
        // Login-gate check -- fires on every page load; a no-op (res.data.accepted === true, or no
        // active T&C at all) the overwhelming majority of the time.
        $.get(`${BASE_URL}/api/terms.get`).done(function (res) {
            if (res && res.status && res.data && !res.data.accepted) {
                openTermsModal(true);
            }
        });
    });

    $(document).on('click', '#btnOpenTermsView', function () {
        openTermsModal(false);
    });

    // Detects reaching the bottom of the scrollable modal body -- .modal-dialog-scrollable makes
    // .modal-body itself the scrolling element, not the whole modal or the window.
    // 2026-09-09, real bug found and fixed ("Switch เข้ามาเจอให้อ่าน แต่กดยอมรับแล้วไม่ได้"): this was
    // a DELEGATED handler ($(document).on('scroll', '#termsModalBody', ...)), which never fired at
    // all -- the DOM `scroll` event does NOT bubble (per spec), so delegating it from an ancestor
    // (document) can never catch it; only a listener bound DIRECTLY to the scrolling element itself
    // sees it. Whenever the T&C text was long enough to actually require scrolling (the whole point
    // of this gate), the scroll-to-bottom re-enable logic silently never ran -- #btnAcceptTerms
    // stayed disabled forever no matter how far the employee scrolled, on a forced modal with no
    // other way to close it. The shown.bs.modal handler below (short-content, no-scroll-needed case)
    // masked this in quick manual smoke-testing with brief placeholder text. Fixed by binding
    // directly to #termsModalBody (safe: modals.php is included before this script tag in
    // footer.php, so the element already exists in the DOM when this file runs) instead of
    // delegating from document.
    $('#termsModalBody').on('scroll', function () {
        if ($('#termsModal').attr('data-forced') !== '1') return;
        const el = this;
        if (el.scrollTop + el.clientHeight >= el.scrollHeight - 4) {
            $('#btnAcceptTerms').prop('disabled', false);
            $('#termsModalScrollHint').addClass('d-none');
        }
    });

    // A short/empty-scrollbar case (the placeholder text may not even need scrolling on a tall
    // screen) -- check once right after the modal is fully shown too, not just on a scroll event
    // that may never fire.
    $(document).on('shown.bs.modal', '#termsModal', function () {
        if ($(this).attr('data-forced') !== '1') return;
        const el = document.getElementById('termsModalBody');
        if (el.scrollHeight <= el.clientHeight + 4) {
            $('#btnAcceptTerms').prop('disabled', false);
            $('#termsModalScrollHint').addClass('d-none');
        } else {
            $('#termsModalScrollHint').removeClass('d-none');
        }
    });

    // Blocks EVERY close attempt (backdrop click, Esc, or any stray dismiss trigger) while still
    // forced -- the only legitimate way out is the Accept handler below, which flips data-forced
    // back to '0' itself right before calling .hide(), so this guard never blocks that one.
    $(document).on('hide.bs.modal', '#termsModal', function (e) {
        if ($(this).attr('data-forced') === '1') {
            e.preventDefault();
        }
    });

    $(document).on('click', '#btnAcceptTerms', function () {
        const $btn = $(this);
        $btn.prop('disabled', true);
        $.post(`${BASE_URL}/api/terms.accept`).done(function (res) {
            if (res && res.status) {
                $('#termsModal').attr('data-forced', '0');
                bootstrap.Modal.getInstance(document.getElementById('termsModal'))?.hide();
            } else {
                $btn.prop('disabled', false);
                if (typeof showError === 'function') showError(res && res.message ? res.message : 'Failed to save.');
            }
        }).fail(function (xhr) {
            // Was previously silent on a non-2xx response (a dead/killed session, a suspended
            // account, a transport error) -- the button just stayed disabled forever with zero
            // feedback on a forced, un-closeable modal. A 401 with reason timeout/superseded is
            // still separately caught by session-guard.js's own global ajaxError handler (shows its
            // own "Session Ended" popup + redirect), so don't double up on that one; everything else
            // gets re-enabled + a visible error here instead of leaving the employee stuck.
            const reason = xhr && xhr.responseJSON && xhr.responseJSON.reason;
            if (xhr && xhr.status === 401 && reason && reason !== 'not_logged_in') return;
            $btn.prop('disabled', false);
            const msg = (xhr && xhr.responseJSON && xhr.responseJSON.message) || 'Failed to save.';
            if (typeof showError === 'function') showError(msg);
        });
    });
})();
