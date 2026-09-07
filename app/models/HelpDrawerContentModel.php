<?php
declare(strict_types=1);

/**
 * 2026-09-05, Backlog Phase 13 -- per-page contextual help shown in the Help Drawer (a slide-in
 * right-side panel, one persistent trigger button on every page). Keyed by a stable `page_key`
 * string the frontend already knows for the page/tab it's currently on (see
 * public/js/setup/help-drawer.js's own PAGE_KEY map for the full closed set this round wires up --
 * more can be added later by inserting a row, no code change needed, per this project's own
 * master-table convention). Explicit request confirmed via
 * AskUserQuestion: this round ships the mechanism plus REAL content for ~5 main pages, not full
 * app-wide coverage yet -- a page_key with no matching row simply shows a generic "no help written
 * for this page yet" placeholder (see HelpController::drawerContent()), never an error.
 */
class HelpDrawerContentModel {
    private PDO $db;

    public function __construct(?PDO $pdo = null) {
        $this->db = $pdo ?? Database::getInstance()->pdo;
    }

    public function getByPageKey(string $pageKey): ?array {
        $stmt = $this->db->prepare(
            "SELECT page_key, title_th, title_en, body_th, body_en FROM `help_drawer_content` WHERE page_key = :page_key AND is_active = 1 LIMIT 1"
        );
        $stmt->execute([':page_key' => $pageKey]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }
}
