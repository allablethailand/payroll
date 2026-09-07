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

    function renderContent(active) {
        const content = currentLang === 'en' ? active.content_en : active.content_th;
        // Plain text with real newlines (see the seeded placeholder's own \n usage) -- not HTML,
        // so line breaks need an explicit conversion rather than trusting the browser to render
        // \n inside a plain <div>.
        $('#termsModalContent').html(escapeHtmlTerms(content).replace(/\n/g, '<br>'));
    }

    function renderHistory(rows) {
        if (!rows || !rows.length) {
            $('#termsModalHistory').html('<div class="text-muted small" data-i18n="terms_and_conditions_history_empty">You have not accepted any version yet.</div>');
        } else {
            let html = '<div class="text-muted small fw-semibold mb-1" data-i18n="terms_and_conditions_accepted_on">Accepted on</div><ul class="small text-muted mb-0">';
            rows.forEach(function (r) {
                html += '<li>' + escapeHtmlTerms(r.version_label) + ' — ' + escapeHtmlTerms(r.accepted_at) + '</li>';
            });
            html += '</ul>';
            $('#termsModalHistory').html(html);
        }
        if (typeof applyLanguage === 'function') applyLanguage();
    }

    function setForcedUi(forced) {
        const $modal = $('#termsModal');
        $modal.attr('data-forced', forced ? '1' : '0');
        $modal.find('.terms-modal-close-btn').toggleClass('d-none', forced);
        $modal.find('.terms-modal-footer-forced').toggleClass('d-none', !forced);
        $('#termsModalHistory').toggleClass('d-none', forced);
        $('#btnAcceptTerms').prop('disabled', forced);
    }

    /** @param {boolean} forced */
    function openTermsModal(forced) {
        $.get(`${BASE_URL}/api/terms.get`).done(function (res) {
            if (!res || !res.status || !res.data) return; // no active T&C at all -- nothing to show
            setForcedUi(forced && !res.data.accepted);
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
    $(document).on('scroll', '#termsModalBody', function () {
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
        });
    });
})();
