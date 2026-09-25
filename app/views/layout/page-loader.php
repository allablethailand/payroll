<?php
// design:clean -- docs/design/rules.md §10/§11, Phase Design Round 3 item 3c-1 follow-up.
/**
 * Full-page loading overlay ("Tier 2" -- see style.css's own .om-loader/.om-page-loader comment
 * for the small in-place "Tier 1" spinner this is deliberately NOT the same as). Used ONLY for a
 * page's genuine main-content load (first paint, route change, main data not ready yet) -- never
 * for an in-page action (save/table reload/modal open use a button spinner or .table-loading, §10).
 *
 * Rendered ONCE per page, hidden by default (`d-none`) -- app.js's showPageLoader()/
 * hidePageLoader() toggle it (adding/removing `d-none` plus the appear-delay/fade-out timing, see
 * those functions' own docblock), never rebuild this markup per call. Included unconditionally in
 * footer.php, same convention as modals.php right above that include -- id-based lookup, DOM
 * position doesn't matter.
 */
?>
<div id="omPageLoader" class="om-page-loader d-none" aria-hidden="true">
    <div class="om-page-loader-stage">
        <div class="om-page-loader-ring"></div>
        <img class="om-page-loader-logo" src="<?=BASE_URL?>/public/images/origami_logo.png" alt="">
    </div>
    <div class="om-page-loader-text" data-i18n="processing">Loading...</div>
</div>
