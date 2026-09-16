<?php

namespace MediaWiki\Extension\SaintapediaSuggest\Maintenance;

use Maintenance;
use MediaWiki\Extension\SaintapediaSuggest\SuggestionBatch;
use MediaWiki\MediaWikiServices;

// @codeCoverageIgnoreStart
$IP = getenv( 'MW_INSTALL_PATH' );
if ( $IP === false ) {
	$IP = dirname( __DIR__, 3 );
}
require_once "$IP/maintenance/Maintenance.php";
// @codeCoverageIgnoreEnd

/**
 * Post a batch of pending field suggestions to an external endpoint for
 * offline triage (an LLM classifier, a ticket queue, a spreadsheet job).
 *
 * Read-only with respect to the suggestions themselves: this never changes a
 * status and never touches Cargo. It only marks rows as exported so the next
 * run does not resend them.
 *
 *   php maintenance/run.php \
 *       extensions/SaintapediaSuggest/maintenance/ProcessSuggestions.php
 *
 *   # Canasta:
 *   canasta maintenance exec -i <instance> -- php maintenance/run.php \
 *       extensions/SaintapediaSuggest/maintenance/ProcessSuggestions.php --dry-run
 */
class ProcessSuggestions extends Maintenance {

	public function __construct() {
		parent::__construct();
		$this->addDescription(
			'Post pending Cargo field suggestions to the configured batch webhook.'
		);
		$this->addOption( 'webhook', 'Override $wgSaintapediaSuggestWebhook.', false, true );
		$this->addOption(
			'limit',
			'Rows to post this run (default $wgSaintapediaSuggestBatchSize, max '
				. SuggestionBatch::MAX_BATCH_SIZE . ').',
			false,
			true
		);
		$this->addOption(
			'dry-run',
			'Build and print the payload without posting it or marking rows exported.'
		);
		$this->requireExtension( 'SaintapediaSuggest' );
	}

	public function execute() {
		$services = MediaWikiServices::getInstance();
		$config = $services->getMainConfig();
		$store = $services->getService( 'SaintapediaSuggest.SuggestionStore' );

		$dryRun = $this->hasOption( 'dry-run' );

		$webhook = (string)( $this->getOption( 'webhook' )
			?: $config->get( 'SaintapediaSuggestWebhook' ) );
		if ( !$dryRun && !SuggestionBatch::isValidWebhook( $webhook ) ) {
			$this->fatalError(
				"No valid HTTPS webhook configured.\n"
				. "Set \$wgSaintapediaSuggestWebhook, pass --webhook, or use --dry-run."
			);
		}

		$limit = SuggestionBatch::clampBatchSize(
			$this->getOption( 'limit' ) !== null
				? (int)$this->getOption( 'limit' )
				: (int)$config->get( 'SaintapediaSuggestBatchSize' )
		);

		// A dry run never posts or marks anything, so there's nothing for a
		// concurrent real run to race -- skip the lock entirely rather than
		// have a --dry-run invocation block on, or be blocked by, one.
		if ( $dryRun ) {
			$rows = $store->getPendingBatch( $limit );
			if ( !$rows ) {
				$this->output( "No pending suggestions.\n" );
				return;
			}
			$this->output( json_encode(
				SuggestionBatch::buildPayload( $rows ),
				JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
			) . "\n" );
			$this->output( sprintf(
				"Dry run: %d suggestion(s) would be posted; nothing marked exported.\n",
				count( $rows )
			) );
			return;
		}

		// Held across the whole select -> POST -> mark sequence: two
		// overlapping runs (or a slow one still in flight when the next is
		// scheduled) must not both select the same pending rows and repost
		// them. getPendingBatch() also reads DB_PRIMARY under this lock, so
		// a run started right after another's markBatchProcessed() commit
		// cannot see a lagged replica still showing those rows as pending.
		if ( !$store->acquireBatchLock() ) {
			$this->fatalError( 'Another ProcessSuggestions run holds the batch lock; not posting.' );
		}
		try {
			$rows = $store->getPendingBatch( $limit );
			if ( !$rows ) {
				$this->output( "No pending suggestions.\n" );
				return;
			}

			$payload = SuggestionBatch::buildPayload( $rows );
			$ids = array_map( static function ( $row ) {
				return (int)$row->sg_id;
			}, $rows );

			$this->output( sprintf( "Posting %d suggestion(s) to %s\n",
				count( $ids ), SuggestionBatch::redactUrl( $webhook ) ) );

			$status = $this->post( $webhook, $payload, $config );
			if ( !$status ) {
				// Leave the rows unmarked so the next run retries them. The
				// lock still releases (finally, below) once this exits --
				// fatalError() calls exit(), which drops the DB connection
				// and releases the MySQL-side GET_LOCK regardless, but the
				// explicit release in the finally block runs first.
				$this->fatalError( 'Webhook POST failed; no rows marked exported.' );
			}

			$marked = $store->markBatchProcessed( $ids );
			$this->output( "Posted and marked $marked suggestion(s) as exported.\n" );
		} finally {
			$store->releaseBatchLock();
		}
	}

	/**
	 * @param array<string,mixed> $payload
	 * @param \Config $config
	 */
	private function post( string $url, array $payload, $config ): bool {
		$body = json_encode( $payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		if ( $body === false ) {
			$this->error( 'Could not encode payload as JSON.' );
			return false;
		}

		$headers = [ 'Content-Type' => 'application/json' ];

		// The token lives in LocalSettings / env, never on a wiki page.
		$token = (string)$config->get( 'SaintapediaSuggestWebhookToken' );
		if ( $token !== '' ) {
			$headers['Authorization'] = 'Bearer ' . $token;
		}

		$request = MediaWikiServices::getInstance()->getHttpRequestFactory()->create(
			$url,
			[
				'method'  => 'POST',
				'timeout' => 30,
				'postData' => $body,
			],
			__METHOD__
		);
		foreach ( $headers as $name => $value ) {
			$request->setHeader( $name, $value );
		}

		$status = $request->execute();
		if ( !$status->isOK() ) {
			$this->error( 'HTTP error: ' . $status->__toString() );
			return false;
		}

		$code = $request->getStatus();
		if ( $code < 200 || $code >= 300 ) {
			$this->error( "Webhook returned HTTP $code." );
			return false;
		}
		return true;
	}
}

// @codeCoverageIgnoreStart
$maintClass = ProcessSuggestions::class;
require_once RUN_MAINTENANCE_IF_MAIN;
// @codeCoverageIgnoreEnd
