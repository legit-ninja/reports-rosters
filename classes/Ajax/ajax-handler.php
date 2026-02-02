<?php
/**
 * AJAX Handler
 *
 * Central registration point for WordPress AJAX actions used by the plugin.
 *
 * @package InterSoccer\ReportsRosters\Ajax
 */

namespace InterSoccer\ReportsRosters\Ajax;

use InterSoccer\ReportsRosters\Core\Logger;

defined('ABSPATH') or die('Restricted access');

class AjaxHandler {
    /**
     * @var Logger
     */
    private $logger;

    /**
     * @var RosterAjaxHandler
     */
    private $roster_ajax;

    /**
     * @var AdminToolsAjaxHandler
     */
    private $admin_tools_ajax;

    /**
     * @var RostersTabsAjaxHandler
     */
    private $rosters_tabs_ajax;

    /**
     * @var RosterEditorAjaxHandler
     */
    private $roster_editor_ajax;

    public function __construct(
        Logger $logger = null,
        RosterAjaxHandler $roster_ajax = null,
        AdminToolsAjaxHandler $admin_tools_ajax = null,
        RostersTabsAjaxHandler $rosters_tabs_ajax = null,
        RosterEditorAjaxHandler $roster_editor_ajax = null
    ) {
        $this->logger = $logger ?: new Logger();
        $this->roster_ajax = $roster_ajax ?: new RosterAjaxHandler($this->logger);
        $this->admin_tools_ajax = $admin_tools_ajax ?: new AdminToolsAjaxHandler($this->logger);
        $this->rosters_tabs_ajax = $rosters_tabs_ajax ?: new RostersTabsAjaxHandler();
        $this->roster_editor_ajax = $roster_editor_ajax ?: new RosterEditorAjaxHandler();
    }

    public function init(): void {
        // Core roster admin operations (rebuild/reconcile/upgrade/etc.)
        $this->roster_ajax->register();

        // Admin tools / legacy action-name endpoints
        $this->admin_tools_ajax->register();

        // Roster listing details UI
        $this->rosters_tabs_ajax->register();

        // Roster editor UI endpoints (HTML-heavy; delegated for now)
        $this->roster_editor_ajax->register();
    }
}

