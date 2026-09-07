/**
 * Canvas Designer Core -- shared, genuinely stateless/pure utilities between Payslip Template and
 * Employment Certificate Template's own canvas designers (public/js/setup/payslip-template.js /
 * employment-certificate-template.js). 2026-09-04, Backlog Phase 11, T064.
 *
 * SCOPE NOTE, read before adding anything else here: this file intentionally holds ONLY genuinely
 * pure/stateless code -- shared constants and functions with no dependency on either designer's own
 * closure-scoped mutable state (elements/selectedKeys/undoStack/redoStack/dirty) or hardcoded
 * "#pst" / "#ect" prefixed DOM ids. A T064 audit found ~50 more functions with matching names across both files
 * (selection, drag, undo/redo, layers-panel rendering, zoom/pan DOM manipulation, image library,
 * Insert Table/Shape/Symbol) that LOOK like the same kind of duplication but are NOT safe to extract
 * the same way -- confirmed by reading their actual bodies, not assumed: they are deeply coupled to
 * each file's own private state and to each other (e.g. selectElement() reads/writes `elements`/
 * `selectedKeys` and calls renderLayersPanel(), which itself reads `selectedKeys` again). A correct
 * extraction of that stateful layer would require redesigning both designers around a shared
 * per-instance state object (likely 300-500+ call-site changes across ~5000 combined lines), which
 * cannot be safely verified without live browser interaction testing -- a standing limitation this
 * project already has for canvas interaction code (see CLAUDE.md's own repeated "verified via
 * node --check + reading the code path, not by clicking through a real browser" caveat on this exact
 * designer pair). That larger consolidation is deliberately NOT attempted here -- it needs its own
 * dedicated, carefully scoped, live-tested effort, not an opportunistic addition to this pass.
 *
 * What IS extracted here is real, verified duplication with a genuine, already-documented risk: the
 * PAGE_SIZES_MM constant in particular carries an explicit comment in both original files reading
 * "MUST stay byte-identical to PayslipTemplateRenderer::PAGE_SIZES_MM ... easy to miss since it's a
 * silent client-side duplicate" -- keeping TWO separate JS copies in sync by hand is exactly the kind
 * of drift risk a shared module removes structurally, not just a line-count/DRY nicety.
 *
 * This file follows the SAME "no bare global pollution" discipline the IIFE wrapping on both caller
 * files was built to enforce (see those files' own docblocks for the real, shipped
 * `SyntaxError: Identifier 'currentTemplate' has already been declared` bug this project hit once
 * from skipping that discipline) -- everything here lives inside its own IIFE and the only thing
 * that reaches the global scope is the single `window.CanvasDesignerCore` namespace object. Loaded
 * BEFORE payslip-template.js/employment-certificate-template.js in app/views/payslip/settings.php.
 */
(function () {
    // 2026-08-25/26 origin comment (both original files carried this verbatim): "ตรง Page Setup ให้
    // เพิ่ม A3 A5 และอื่นๆ เหมือนใน Word" -- MUST stay byte-identical to
    // PayslipTemplateRenderer::PAGE_SIZES_MM / EmploymentCertificateRenderer::PAGE_SIZES_MM (the
    // canvas and the PDF renderer share this exact coordinate space for true WYSIWYG, no unit
    // conversion anywhere).
    const PAGE_SIZES_MM = {
        A3: [297, 420], A4: [210, 297], A5: [148, 210], B4: [250, 353], B5: [176, 250],
        Letter: [215.9, 279.4], Legal: [215.9, 355.6], Tabloid: [279.4, 431.8],
        Executive: [184.15, 266.7], Statement: [139.7, 215.9],
    };
    // Word's own Page Layout > Margins preset names/values (both files' own origin comment).
    const MARGIN_PRESETS = [
        { code: 'narrow', mm: 8, labelKey: 'ect_margin_narrow' },
        { code: 'normal', mm: 15, labelKey: 'ect_margin_normal' },
        { code: 'moderate', mm: 20, labelKey: 'ect_margin_moderate' },
        { code: 'wide', mm: 30, labelKey: 'ect_margin_wide' },
    ];
    const ZOOM_LEVELS = [25, 50, 75, 100, 125, 150, 200, 300];
    const FONT_FAMILY_CSS_STACK = {
        th_sarabun_new: "'TH Sarabun New', sans-serif",
        dejavu_sans: "'DejaVu Sans', sans-serif",
        dejavu_sans_mono: "'DejaVu Sans Mono', monospace",
        dejavu_serif: "'DejaVu Serif', serif",
        helvetica: "Helvetica, Arial, sans-serif",
        times_new_roman: "'Times New Roman', Times, serif",
        courier: "'Courier New', Courier, monospace",
    };

    /** [widthMm, heightMm] for a page size + orientation, swapping the pair for landscape. */
    function pageDimensionsMm(pageSize, orientation) {
        const dims = PAGE_SIZES_MM[pageSize] || PAGE_SIZES_MM.A4;
        return orientation === 'landscape' ? [dims[1], dims[0]] : [dims[0], dims[1]];
    }
    function clamp(v, min, max) {
        if (max < min) max = min;
        return Math.max(min, Math.min(max, v));
    }
    /** Opaque, collision-safe id for a canvas element group -- not a DB foreign key, just a shared
     *  tag; elements sharing one group_key are one group (see either designer's own docblock). */
    function generateGroupKey() {
        return 'grp_' + Date.now() + '_' + Math.random().toString(36).slice(2, 8);
    }
    /** FA icon class for a Layers-panel row, given one element's own element_type. */
    function layerIcon(el) {
        return el.element_type === 'image' ? 'fa-image' : 'fa-font';
    }
    /** Deep clone via JSON round-trip -- used by both designers' own undo/redo stacks. */
    function cloneElementsList(arr) {
        return JSON.parse(JSON.stringify(arr));
    }

    window.CanvasDesignerCore = {
        PAGE_SIZES_MM: PAGE_SIZES_MM,
        MARGIN_PRESETS: MARGIN_PRESETS,
        ZOOM_LEVELS: ZOOM_LEVELS,
        FONT_FAMILY_CSS_STACK: FONT_FAMILY_CSS_STACK,
        pageDimensionsMm: pageDimensionsMm,
        clamp: clamp,
        generateGroupKey: generateGroupKey,
        layerIcon: layerIcon,
        cloneElementsList: cloneElementsList,
    };
})();
