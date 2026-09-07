<?php
declare(strict_types=1);
require_once __DIR__ . '/../models/SetupGuideModel.php';
require_once __DIR__ . '/../models/ChangelogModel.php';
require_once __DIR__ . '/../models/HelpDrawerContentModel.php';

/**
 * 2026-09-05, Backlog Phase 13 -- the new "Help" top-level menu (Setup Guide + Version pages) and
 * the Help Drawer's own content endpoint. No permission gate beyond "logged in" -- all 3 surfaces
 * are read-only/informational for the viewer's own company (Setup Guide) or platform-wide
 * (Version, Help Drawer content), nothing sensitive to restrict further.
 */
class HelpController extends Controller {
    private SetupGuideModel $setupGuideModel;
    private ChangelogModel $changelogModel;
    private HelpDrawerContentModel $drawerModel;

    public function __construct() {
        $this->setupGuideModel = new SetupGuideModel();
        $this->changelogModel = new ChangelogModel();
        $this->drawerModel = new HelpDrawerContentModel();
    }

    public function setupGuide() {
        $this->view('help/setup-guide');
    }

    public function version() {
        $this->view('help/version');
    }

    public function checklist() {
        $compId = (int)getCompId();
        if ($compId <= 0) {
            $this->json(['status' => false, 'message' => 'No company context.']);
            return;
        }
        $this->json(['status' => true, 'data' => $this->setupGuideModel->checklist($compId)]);
    }

    public function changelogList() {
        $this->json(['status' => true, 'data' => $this->changelogModel->list()]);
    }

    /** @query page_key string -- a stable key the frontend already sends per current page/tab
     *  (see help-drawer.js's own PAGE_KEY map). A page_key with no content row is NOT an error --
     *  returns null data, the drawer shows a generic "not written yet" placeholder. */
    public function drawerContent() {
        $pageKey = trim((string)($_GET['page_key'] ?? ''));
        if ($pageKey === '') {
            $this->json(['status' => false, 'message' => 'page_key is required.']);
            return;
        }
        $this->json(['status' => true, 'data' => $this->drawerModel->getByPageKey($pageKey)]);
    }
}
