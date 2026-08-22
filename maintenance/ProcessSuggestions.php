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

		$rows = $store->getPendingBatch( $limit );
		if ( !$rows ) {
			$this->output( "No pending suggestions.\n" );
			return;
		}

		$payload = SuggestionBatch::buildPayload( $rows );
		$ids = array_map( static function ( $row ) {
			return (int)$row->sg_id;
		}, $rows );

		if ( $dryRun ) {
			$this->output( json_encode(
				$payload,
				JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
			) . "\n" );
			$this->output( sprintf(
				"Dry run: %d suggestion(s) would be posted; nothing marked exported.\n",
				count( $ids )
			) );
			return;
		}

		$this->output( sprintf( "Posting %d suggestion(s) to %s\n",
			count( $ids ), SuggestionBatch::redactUrl( $webhook ) ) );

		$status = $this->post( $webhook, $payload, $config );
		if ( !$status ) {
			// Leave the rows unmarked so the next run retries them.
			$this->fatalError( 'Webhook POST failed; no rows marked exported.' );
		}

		$marked = $store->markBatchProcessed( $ids );
		$this->output( "Posted and marked $marked suggestion(s) as exported.\n" );
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
