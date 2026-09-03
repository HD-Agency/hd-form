<?php
/**
 * Form Entries Page
 *
 * @package HDForm\Admin
 */

declare(strict_types=1);

namespace HDForm\Admin;

use HDForm\FormConfig;
use HDForm\Repository\EntryCountCache;
use HDForm\Repository\FormEntryRepository;
use HDForm\Repository\WorkflowHistoryRepository;
use HDForm\Compat\Helper;
use HDForm\Plugin;

defined( 'ABSPATH' ) || exit;

class FormEntriesPage {

	/**
	 * Register admin menu and styles.
	 */
	public static function register(): void {
		add_action( 'admin_menu', [ self::class, 'addMenuPage' ], 30 );
		add_action( 'admin_enqueue_scripts', [ self::class, 'enqueueScripts' ] );
		add_action( 'admin_head', [ self::class, 'printAdminStyles' ] );
		add_filter( 'set-screen-option', [ self::class, 'saveScreenOption' ], 10, 3 );
		add_filter( 'manage_toplevel_page_hd-form-entries_columns', [ self::class, 'reorderListColumns' ], 99 );
		add_filter( 'default_hidden_columns', [ self::class, 'defaultHiddenColumns' ], 10, 2 );
	}

	/**
	 * Enqueue scripts for Form Entries admin pages.
	 */
	public static function enqueueScripts( string $hook ): void {
		if ( ! str_contains( $hook, 'hd-form-entries' ) ) {
			return;
		}

		if ( ! FormConfig::hasWorkflowStatuses() ) {
			return;
		}

		$wfConfigJson = wp_json_encode(
			[
				'statuses' => array_values( FormConfig::getWorkflowStatuses() ),
				'nonce'    => wp_create_nonce( 'wp_rest' ),
				'restUrl'  => esc_url_raw( rest_url( HD_FORM_REST_NAMESPACE . '/form-entries/' ) ),
			]
		);

		$script = sprintf(
			'window.hdWfConfig=%s;(function(){if(!window.hdWfConfig)return;var cfg=window.hdWfConfig;var popEl=null,activeBtn=null,noteInput=null,itemMap={};function initPopover(){if(popEl)return;popEl=document.createElement("div");popEl.className="hd-wf-popover";popEl.style.display="none";var header=document.createElement("div");header.className="hd-wf-popover-header";header.textContent="Set Workflow Status";popEl.appendChild(header);var list=document.createElement("ul");list.className="hd-wf-popover-list";(cfg.statuses||[]).forEach(function(st){var li=document.createElement("li");li.className="hd-wf-popover-item";li.dataset.slug=st.slug;var dot=document.createElement("span");dot.className="hd-wf-popover-dot";dot.style.background=st.color||"#a7aaad";li.appendChild(dot);var label=document.createElement("span");label.textContent=st.label||st.slug;li.appendChild(label);li.addEventListener("click",function(e){e.stopPropagation();if(activeBtn){updateStatus(activeBtn,st.slug,st.label,st.color);}});list.appendChild(li);itemMap[st.slug]=li;});var clearLi=document.createElement("li");clearLi.className="hd-wf-popover-item hd-wf-popover-item--clear";clearLi.textContent="Clear Status";clearLi.addEventListener("click",function(e){e.stopPropagation();if(activeBtn){updateStatus(activeBtn,"","Set Status","");}});list.appendChild(clearLi);popEl.appendChild(list);var noteWrap=document.createElement("div");noteWrap.className="hd-wf-popover-note-wrap";noteInput=document.createElement("input");noteInput.type="text";noteInput.className="hd-wf-popover-note-input";noteInput.placeholder="Add note (optional)...";noteWrap.appendChild(noteInput);popEl.appendChild(noteWrap);document.body.appendChild(popEl);}function closePopover(){if(popEl){popEl.style.display="none";activeBtn=null;}}function openPopover(btn){initPopover();if(activeBtn===btn){closePopover();return;}activeBtn=btn;var currentSlug=btn.dataset.currentSlug||"";noteInput.value="";Object.keys(itemMap).forEach(function(s){itemMap[s].classList.toggle("hd-wf-popover-item--active",s===currentSlug);});var rect=btn.getBoundingClientRect();var top=window.scrollY+rect.bottom+6;var left=window.scrollX+Math.min(rect.left,window.innerWidth-240);popEl.style.top=top+"px";popEl.style.left=left+"px";popEl.style.display="block";}document.addEventListener("click",function(e){var btn=e.target.closest(".hd-workflow-picker-btn");if(btn){e.preventDefault();e.stopPropagation();openPopover(btn);return;}if(popEl&&popEl.style.display!=="none"&&!popEl.contains(e.target)){closePopover();}});window.addEventListener("scroll",closePopover,{passive:true});window.addEventListener("resize",closePopover,{passive:true});function updateStatus(btn,slug,label,color){var entryId=btn.dataset.entryId;btn.style.opacity="0.5";btn.disabled=true;fetch(cfg.restUrl+entryId+"/workflow-status",{method:"POST",headers:{"X-WP-Nonce":cfg.nonce,"Content-Type":"application/json"},body:JSON.stringify({workflow_status:slug,note:noteInput.value||""})}).then(function(res){return res.json();}).then(function(res){btn.style.opacity="1";btn.disabled=false;if(res.success||res.updated||res.id||res.message===undefined){btn.dataset.currentSlug=slug;if(slug===""){btn.className="hd-workflow-picker-btn hd-workflow-picker-btn--empty";btn.innerHTML=\'<span class="dashicons dashicons-plus-alt2" style="font-size:12px;width:12px;height:12px;line-height:12px;margin-right:2px;"></span><span class="hd-wf-label">Set Status</span>\';}else{btn.className="hd-workflow-picker-btn";btn.innerHTML=\'<span class="hd-wf-dot" style="background:\'+(color||"#a7aaad")+\';"></span><span class="hd-wf-label">\'+escapeHtml(label)+\'</span>\';}}closePopover();}).catch(function(){btn.style.opacity="1";btn.disabled=false;closePopover();});}function escapeHtml(str){var div=document.createElement("div");div.textContent=str;return div.innerHTML;}})();',
			$wfConfigJson
		);

		wp_register_script( 'hd-form-admin-entries', '', [ 'jquery' ], HD_FORM_VERSION, true );
		wp_add_inline_script( 'hd-form-admin-entries', $script );
		wp_enqueue_script( 'hd-form-admin-entries' );
	}

	/**
	 * Print scoped CSS for form-entries admin pages.
	 */
	public static function printAdminStyles(): void {
		$screen = get_current_screen();
		if ( ! $screen || ! str_contains( $screen->id, 'hd-form-entries' ) ) {
			return;
		}

		?>
		<style>
			/* Wider metadata sidebar */
			.hd-form-entries #post-body.columns-2 { margin-right: 360px; }
			.hd-form-entries #postbox-container-1 { width: 340px; margin-right: -360px; }

			/* Status badges */
			.hd-entry-status { display:inline-block; padding:4px 10px; border-radius:99px; font-size:11px; font-weight:600; line-height:1.4; }
			.hd-entry-status--new { background:#d63638; color:#fff; }
			.hd-entry-status--read { background:#f0f0f1; color:#3c434a; }
			.hd-entry-status--starred { background:#2271b1; color:#fff; }
			.hd-entry-status--spam { background:#dba617; color:#fff; }
			.hd-entry-status--trash { background:#787c82; color:#fff; }

			/* UTM separator */
			.hd-meta-separator { margin:12px 0; border:0; border-top:1px solid #dcdcde; }

			/* Workflow Picker Buttons */
			.hd-workflow-picker-btn { display:inline-flex; align-items:center; gap:6px; padding:4px 8px; margin:-4px -6px; border-radius:6px; font-size:13px; font-weight:700; line-height:1.4; background:transparent; color:#0f172a; border:0; cursor:pointer; transition:all 0.15s cubic-bezier(0.16,1,0.3,1); text-align:left; max-width:100%; box-shadow:none; }
			.hd-workflow-picker-btn:hover { background:#f1f5f9; color:var(--wp-admin-theme-color,#2271b1); }
			.hd-workflow-picker-btn .hd-wf-dot { width:8px; height:8px; border-radius:50%; flex-shrink:0; display:inline-block; }
			.hd-workflow-picker-btn .hd-wf-label { overflow:hidden; text-overflow:ellipsis; white-space:nowrap; font-weight:700; }
			.hd-workflow-picker-btn--empty { color:#64748b; font-weight:600; font-size:12px; border:1px dashed #cbd5e1; background:#f8fafc; padding:3px 8px; border-radius:6px; transition:all 0.15s ease; }
			.hd-workflow-picker-btn--empty:hover { background:#eff6ff; border-color:#93c5fd; color:#2563eb; }
			.hd-workflow-picker-btn--orphan { font-style:italic; opacity:.85; }

			/* Workflow Quick Popover */
			.hd-wf-popover { position:absolute; z-index:99999; width:230px; background:rgba(255,255,255,0.96); backdrop-filter:blur(16px); -webkit-backdrop-filter:blur(16px); border-radius:12px; box-shadow:0 20px 25px -5px rgba(15,23,42,0.12),0 8px 10px -6px rgba(15,23,42,0.06); border:1px solid #e2e8f0; padding:10px; animation:hd-popover-scale 0.18s cubic-bezier(0.16,1,0.3,1); }
			@keyframes hd-popover-scale { from { opacity:0; transform:scale(0.94) translateY(-4px); } to { opacity:1; transform:scale(1) translateY(0); } }
			.hd-wf-popover-header { font-size:11px; font-weight:800; color:#64748b; text-transform:uppercase; letter-spacing:0.8px; padding:4px 8px 8px; border-bottom:1px solid #f1f5f9; margin-bottom:6px; }
			.hd-wf-popover-list { list-style:none; margin:0; padding:0; max-height:220px; overflow-y:auto; }
			.hd-wf-popover-item { display:flex; align-items:center; gap:8px; padding:6px 10px; border-radius:8px; font-size:13px; color:#334155; cursor:pointer; transition:all 0.12s ease; }
			.hd-wf-popover-item:hover { background:#f1f5f9; color:#0f172a; }
			.hd-wf-popover-item--active { background:#f0f6fc; color:var(--wp-admin-theme-color,#2271b1); font-weight:600; }
			.hd-wf-popover-dot { width:8px; height:8px; border-radius:50%; flex-shrink:0; }
			.hd-wf-popover-item--clear { color:#94a3b8; border-top:1px solid #f1f5f9; margin-top:6px; padding-top:8px; }
			.hd-wf-popover-note-wrap { margin-top:8px; padding-top:8px; border-top:1px solid #f1f5f9; }
			.hd-wf-popover-note-input { width:100%; box-sizing:border-box; font-size:12px; padding:5px 9px; border:1px solid #cbd5e1; border-radius:6px; transition:all 0.15s ease; }
			.hd-wf-popover-note-input:focus { outline:none; border-color:var(--wp-admin-theme-color,#2271b1); box-shadow:0 0 0 3px rgba(34,113,177,0.12); }

			/* Workflow Timeline */
			.hd-workflow-timeline { list-style:none; margin:0; padding:0; position:relative; }
			.hd-workflow-timeline::before { content:''; position:absolute; left:8px; top:4px; bottom:4px; width:2px; background:#e2e8f0; }
			.hd-workflow-timeline .timeline-item { position:relative; padding:0 0 16px 30px; }
			.hd-workflow-timeline .timeline-item:last-child { padding-bottom:0; }
			.hd-workflow-timeline .timeline-dot { position:absolute; left:3px; top:4px; width:12px; height:12px; border-radius:50%; border:2px solid #fff; box-shadow:0 0 0 2px rgba(34,113,177,0.15); background:#2271b1; }
			.hd-workflow-timeline .timeline-status { display:block; font-size:12px; font-weight:700; color:#0f172a; margin-bottom:2px; }
			.hd-workflow-timeline .timeline-note { margin:3px 0 5px; font-size:12px; color:#334155; background:#f8fafc; padding:6px 10px; border-radius:6px; border:1px solid #f1f5f9; white-space:pre-wrap; }
			.hd-workflow-timeline .timeline-meta { margin:0; font-size:11px; color:#64748b; }
			.hd-workflow-timeline .timeline-item--orphan .timeline-status { font-style:italic; color:#64748b; }
		</style>
		<?php
	}

	public static function addMenuPage(): void {
		// Only users who can actually see the menu pay for the badge query.
		$badge = '';
		if ( current_user_can( Plugin::CAP_VIEW_ENTRIES ) ) {
			$unread = EntryCountCache::unread( new FormEntryRepository() );
			$badge  = $unread > 0 ? sprintf( ' <span class="update-plugins count-%d"><span class="plugin-count">%d</span></span>', $unread, $unread ) : '';
		}

		$hookSuffix = add_menu_page(
			__( 'Form Entries', 'hd-form' ),
			__( 'Form Entries', 'hd-form' ) . $badge,
			Plugin::CAP_VIEW_ENTRIES,
			'hd-form-entries',
			[ self::class, 'renderPage' ],
			'dashicons-feedback',
			30
		);

		add_submenu_page(
			'hd-form-entries',
			__( 'All Entries', 'hd-form' ),
			__( 'All Entries', 'hd-form' ),
			Plugin::CAP_VIEW_ENTRIES,
			'hd-form-entries',
			[ self::class, 'renderPage' ]
		);

		if ( is_string( $hookSuffix ) && '' !== $hookSuffix ) {
			add_action( "load-{$hookSuffix}", [ self::class, 'loadScreenOptions' ] );
		}
	}

	/**
	 * Configure Screen Options when viewing form-entries page.
	 */
	public static function loadScreenOptions(): void {
		$screen = get_current_screen();
		if ( ! $screen ) {
			return;
		}

		add_screen_option(
			'per_page',
			[
				'label'   => __( 'Entries per page', 'hd-form' ),
				'default' => 20,
				'option'  => 'hd_form_entries_per_page',
			]
		);
	}

	/**
	 * Save per_page screen option for hd-form-entries.
	 */
	public static function saveScreenOption( mixed $status, string $option, mixed $value ): mixed {
		if ( 'hd_form_entries_per_page' === $option ) {
			return absint( $value );
		}

		return $status;
	}

	/**
	 * Ensure Workflow column always appears right after ID column and columns are supplied for Screen Options.
	 *
	 * @param array<string, string> $columns Screen columns.
	 *
	 * @return array<string, string>
	 */
	public static function reorderListColumns( array $columns = [] ): array {
		if ( empty( $columns ) ) {
			$columns = FormEntriesListTable::getColumns();
		}

		if ( ! FormConfig::hasWorkflowStatuses() ) {
			unset( $columns['workflow'] );
			return $columns;
		}

		if ( ! isset( $columns['workflow'] ) ) {
			$columns['workflow'] = __( 'Workflow', 'hd-form' );
		}

		$workflow = $columns['workflow'];
		unset( $columns['workflow'] );

		$reordered = [];
		foreach ( $columns as $key => $title ) {
			$reordered[ $key ] = $title;
			if ( 'id' === $key ) {
				$reordered['workflow'] = $workflow;
			}
		}

		return $reordered;
	}

	/**
	 * Set default hidden columns for Form Entries (Phone column hidden by default).
	 *
	 * @param array<int, string> $hidden Default hidden column slugs.
	 * @param \WP_Screen         $screen Current admin screen.
	 *
	 * @return array<int, string>
	 */
	public static function defaultHiddenColumns( array $hidden, \WP_Screen $screen ): array {
		if ( 'toplevel_page_hd-form-entries' === $screen->id ) {
			$hidden[] = 'phone';
		}

		return array_values( array_unique( $hidden ) );
	}

	public static function renderPage(): void {
		if ( ! current_user_can( Plugin::CAP_VIEW_ENTRIES ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to access this page.', 'hd-form' ) );
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$action = isset( $_GET['action'] ) ? sanitize_text_field( wp_unslash( $_GET['action'] ) ) : '';

		if ( 'view' === $action && ! empty( $_GET['entry'] ) ) {
			self::renderViewPage( absint( wp_unslash( $_GET['entry'] ) ) );
			return;
		}

		$listTable = new FormEntriesListTable();
		$listTable->prepare_items();

		echo '<div class="wrap">';
		echo '<h1 class="wp-heading-inline">' . esc_html__( 'Form Entries', 'hd-form' ) . '</h1>';

		// Export buttons — carry current filters.
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$exportParams = [
			'action'   => 'hd_export_form_entries',
			'_wpnonce' => wp_create_nonce( 'hd_export_entries' ),
		];
		if ( ! empty( $_REQUEST['status'] ) ) {
			$exportParams['status'] = sanitize_text_field( wp_unslash( $_REQUEST['status'] ) );
		}
		if ( ! empty( $_REQUEST['form_type'] ) ) {
			$exportParams['form_type'] = sanitize_text_field( wp_unslash( $_REQUEST['form_type'] ) );
		}
		if ( ! empty( $_REQUEST['workflow_status'] ) && FormConfig::hasWorkflowStatuses() ) {
			$exportParams['workflow_status'] = sanitize_key( wp_unslash( $_REQUEST['workflow_status'] ) );
		}
		if ( ! empty( $_REQUEST['s'] ) ) {
			$exportParams['s'] = sanitize_text_field( wp_unslash( $_REQUEST['s'] ) );
		}

		$xlsxUrl = add_query_arg( array_merge( $exportParams, [ 'export_format' => 'xlsx' ] ), admin_url( 'admin-post.php' ) );
		$csvUrl  = add_query_arg( array_merge( $exportParams, [ 'export_format' => 'csv' ] ), admin_url( 'admin-post.php' ) );

		echo '<a href="' . esc_url( $xlsxUrl ) . '" class="page-title-action">' . esc_html__( 'Export XLSX', 'hd-form' ) . '</a>';
		echo '<a href="' . esc_url( $csvUrl ) . '" class="page-title-action">' . esc_html__( 'Export CSV', 'hd-form' ) . '</a>';
		echo '<hr class="wp-header-end" />';

		$listTable->views();

		echo '<form method="get">';
		echo '<input type="hidden" name="page" value="' . esc_attr( isset( $_REQUEST['page'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['page'] ) ) : '' ) . '">';
		// phpcs:enable

		$listTable->search_box( __( 'Search', 'hd-form' ), 'search_id' );
		$listTable->display();

		echo '</form>';
		echo '</div>';
	}

	private static function renderViewPage( int $id ): void {
		if ( ! current_user_can( Plugin::CAP_VIEW_ENTRIES ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to access this page.', 'hd-form' ) );
		}

		$repo  = new FormEntryRepository();
		$entry = $repo->findById( $id );

		if ( ! $entry ) {
			echo '<div class="wrap"><p>' . esc_html__( 'Entry not found.', 'hd-form' ) . '</p></div>';
			return;
		}

		// Handle notes save.
		if ( 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? '' )
			&& ! empty( $_POST['hd_entry_notes_nonce'] )
			&& wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['hd_entry_notes_nonce'] ) ), 'hd_save_entry_notes_' . $id )
		) {
			if ( ! current_user_can( Plugin::CAP_VIEW_ENTRIES ) ) {
				wp_die( esc_html__( 'Sorry, you are not allowed to edit this entry.', 'hd-form' ) );
			}

			$notes = sanitize_textarea_field( wp_unslash( $_POST['entry_notes'] ?? '' ) );
			$repo->updateNotes( $id, $notes );
			$entry['notes'] = $notes;

			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Notes saved.', 'hd-form' ) . '</p></div>';
		}

		// Handle workflow status save.
		if ( FormConfig::hasWorkflowStatuses()
			&& 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? '' )
			&& ! empty( $_POST['hd_workflow_nonce'] )
			&& wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['hd_workflow_nonce'] ) ), 'hd_save_workflow_' . $id )
		) {
			if ( ! current_user_can( Plugin::CAP_VIEW_ENTRIES ) ) {
				wp_die( esc_html__( 'Sorry, you are not allowed to edit this entry.', 'hd-form' ) );
			}

			$newSlug      = sanitize_key( wp_unslash( $_POST['workflow_status'] ?? '' ) );
			$workflowNote = sanitize_textarea_field( wp_unslash( $_POST['workflow_note'] ?? '' ) );
			$currentSlug  = (string) ( $entry['workflow_status'] ?? '' );

			if ( '' !== $newSlug && null === FormConfig::getWorkflowStatusBySlug( $newSlug ) ) {
				$newSlug = $currentSlug;
			}

			if ( $newSlug !== $currentSlug || '' !== $workflowNote ) {
				if ( $newSlug !== $currentSlug ) {
					$repo->updateWorkflowStatus( $id, $newSlug );
					$entry['workflow_status'] = $newSlug;
				}

				( new WorkflowHistoryRepository() )->insert(
					$id,
					$newSlug,
					$workflowNote,
					get_current_user_id()
				);
			}

			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Workflow status updated.', 'hd-form' ) . '</p></div>';
		}

		if ( 'new' === $entry['status'] ) {
			$repo->updateStatus( $id, 'read' );
			$entry['status'] = 'read';

			EntryCountCache::flush();
		}

		echo '<div class="wrap hd-form-entries">';
		echo '<h1 class="wp-heading-inline">' . esc_html__( 'Entry Details', 'hd-form' ) . '</h1>';
		echo '<a href="' . esc_url( admin_url( 'admin.php?page=hd-form-entries' ) ) . '" class="page-title-action">' . esc_html__( 'Back', 'hd-form' ) . '</a>';
		echo '<hr class="wp-header-end">';

		echo '<div id="poststuff">';
		echo '<div id="post-body" class="metabox-holder columns-2">';

		// -- Form Data & Workflow history (main content area).
		echo '<div id="post-body-content">';

		// 1. Form Data Postbox
		echo '<div class="postbox">';
		echo '<h2 class="hndle"><span>' . esc_html__( 'Form Data', 'hd-form' ) . '</span></h2>';
		echo '<div class="inside">';
		echo '<table class="form-table">';

		// Core fields.
		$coreFields = [
			__( 'Name', 'hd-form' )  => $entry['name'],
			__( 'Email', 'hd-form' ) => $entry['email'],
			__( 'Phone', 'hd-form' ) => $entry['phone'],
		];

		// Extra fields with __labels mapping.
		$data   = $entry['data'] ?? [];
		$labels = $data['__labels'] ?? [];
		unset( $data['__labels'], $data['__files'], $data['__geo'] );

		$allFields = $coreFields;
		foreach ( $data as $key => $value ) {
			$label               = $labels[ $key ] ?? ucfirst( str_replace( [ '_', '-' ], ' ', $key ) );
			$allFields[ $label ] = $value;
		}

		foreach ( $allFields as $label => $value ) {
			if ( empty( $value ) ) {
				continue;
			}
			echo '<tr>';
			echo '<th scope="row">' . esc_html( $label ) . '</th>';
			echo '<td>' . self::renderFieldValue( $value ) . '</td>';
			echo '</tr>';
		}

		// File attachments.
		$files = $entry['data']['__files'] ?? [];
		if ( ! empty( $files ) ) {
			$attachmentLinks = [];
			foreach ( $files as $fileName => $fileUrl ) {
				if ( ! self::isUploadAttachmentUrl( (string) $fileUrl ) ) {
					continue;
				}

				$attachmentLinks[] = '<a href="' . esc_url( (string) $fileUrl ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( (string) $fileName ) . '</a>';
			}

			if ( ! empty( $attachmentLinks ) ) {
				echo '<tr>';
				echo '<th scope="row">' . esc_html__( 'Attachments', 'hd-form' ) . '</th>';
				echo '<td>' . implode( '<br>', $attachmentLinks ) . '</td>';
				echo '</tr>';
			}
		}

		echo '</table>';
		echo '</div></div>';

		// 2. Workflow History Timeline Postbox
		if ( FormConfig::hasWorkflowStatuses() ) {
			$historyRows = ( new WorkflowHistoryRepository() )->findByEntryId( $id );
			if ( ! empty( $historyRows ) ) {
				echo '<div class="postbox">';
				echo '<h2 class="hndle"><span>' . esc_html__( 'Workflow History', 'hd-form' ) . '</span></h2>';
				echo '<div class="inside">';
				echo '<ol class="hd-workflow-timeline">';

				foreach ( $historyRows as $row ) {
					$rowSlug   = (string) ( $row['workflow_status'] ?? '' );
					$rowConfig = FormConfig::getWorkflowStatusBySlug( $rowSlug );
					$rowLabel  = $rowConfig['label'] ?? ( '' !== $rowSlug ? $rowSlug : __( 'None', 'hd-form' ) );
					$rowColor  = $rowConfig['color'] ?? '#a7aaad';
					$isOrphan  = '' !== $rowSlug && null === $rowConfig;

					$userData    = get_userdata( (int) ( $row['user_id'] ?? 0 ) );
					$displayName = $userData ? esc_html( $userData->display_name ) : esc_html__( 'System', 'hd-form' );
					$datetime    = esc_html( Helper::formatDate( $row['created_at'] ?? '' ) );
					$noteText    = esc_html( $row['note'] ?? '' );

					echo '<li class="timeline-item' . ( $isOrphan ? ' timeline-item--orphan' : '' ) . '">';
					echo '<span class="timeline-dot" style="background:' . esc_attr( $rowColor ) . ';"></span>';
					echo '<div class="timeline-content">';
					echo '<strong class="timeline-status">' . esc_html( $rowLabel ) . '</strong>';
					if ( '' !== $noteText ) {
						echo '<p class="timeline-note">' . nl2br( $noteText ) . '</p>';
					}
					echo '<p class="timeline-meta">' . $displayName . ' &mdash; ' . $datetime . '</p>';
					echo '</div>';
					echo '</li>';
				}

				echo '</ol>';
				echo '</div></div>';
			}
		}

		echo '</div>';
		// ↑ closes #post-body-content

		// -- Metadata sidebar.
		echo '<div id="postbox-container-1" class="postbox-container">';

		// Workflow Status Update Box in Sidebar
		if ( FormConfig::hasWorkflowStatuses() ) {
			$currentWorkflowSlug = (string) ( $entry['workflow_status'] ?? '' );
			echo '<div class="postbox">';
			echo '<h2 class="hndle"><span>' . esc_html__( 'Workflow Status', 'hd-form' ) . '</span></h2>';
			echo '<div class="inside">';
			echo '<form method="post">';
			wp_nonce_field( 'hd_save_workflow_' . $id, 'hd_workflow_nonce' );
			echo '<p><select name="workflow_status" class="widefat">';
			echo '<option value=""' . selected( $currentWorkflowSlug, '', false ) . '>' . esc_html__( '— None —', 'hd-form' ) . '</option>';
			foreach ( FormConfig::getWorkflowStatuses() as $wfStatus ) {
				$wfSlug  = $wfStatus['slug'] ?? '';
				$wfLabel = $wfStatus['label'] ?? $wfSlug;
				$wfColor = $wfStatus['color'] ?? '#a7aaad';
				if ( '' === $wfSlug ) {
					continue;
				}
				printf(
					'<option value="%1$s" data-color="%2$s"%3$s>%4$s</option>',
					esc_attr( $wfSlug ),
					esc_attr( $wfColor ),
					selected( $currentWorkflowSlug, $wfSlug, false ),
					esc_html( $wfLabel )
				);
			}
			echo '</select></p>';
			echo '<p class="description">' . esc_html__( 'Add a note (optional):', 'hd-form' ) . '</p>';
			echo '<p><textarea name="workflow_note" rows="3" class="widefat" placeholder="' . esc_attr__( 'Notes for this status change…', 'hd-form' ) . '"></textarea></p>';
			echo '<p><button type="submit" class="button button-secondary">' . esc_html__( 'Save Workflow', 'hd-form' ) . '</button></p>';
			echo '</form>';
			echo '</div></div>';
		}

		echo '<div class="postbox">';
		echo '<h2 class="hndle"><span>' . esc_html__( 'Metadata', 'hd-form' ) . '</span></h2>';
		echo '<div class="inside">';
		echo '<p><strong>' . esc_html__( 'ID', 'hd-form' ) . ':</strong> ' . esc_html( (string) $entry['id'] ) . '</p>';
		echo '<p><strong>' . esc_html__( 'Form Type', 'hd-form' ) . ':</strong> ' . esc_html( $entry['form_type'] ) . '</p>';

		$statusLabel = match ( $entry['status'] ) {
			'new'     => __( 'New', 'hd-form' ),
			'read'    => __( 'Read', 'hd-form' ),
			'spam'    => __( 'Spam', 'hd-form' ),
			'starred' => __( 'Starred', 'hd-form' ),
			default   => ucfirst( $entry['status'] ),
		};
		echo '<p><strong>' . esc_html__( 'Status', 'hd-form' ) . ':</strong> <span class="hd-entry-status hd-entry-status--' . esc_attr( $entry['status'] ) . '">' . esc_html( $statusLabel ) . '</span></p>';
		echo '<p><strong>' . esc_html__( 'Date', 'hd-form' ) . ':</strong> ' . esc_html( Helper::formatDate( $entry['created_at'] ) ) . '</p>';
		echo '<p><strong>' . esc_html__( 'IP', 'hd-form' ) . ':</strong> ' . esc_html( $entry['ip_address'] ) . '</p>';
		echo '<hr class="hd-meta-separator" />';
		echo '<p><strong>' . esc_html__( 'Source', 'hd-form' ) . ':</strong> ' . esc_html( $entry['utm_source'] ?: '-' ) . '</p>';
		echo '<p><strong>' . esc_html__( 'Medium', 'hd-form' ) . ':</strong> ' . esc_html( $entry['utm_medium'] ?: '-' ) . '</p>';
		echo '<p><strong>' . esc_html__( 'Campaign', 'hd-form' ) . ':</strong> ' . esc_html( $entry['utm_campaign'] ?: '-' ) . '</p>';
		echo '<p><strong>' . esc_html__( 'Referer', 'hd-form' ) . ':</strong> ' . ( $entry['referer_url'] ? '<a href="' . esc_url( $entry['referer_url'] ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Link', 'hd-form' ) . '</a>' : '-' ) . '</p>';
		echo '</div></div>';
		// ↑ closes .inside + .postbox (Metadata)

		// -- Admin Notes postbox.
		echo '<div class="postbox">';
		echo '<h2 class="hndle"><span>' . esc_html__( 'Admin Notes', 'hd-form' ) . '</span></h2>';
		echo '<div class="inside">';
		echo '<form method="post">';
		wp_nonce_field( 'hd_save_entry_notes_' . $id, 'hd_entry_notes_nonce' );
		echo '<textarea name="entry_notes" rows="5" style="width:100%;">' . esc_textarea( $entry['notes'] ?? '' ) . '</textarea>';
		echo '<p><button type="submit" class="button button-primary">' . esc_html__( 'Save Notes', 'hd-form' ) . '</button></p>';
		echo '</form>';
		echo '</div></div>';
		// ↑ closes .inside + .postbox (Notes)

		echo '</div>';
		// ↑ closes #postbox-container-1

		echo '</div></div></div>';
		// ↑ closes #post-body + #poststuff + .wrap
	}

	/**
	 * Render a field value for admin display.
	 */
	private static function renderFieldValue( mixed $value ): string {
		if ( is_array( $value ) ) {
			$value = implode(
				"\n",
				array_map(
					static fn( mixed $item ): string => is_scalar( $item ) || null === $item ? (string) $item : ( wp_json_encode( $item ) ?: '' ),
					$value
				)
			);
		}

		if ( ! is_scalar( $value ) && null !== $value ) {
			$value = wp_json_encode( $value ) ?: '';
		}

		return nl2br( esc_html( (string) $value ) );
	}

	/**
	 * Only display attachment links that point to the WordPress uploads base URL.
	 */
	private static function isUploadAttachmentUrl( string $url ): bool {
		$uploads = wp_upload_dir();
		$baseUrl = isset( $uploads['baseurl'] ) ? rtrim( str_replace( '\\', '/', (string) $uploads['baseurl'] ), '/' ) . '/' : '';
		$url     = str_replace( '\\', '/', esc_url_raw( $url ) );
		$path    = wp_parse_url( $url, PHP_URL_PATH );

		return '' !== $baseUrl
			&& ! ( is_string( $path ) && preg_match( '#(?:^|/)\.\.(?:/|$)#', $path ) )
			&& str_starts_with( $url, $baseUrl );
	}
}
